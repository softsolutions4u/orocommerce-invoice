<?php

/**
 * This file is part of the SoftSolutions4U OroCommerce Invoice bundle.
 *
 * @category  SoftSolutions4U
 * @package   SoftSolutions4U\Bundle\InvoiceBundle
 * @author    Pradeep Elayaraja
 * @author    Ganesh
 * @copyright 2026 SoftSolutions4U
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-only
 * @link      https://www.softsolutions4u.com/
 */

declare(strict_types=1);

namespace SoftSolutions4U\Bundle\InvoiceBundle\Generator;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;

/**
 * Generates the reference a customer quotes when paying an invoice by bank transfer.
 *
 * The reference is kept separate from the invoice number so bank transfers can
 * be matched reliably:
 *
 *   BT-0000020-38          BT-1234567890-92
 *   │  │       └─ check digits (ISO 7064 MOD 97-10, as used by IBAN)
 *   │  └───────── invoice id, zero-padded to at least 7 digits
 *   └──────────── prefix marking it as a bank transfer reference
 *
 * - Customers and banks often truncate or mistype free text; the check digits
 *   catch a mistyped or transposed digit before a payment is matched to the
 *   wrong invoice.
 * - Hyphens and digits only: survives every bank's reference field, unlike
 *   invoice numbers that may be renumbered or contain characters banks strip.
 * - Derived from the invoice id, so it is stable, unique per invoice and needs
 *   no database column; parseInvoiceId() turns it back into the invoice id.
 *
 * Length: ids are padded to a minimum of 7 digits, so references stay a
 * uniform width for the first ten million invoices and every reference issued
 * so far is unchanged. Larger ids simply use more digits - nothing is ever
 * truncated - up to 18 digits, the most that fits safely in a PHP integer.
 * The check digits are always exactly 2, which keeps the reference unambiguous
 * even when a customer leaves out the hyphens.
 */
class BankTransferReferenceGenerator
{
    public const PREFIX = 'BT';

    /** Ids are zero-padded to at least this many digits. */
    public const MIN_ID_LENGTH = 7;

    /**
     * Longest id accepted. 18 digits (up to 999,999,999,999,999,999) always
     * fits in a 64-bit PHP integer; 19 digits could overflow PHP_INT_MAX.
     */
    public const MAX_ID_LENGTH = 18;

    /**
     * Returns the bank transfer reference, or null for an invoice that is not saved yet.
     *
     * @param Invoice $invoice
     * @return string|null
     */
    public function generate(Invoice $invoice): ?string
    {
        $id = $invoice->getId();

        if (null === $id || $id <= 0) {
            return null;
        }

        return $this->generateForId($id);
    }

    /**
     * Returns the bank transfer reference for an invoice id.
     *
     * @param int $invoiceId
     * @return string
     */
    public function generateForId(int $invoiceId): string
    {
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('An invoice id must be a positive integer.');
        }

        $digits = (string) $invoiceId;

        if (strlen($digits) > self::MAX_ID_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'An invoice id can have at most %d digits to form a bank transfer reference.',
                self::MAX_ID_LENGTH
            ));
        }

        return sprintf(
            '%s-%s-%02d',
            self::PREFIX,
            str_pad($digits, self::MIN_ID_LENGTH, '0', STR_PAD_LEFT),
            98 - $this->mod97($digits . '00')
        );
    }

    /**
     * Whether the text is a well-formed reference with correct check digits.
     *
     * @param string $reference
     * @return bool
     */
    public function isValid(string $reference): bool
    {
        return null !== $this->parseInvoiceId($reference);
    }

    /**
     * Returns the invoice id a reference points to, or null if it is malformed or mistyped.
     *
     * Case, surrounding spaces and the separators customers tend to use instead
     * of hyphens (spaces, slashes, dots) are tolerated, as is leaving out the
     * leading zeros. Ids of any length up to MAX_ID_LENGTH are accepted.
     *
     * @param string $reference
     * @return int|null
     */
    public function parseInvoiceId(string $reference): ?int
    {
        $normalized = strtoupper((string) preg_replace('/[\s\/._]+/', '-', trim($reference)));

        // The id is every digit except the last two, which are always the check digits.
        if (!preg_match('/^' . self::PREFIX . '-?(\d+)-?(\d{2})$/', $normalized, $match)) {
            return null;
        }

        $digits = ltrim($match[1], '0');

        if ('' === $digits || strlen($digits) > self::MAX_ID_LENGTH) {
            return null;
        }

        if (1 !== $this->mod97($digits . $match[2])) {
            return null;
        }

        return (int) $digits;
    }

    /**
     * Remainder of a decimal string modulo 97.
     *
     * Works digit by digit, so it never overflows however long the number is.
     *
     * @param string $digits
     * @return int
     */
    private function mod97(string $digits): int
    {
        $remainder = 0;

        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }
}
