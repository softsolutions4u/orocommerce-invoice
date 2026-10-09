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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Model;

/**
 * Payment status codes shared with Oro's payment status translations.
 */
final class InvoicePaymentStatus
{
    /** Oro: oro.payment.status.full -> "Paid in full" */
    public const FULL = 'full';

    /** Oro: oro.payment.status.partially -> "Partially paid" */
    public const PARTIALLY = 'partially';

    /** Oro: oro.payment.status.pending -> "Pending payment" */
    public const PENDING = 'pending';

    /** Oro codes this bundle never writes itself. */
    public const INVOICED = 'invoiced';
    public const AUTHORIZED = 'authorized';
    public const DECLINED = 'declined';

    /** Legacy payment_status values mapped onto Oro status codes. */
    private const LEGACY_MAP = [
        'partial' => self::PARTIALLY,
        'partially_paid' => self::PARTIALLY,
        'partly_paid' => self::PARTIALLY,
        'paid_in_full' => self::FULL,
        'paid' => self::FULL,
        'unpaid' => self::PENDING,
        'not_paid' => self::PENDING,
    ];

    private const KNOWN = [
        self::FULL,
        self::PARTIALLY,
        self::PENDING,
        self::INVOICED,
        self::AUTHORIZED,
        self::DECLINED,
    ];

    /** Money is stored as `money`. */
    private const SCALE = 2;

    /**
     * Creates a new InvoicePaymentStatus instance.
     */
    private function __construct()
    {
    }

    /**
     * Resolve the status implied by what has actually been paid.
     *
     * @param float $amountPaid
     * @param float $amountDue
     * @param string|null $currentStatus
     * @return string
     */
    public static function fromAmounts(
        float $amountPaid,
        float $amountDue,
        ?string $currentStatus = null
    ): string {
        $paid = round($amountPaid, self::SCALE);
        $due = round($amountDue, self::SCALE);

        if ($paid <= 0.0) {
            return self::normalize($currentStatus) ?? self::PENDING;
        }

        return $paid >= $due ? self::FULL : self::PARTIALLY;
    }

    /**
     * Translate a legacy/unknown code into an Oro code.
     *
     * @param string|null $status
     * @return string|null
     */
    public static function normalize(?string $status): ?string
    {
        if (null === $status || '' === trim($status)) {
            return null;
        }

        $status = strtolower(trim($status));

        if (isset(self::LEGACY_MAP[$status])) {
            return self::LEGACY_MAP[$status];
        }

        return in_array($status, self::KNOWN, true) ? $status : null;
    }

    /**
     * Returns the legacy map.
     *
     * @return array<string,string> legacy code => Oro code, for data migrations
     */
    public static function legacyMap(): array
    {
        return self::LEGACY_MAP;
    }
}
