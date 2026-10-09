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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Pdf;

use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Pdf\InvoicePdfResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Unit tests for InvoicePdfResponse.
 */
class InvoicePdfResponseTest extends TestCase
{
    /**
     * Tests the content, type, download file name and no-cache headers.
     */
    public function testCreatesANonCacheablePdfDownload(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');

        $response = InvoicePdfResponse::create($invoice, '%PDF', HeaderUtils::DISPOSITION_ATTACHMENT);

        self::assertSame('%PDF', $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame(
            'attachment; filename=invoice-INV-2026-09-00019.pdf',
            $response->headers->get('Content-Disposition')
        );
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
    }

    /**
     * Tests that the preview is sent inline.
     */
    public function testInlinePreview(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-1');

        $response = InvoicePdfResponse::create($invoice, '%PDF', HeaderUtils::DISPOSITION_INLINE);

        self::assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * Tests that slashes and non-ASCII characters in the invoice number cannot break the header.
     */
    public function testUnusualInvoiceNumbersAreMadeSafe(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('RÉ/2026\\7');

        $disposition = (string) InvoicePdfResponse::create($invoice, '%PDF', HeaderUtils::DISPOSITION_ATTACHMENT)
            ->headers->get('Content-Disposition');

        self::assertStringContainsString('filename=invoice-R_-2026-7.pdf', $disposition);
        self::assertStringContainsString("filename*=utf-8''invoice-R%C3%89-2026-7.pdf", $disposition);
    }
}
