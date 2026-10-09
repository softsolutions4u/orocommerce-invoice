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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Provider;

use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\Configuration;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Generator\BankTransferReferenceGenerator;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\Money;

/**
 * Supplies the payment instructions printed on the invoice PDF.
 *
 * US and German transfers identify an account differently - routing number plus
 * account number against IBAN plus BIC - so each is configured separately and
 * only the fields that belong to the chosen account are returned.
 *
 * The account is picked from the invoice currency rather than the locale: a
 * customer reading German still has to pay a USD invoice into the USD account.
 * Locale only decides which language the instructions are written in.
 *
 * Bank transfer details are returned for every invoice, paid or not, so the
 * customer always has the account and reference on file. What depends on the
 * balance is the call to pay: `payable` is false for an invoice with nothing
 * left to pay - paid in full (including orders paid at checkout), overpaid or
 * cancelled - and then `amountDue` and `onlineUrl` are null, so its PDF shows
 * no amount due and no Pay Online link.
 */
class InvoicePaymentDetailsProvider
{
    private const REGION_US = 'us';
    private const REGION_DE = 'de';

    /** @var ConfigManager $configManager */
    private ConfigManager $configManager;

    /** @var UrlGeneratorInterface $urlGenerator */
    private UrlGeneratorInterface $urlGenerator;

    /** @var BankTransferReferenceGenerator $referenceGenerator */
    private BankTransferReferenceGenerator $referenceGenerator;

    /**
     * Creates a new InvoicePaymentDetailsProvider instance.
     *
     * @param ConfigManager $configManager
     * @param UrlGeneratorInterface $urlGenerator
     * @param BankTransferReferenceGenerator|null $referenceGenerator
     */
    public function __construct(
        ConfigManager $configManager,
        UrlGeneratorInterface $urlGenerator,
        ?BankTransferReferenceGenerator $referenceGenerator = null
    ) {
        $this->configManager = $configManager;
        $this->urlGenerator = $urlGenerator;
        $this->referenceGenerator = $referenceGenerator ?? new BankTransferReferenceGenerator();
    }

    /**
     * Returns the payment details for an invoice, or null when none apply.
     *
     * @param Invoice $invoice
     * @return array<string, mixed>|null
     */
    public function getPaymentDetails(Invoice $invoice): ?array
    {
        if (!$this->getConfig(Configuration::BANK_DETAILS_ENABLED)) {
            return null;
        }

        $region = $this->resolveRegion($invoice);

        if (null === $region) {
            return null;
        }

        $details = self::REGION_DE === $region
            ? $this->getGermanAccount()
            : $this->getUnitedStatesAccount();

        if (!$details['fields']) {
            return null;
        }

        // The bank transfer has its own reference, separate from the invoice
        // number; fall back to the invoice number only for an unsaved invoice.
        $details['reference'] = $this->referenceGenerator->generate($invoice) ?? (string) $invoice->getInvoiceNo();
        $details['invoiceNo'] = (string) $invoice->getInvoiceNo();
        $details['currency'] = (string) $invoice->getCurrency();

        $payable = $this->isPayable($invoice);
        $details['payable'] = $payable;
        $details['amountDue'] = $payable ? Money::round($invoice->getBalance()) : null;
        $details['onlineUrl'] = $payable ? $this->resolveOnlinePaymentUrl($invoice) : null;

        return $details;
    }

    /**
     * Whether the invoice still has money owing that the customer can pay.
     *
     * @param Invoice $invoice
     * @return bool
     */
    private function isPayable(Invoice $invoice): bool
    {
        if (in_array($invoice->getStatus(), [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED], true)) {
            return false;
        }

        return Money::round($invoice->getBalance()) > 0.0;
    }

