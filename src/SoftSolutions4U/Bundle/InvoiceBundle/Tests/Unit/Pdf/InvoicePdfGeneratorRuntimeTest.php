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

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\AttachmentBundle\Manager\FileManager;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Pdf\InvoicePdfGenerator;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaymentDetailsProvider;
use Twig\Environment;

/**
 * Tests the runtime path of generate() when Dompdf IS available.
 * Appended as a separate class to keep the primary test file unchanged.
 */
class InvoicePdfGeneratorRuntimeTest extends TestCase
{
    public function testGenerateProducesAPdfStringWhenDompdfIsInstalled(): void
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('dompdf is not installed.');
        }

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html><body><h1>Invoice</h1></body></html>');

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturn(null);

        $fileManager = $this->createMock(FileManager::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $paymentDetails = $this->createMock(InvoicePaymentDetailsProvider::class);
        $paymentDetails->method('getPaymentDetails')->willReturn(null);

        $generator = new InvoicePdfGenerator(
            $twig,
            $configManager,
            $fileManager,
            $registry,
            $paymentDetails
        );

        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setCurrency('USD');

        $pdf = $generator->generate($invoice);

        self::assertStringStartsWith('%PDF-', $pdf, 'generate() must return a valid PDF binary string');
    }
}
