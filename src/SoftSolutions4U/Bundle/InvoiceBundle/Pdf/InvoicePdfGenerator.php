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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Pdf;

use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\Configuration;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaymentDetailsProvider;
use Doctrine\Persistence\ManagerRegistry;
use Dompdf\Dompdf;
use Dompdf\Options;
use Oro\Bundle\AttachmentBundle\Entity\File;
use Oro\Bundle\AttachmentBundle\Manager\FileManager;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Twig\Environment;

/**
 * Renders an invoice to a PDF document.
 */
class InvoicePdfGenerator implements InvoicePdfGeneratorInterface
{
    public const TEMPLATE = '@SoftSolutions4UInvoice/Pdf/invoice.html.twig';

    /** @var InvoicePaymentDetailsProvider $paymentDetailsProvider */
    private InvoicePaymentDetailsProvider $paymentDetailsProvider;

    /**
     * Creates a new InvoicePdfGenerator instance.
     *
     * @param Environment $twig
     * @param ConfigManager $configManager
     * @param FileManager $fileManager
     * @param ManagerRegistry $doctrine
     * @param InvoicePaymentDetailsProvider $paymentDetailsProvider
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly ConfigManager $configManager,
        private readonly FileManager $fileManager,
        private readonly ManagerRegistry $doctrine,
        InvoicePaymentDetailsProvider $paymentDetailsProvider
    ) {
        $this->paymentDetailsProvider = $paymentDetailsProvider;
    }

    /**
     * Renders the invoice and returns the PDF as a binary string.
     *
     * @param Invoice $invoice
     * @return string
     */
    public function generate(Invoice $invoice): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException(
                'The "dompdf/dompdf" package is required to generate invoice PDFs. '
                . 'Run "composer require dompdf/dompdf" and clear the cache.'
            );
        }

        $html = $this->renderHtml($invoice);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultPaperSize', 'A4');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Renders the invoice to the HTML that is fed to the PDF engine.
     *
     * @param Invoice $invoice
     * @return string
     */
    public function renderHtml(Invoice $invoice): string
    {
        return $this->twig->render(self::TEMPLATE, $this->buildTemplateContext($invoice));
    }

    /**
     * Builds the variables available to the invoice PDF template.
     *
     * @param Invoice $invoice
     * @return array<string, mixed>
     */
    public function buildTemplateContext(Invoice $invoice): array
    {
        return [
            'entity' => $invoice,
            'companyName' => (string) $this->getConfig(Configuration::COMPANY_NAME),
            'companyVatNumber' => $this->resolveCompanyVatNumber(),
            'companyLogoDataUri' => $this->resolveCompanyLogoDataUri(),
            'currency' => $this->resolveCurrency($invoice),
            'payableBy' => $this->resolvePayableBy($invoice),
            'paymentDetails' => $this->paymentDetailsProvider->getPaymentDetails($invoice),
        ];
    }

    /**
     * Returns the seller's VAT registration number, or null when none is configured.
     *
     * @return string|null
     */
    private function resolveCompanyVatNumber(): ?string
    {
        $vatNumber = trim((string) $this->getConfig(Configuration::COMPANY_VAT_NUMBER));

        return '' === $vatNumber ? null : $vatNumber;
    }

    /**
     * Returns the lines describing who the invoice is payable by, or null when unknown.
     *
     * First line is the paying company (the Oro customer, falling back to the
     * billing organisation), then the contact person and their email. Blank
     * and repeated values are dropped so a sole trader is not printed twice.
     *
     * @param Invoice $invoice
     * @return array<int, string>|null
     */
    private function resolvePayableBy(Invoice $invoice): ?array
    {
        $customer = $invoice->getCustomer();
        $customerUser = $invoice->getCustomerUser();

        $company = $customer?->getName() ?: $invoice->getBillingAddressOrganization();

        $contact = $customerUser
            ? trim(sprintf('%s %s', (string) $customerUser->getFirstName(), (string) $customerUser->getLastName()))
            : '';
        if ('' === $contact) {
            $contact = (string) $invoice->getBillingAddressFullName();
        }

        $lines = [];
        foreach ([$company, $contact, $customerUser?->getEmail()] as $line) {
            $line = trim((string) $line);

            if ('' !== $line && !in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }

        return $lines ?: null;
    }

    /**
     * Returns the invoice currency as an upper-case ISO code, or null when unset.
     *
     * @param Invoice $invoice
     * @return string|null
     */
    private function resolveCurrency(Invoice $invoice): ?string
    {
        $currency = strtoupper(trim((string) $invoice->getCurrency()));

        return '' === $currency ? null : $currency;
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

    /**
     * Returns the configured company logo as a data URI, or null if unavailable.
     *
     * @return string|null
     */
    private function resolveCompanyLogoDataUri(): ?string
    {
        $logoId = $this->getConfig(Configuration::COMPANY_LOGO);

        if (!$logoId) {
            return null;
        }

        $logo = $this->doctrine->getRepository(File::class)->find($logoId);

        if (!$logo instanceof File || !$logo->getFilename()) {
            return null;
        }

        try {
            $content = $this->fileManager->getContent($logo);
        } catch (\Throwable) {
            return null;
        }

        if ($content === null || $content === '') {
            return null;
        }

        $mimeType = $logo->getMimeType() ?: 'image/png';

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($content));
    }
}
