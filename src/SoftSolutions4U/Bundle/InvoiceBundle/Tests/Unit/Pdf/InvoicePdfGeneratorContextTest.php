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
use Oro\Bundle\AttachmentBundle\Entity\File;
use Oro\Bundle\AttachmentBundle\Manager\FileManager;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\Configuration;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Pdf\InvoicePdfGenerator;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaymentDetailsProvider;
use Twig\Environment;

class InvoicePdfGeneratorContextTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config = [];

    private function generator(?File $logoFile = null, ?string $logoContent = null): InvoicePdfGenerator
    {
        $twig = $this->createMock(
            Environment::class
        );

        $configManager = $this->createMock(
            ConfigManager::class
        );
        $configManager->method('get')->willReturnCallback(
            fn (string $key) => $this->config[$key] ?? null
        );

        $fileManager = $this->createMock(
            FileManager::class
        );
        if ($logoContent !== null) {
            $fileManager->method('getContent')->willReturn($logoContent);
        } elseif ($logoFile === null) {
            $fileManager->method('getContent')->willReturn('');
        }

        $repository = $this->createMock(
            \Doctrine\Persistence\ObjectRepository::class
        );
        if ($logoFile !== null) {
            $repository->method('find')->willReturn($logoFile);
        } else {
            $repository->method('find')->willReturn(null);
        }

        $registry = $this->createMock(
            ManagerRegistry::class
        );
        $registry->method('getRepository')->willReturn($repository);

        $paymentDetails = $this->createMock(
            InvoicePaymentDetailsProvider::class
        );
        $paymentDetails->method('getPaymentDetails')->willReturn(null);

        return new InvoicePdfGenerator($twig, $configManager, $fileManager, $registry, $paymentDetails);
    }

    private function invoice(): Invoice
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setCurrency('USD');

        return $invoice;
    }

    public function testBuildTemplateContextIncludesCompanyNameAndCurrency(): void
    {
        $this->config = [
            Configuration::getConfigKeyByName(Configuration::COMPANY_NAME) => 'Acme Corp',
        ];

        $context = $this->generator()->buildTemplateContext($this->invoice());

        self::assertSame('Acme Corp', $context['companyName']);
        self::assertSame('USD', $context['currency']);
        self::assertNull($context['companyVatNumber']);
        self::assertNull($context['companyLogoDataUri']);
    }

    public function testBuildTemplateContextTrimsAndNullifiesEmptyVat(): void
    {
        $this->config = [
            Configuration::getConfigKeyByName(Configuration::COMPANY_VAT_NUMBER) => '   ',
        ];

        $context = $this->generator()->buildTemplateContext($this->invoice());

        self::assertNull($context['companyVatNumber']);
    }

    public function testBuildTemplateContextReturnsVatWhenConfigured(): void
    {
        $this->config = [
            Configuration::getConfigKeyByName(Configuration::COMPANY_VAT_NUMBER) => '  GB123456789  ',
        ];

        $context = $this->generator()->buildTemplateContext($this->invoice());

        self::assertSame('GB123456789', $context['companyVatNumber']);
    }

    public function testPayableByPrefersCustomerName(): void
    {
        $customer = new Customer();
        $customer->setName('Acme Ltd');

        $invoice = $this->invoice();
        $invoice->setCustomer($customer);
        $invoice->setBillingAddressOrganization('Fallback Org');

        $context = $this->generator()->buildTemplateContext($invoice);

        self::assertSame('Acme Ltd', $context['payableBy'][0]);
    }

    public function testPayableByFallsBackToBillingOrganization(): void
    {
        $invoice = $this->invoice();
        $invoice->setBillingAddressOrganization('Fallback Org');

        $context = $this->generator()->buildTemplateContext($invoice);

        self::assertSame('Fallback Org', $context['payableBy'][0]);
    }

    public function testPayableByIncludesContactFromCustomerUser(): void
    {
        $customerUser = new CustomerUser();
        $customerUser->setFirstName('Jane');
        $customerUser->setLastName('Doe');
        $customerUser->setEmail('jane@example.com');

        $invoice = $this->invoice();
        $invoice->setCustomerUser($customerUser);

        $context = $this->generator()->buildTemplateContext($invoice);

        self::assertContains('Jane Doe', $context['payableBy']);
        self::assertContains('jane@example.com', $context['payableBy']);
    }

    public function testPayableByFallsBackToBillingNameWhenCustomerUserHasNoName(): void
    {
        $customerUser = new CustomerUser();
        $customerUser->setEmail('contact@example.com');

        $invoice = $this->invoice();
        $invoice->setCustomerUser($customerUser);
        $invoice->setBillingAddressFirstName('John');
        $invoice->setBillingAddressLastName('Smith');

        $context = $this->generator()->buildTemplateContext($invoice);

        self::assertContains('John Smith', $context['payableBy']);
    }

    public function testPayableByIsNullWhenNothingIsKnown(): void
    {
        $invoice = $this->invoice();

        $context = $this->generator()->buildTemplateContext($invoice);

        self::assertNull($context['payableBy']);
    }

    public function testPayableByDropsRepeatedValues(): void
    {
        $customer = new Customer();
        $customer->setName('Same Name');

        $invoice = $this->invoice();
        $invoice->setCustomer($customer);
        $invoice->setBillingAddressOrganization('Same Name');

        $context = $this->generator()->buildTemplateContext($invoice);

        self::assertSame(['Same Name'], $context['payableBy']);
    }

    public function testLogoReturnsNullWhenNotConfigured(): void
    {
        $context = $this->generator()->buildTemplateContext($this->invoice());

        self::assertNull($context['companyLogoDataUri']);
    }

    public function testLogoReturnsNullWhenFileIsMissing(): void
    {
        $this->config = [
            Configuration::getConfigKeyByName(Configuration::COMPANY_LOGO) => 42,
        ];

        // No File returned from repository → null logo
        $context = $this->generator()->buildTemplateContext($this->invoice());

        self::assertNull($context['companyLogoDataUri']);
    }

    public function testLogoReturnsNullWhenFileHasNoFilename(): void
    {
        $this->config = [
            Configuration::getConfigKeyByName(Configuration::COMPANY_LOGO) => 42,
        ];

        $logo = new File();
        // No filename set

        $context = $this->generator($logo)->buildTemplateContext($this->invoice());

        self::assertNull($context['companyLogoDataUri']);
    }

    public function testLogoReturnsDataUriWhenValid(): void
    {
        $this->config = [
            Configuration::getConfigKeyByName(Configuration::COMPANY_LOGO) => 42,
        ];

        $logo = new File();
        $logo->setFilename('logo.png');
        $logo->setMimeType('image/png');

        $context = $this->generator($logo, 'PNG-CONTENT')->buildTemplateContext($this->invoice());

        self::assertSame('data:image/png;base64,' . base64_encode('PNG-CONTENT'), $context['companyLogoDataUri']);
    }

    public function testLogoReturnsNullWhenContentIsEmpty(): void
    {
        $this->config = [
            Configuration::getConfigKeyByName(Configuration::COMPANY_LOGO) => 42,
        ];

        $logo = new File();
        $logo->setFilename('logo.png');

        $context = $this->generator($logo, '')->buildTemplateContext($this->invoice());

        self::assertNull($context['companyLogoDataUri']);
    }

    public function testLogoUsesPngMimeTypeAsFallback(): void
    {
        $this->config = [
            Configuration::getConfigKeyByName(Configuration::COMPANY_LOGO) => 42,
        ];

        $logo = new File();
        $logo->setFilename('logo.bin');
        // No mime type set

        $context = $this->generator($logo, 'BINARY')->buildTemplateContext($this->invoice());

        self::assertSame('data:image/png;base64,' . base64_encode('BINARY'), $context['companyLogoDataUri']);
    }

    public function testCurrencyReturnsUppercaseIsoCode(): void
    {
        $invoice = $this->invoice();
        $invoice->setCurrency('eur');

        $context = $this->generator()->buildTemplateContext($invoice);

        self::assertSame('EUR', $context['currency']);
    }

    public function testCurrencyIsNullWhenUnset(): void
    {
        $invoice = $this->invoice();
        $invoice->setCurrency('');

        $context = $this->generator()->buildTemplateContext($invoice);

        self::assertNull($context['currency']);
    }

    public function testGenerateThrowsWithoutDompdfPackage(): void
    {
        require_once __DIR__ . '/Fixtures/dompdf_missing.php';

        $invoice = $this->invoice();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/dompdf/');

        \SoftSolutions4U\Bundle\InvoiceBundle\Pdf\DompdfMissingSwitch::$enabled = true;
        try {
            $this->generator()->generate($invoice);
        } finally {
            \SoftSolutions4U\Bundle\InvoiceBundle\Pdf\DompdfMissingSwitch::$enabled = false;
        }
    }
}