    /**
     * Returns the storefront address where the customer can pay this invoice.
     *
     * Built from Oro's configured Application URL rather than the router's
     * absolute host, because a PDF generated from a background job has no
     * request and would otherwise be given localhost.
     *
     * Uses the create-payment route, which takes an invoice id, builds the
     * InvoicePayment and forwards to /invoice/payment/{id}. The payment route
     * itself cannot be linked directly - its id belongs to a record that does
     * not exist until the customer starts paying.
     *
     * @param Invoice $invoice
     * @return string|null
     */
    private function resolveOnlinePaymentUrl(Invoice $invoice): ?string
    {
        if (null === $invoice->getId()) {
            return null;
        }

        $applicationUrl = trim((string) $this->configManager->get('oro_ui.application_url'));

        if ('' === $applicationUrl) {
            return null;
        }

        try {
            $path = $this->urlGenerator->generate(
                'softsolutions4u_invoice_frontend_payment_create_for_invoice',
                ['id' => $invoice->getId()],
                UrlGeneratorInterface::ABSOLUTE_PATH
            );
        } catch (\Throwable $exception) {
            return null;
        }

        return rtrim($applicationUrl, '/') . $path;
    }

    /**
     * Returns which configured account an invoice should be paid into.
     *
     * @param Invoice $invoice
     * @return string|null
     */
    private function resolveRegion(Invoice $invoice): ?string
    {
        $currency = strtoupper((string) $invoice->getCurrency());
        $usEnabled = (bool) $this->getConfig(Configuration::BANK_US_ENABLED);
        $deEnabled = (bool) $this->getConfig(Configuration::BANK_DE_ENABLED);

        if ('EUR' === $currency && $deEnabled) {
            return self::REGION_DE;
        }

        if ('USD' === $currency && $usEnabled) {
            return self::REGION_US;
        }

        // No account matches the currency: fall back to whichever is configured.
        if ($usEnabled) {
            return self::REGION_US;
        }

        return $deEnabled ? self::REGION_DE : null;
    }

    /**
     * Returns the US account, keyed by label so the template stays generic.
     *
     * @return array<string, mixed>
     */
    private function getUnitedStatesAccount(): array
    {
        $fields = array_filter([
            'softsolutions4u.invoice.bank.account_holder.label' => $this->getConfig(
                Configuration::BANK_US_ACCOUNT_HOLDER
            ),
            'softsolutions4u.invoice.bank.bank_name.label' => $this->getConfig(Configuration::BANK_US_BANK_NAME),
            'softsolutions4u.invoice.bank.routing_number.label' => $this->getConfig(
                Configuration::BANK_US_ROUTING_NUMBER
            ),
            'softsolutions4u.invoice.bank.account_number.label' => $this->getConfig(
                Configuration::BANK_US_ACCOUNT_NUMBER
            ),
            'softsolutions4u.invoice.bank.swift.label' => $this->getConfig(Configuration::BANK_US_SWIFT),
        ]);

        return [
            'region' => self::REGION_US,
            'fields' => $fields,
            'instructions' => (string) $this->getConfig(Configuration::BANK_US_INSTRUCTIONS),
        ];
    }

    /**
     * Returns the German account, keyed by label so the template stays generic.
     *
     * @return array<string, mixed>
     */
    private function getGermanAccount(): array
    {
        $fields = array_filter([
            'softsolutions4u.invoice.bank.account_holder.label' => $this->getConfig(
                Configuration::BANK_DE_ACCOUNT_HOLDER
            ),
            'softsolutions4u.invoice.bank.bank_name.label' => $this->getConfig(Configuration::BANK_DE_BANK_NAME),
            'softsolutions4u.invoice.bank.iban.label' => $this->getConfig(Configuration::BANK_DE_IBAN),
            'softsolutions4u.invoice.bank.bic.label' => $this->getConfig(Configuration::BANK_DE_BIC),
        ]);

        return [
            'region' => self::REGION_DE,
            'fields' => $fields,
            'instructions' => (string) $this->getConfig(Configuration::BANK_DE_INSTRUCTIONS),
        ];
    }

    /**
     * Reads one setting from this bundle's configuration scope.
     *
     * @param string $name
     * @return mixed
     */
    private function getConfig(string $name): mixed
    {
        return $this->configManager->get(Configuration::getConfigKeyByName($name));
    }
}
