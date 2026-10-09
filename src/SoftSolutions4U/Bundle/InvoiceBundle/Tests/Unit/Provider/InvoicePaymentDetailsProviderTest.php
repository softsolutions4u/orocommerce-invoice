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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Provider;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Generator\BankTransferReferenceGenerator;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaymentDetailsProvider;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit tests for the payment instructions printed on invoice PDFs.
 */
class InvoicePaymentDetailsProviderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var InvoicePaymentDetailsProvider $provider */
    private InvoicePaymentDetailsProvider $provider;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->config = [
            'oro_ui.application_url' => 'https://shop.example.com/',
            'softsolutions4u_invoice.bank_details_enabled' => true,
            'softsolutions4u_invoice.bank_us_enabled' => true,
            'softsolutions4u_invoice.bank_us_account_holder' => 'Example Seller Inc.',
            'softsolutions4u_invoice.bank_us_bank_name' => 'Example Bank, N.A.',
            'softsolutions4u_invoice.bank_us_routing_number' => '123456789',
            'softsolutions4u_invoice.bank_us_account_number' => '000123456789',
            'softsolutions4u_invoice.bank_us_swift' => 'EXAMUS33',
            'softsolutions4u_invoice.bank_us_instructions' => '',
            'softsolutions4u_invoice.bank_de_enabled' => false,
        ];

        $this->rebuildProvider();
    }

    /**
     * Rebuilds the provider so a test can change $this->config first.
     */
    private function rebuildProvider(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturnCallback(fn (string $name) => $this->config[$name] ?? null);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters) => '/customer/invoice/payment/create/' . $parameters['id']
        );

        $this->provider = new InvoicePaymentDetailsProvider(
            $configManager,
            $urlGenerator,
            new BankTransferReferenceGenerator()
        );
    }

    /**
     * Tests that an unpaid invoice gets bank details, amount due and a Pay Online link.
     */
    public function testUnpaidInvoiceGetsPaymentInstructions(): void
    {
        $details = $this->provider->getPaymentDetails($this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00));

        self::assertIsArray($details);
        self::assertTrue($details['payable']);
        self::assertSame(100.00, $details['amountDue']);
        self::assertSame('USD', $details['currency']);
        self::assertSame('123456789', $details['fields']['softsolutions4u.invoice.bank.routing_number.label']);
        self::assertSame('https://shop.example.com/customer/invoice/payment/create/19', $details['onlineUrl']);
    }

    /**
     * Tests that the bank transfer reference is its own reference, not the invoice number.
     */
    public function testBankTransferHasItsOwnReference(): void
    {
        $details = $this->provider->getPaymentDetails($this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00));

        self::assertSame('BT-0000019-41', $details['reference']);
        self::assertSame('INV-2026-09-00019', $details['invoiceNo']);
        self::assertNotSame($details['invoiceNo'], $details['reference']);
    }

    /**
     * Tests that a partially paid invoice asks only for what is still owed.
     */
    public function testPartiallyPaidInvoiceAsksForTheBalance(): void
    {
        $details = $this->provider->getPaymentDetails(
            $this->invoice(Invoice::STATUS_PARTIALLY_PAID, 100.00, 40.00)
        );

        self::assertSame(60.00, $details['amountDue']);
        self::assertNotNull($details['onlineUrl']);
    }

    /**
     * Tests that an invoice with nothing left to pay keeps its bank details but loses the call to pay.
     *
     * @param string $status
     * @param float $amount
     * @param float $amountPaid
     * @dataProvider nothingToPayDataProvider
     */
    #[DataProvider('nothingToPayDataProvider')]
    public function testNothingOwedKeepsBankDetailsWithoutAmountDueOrPayOnline(
        string $status,
        float $amount,
        float $amountPaid
    ): void {
        $details = $this->provider->getPaymentDetails($this->invoice($status, $amount, $amountPaid));

        self::assertIsArray($details, 'Bank transfer instructions are shown on every invoice');
        self::assertFalse($details['payable']);
        self::assertNull($details['amountDue']);
        self::assertNull($details['onlineUrl']);

        self::assertSame('123456789', $details['fields']['softsolutions4u.invoice.bank.routing_number.label']);
        self::assertSame('BT-0000019-41', $details['reference']);
    }

    /**
     * Provides the data sets for nothing to pay data.
     *
     * @return \Generator<string, array{status: string, amount: float, amountPaid: float}>
     */
    public static function nothingToPayDataProvider(): \Generator
    {
        yield 'order paid at checkout' => ['status' => Invoice::STATUS_PAID, 'amount' => 50.48, 'amountPaid' => 50.48];
        yield 'posted but fully paid' => ['status' => Invoice::STATUS_POSTED, 'amount' => 50.48, 'amountPaid' => 50.48];
        yield 'overpaid' => ['status' => Invoice::STATUS_POSTED, 'amount' => 50.00, 'amountPaid' => 60.00];
        yield 'status paid' => ['status' => Invoice::STATUS_PAID, 'amount' => 50.00, 'amountPaid' => 0.00];
        yield 'cancelled' => ['status' => Invoice::STATUS_CANCELLED, 'amount' => 50.00, 'amountPaid' => 0.00];
        yield 'zero value' => ['status' => Invoice::STATUS_POSTED, 'amount' => 0.00, 'amountPaid' => 0.00];
    }

    /**
     * Tests that nothing is offered when bank details are switched off.
     */
    public function testNoPaymentInstructionsWhenDisabled(): void
    {
        $this->config['softsolutions4u_invoice.bank_details_enabled'] = false;

        self::assertNull($this->provider->getPaymentDetails($this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00)));
    }

    /**
     * Returns the invoice.
     *
     * @param string $status
     * @param float $amount
     * @param float $amountPaid
     * @return Invoice
     */
    private function invoice(string $status, float $amount, float $amountPaid): Invoice
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setStatus($status);
        $invoice->setCurrency('USD');
        $invoice->setAmount($amount);
        $invoice->setAmountPaid($amountPaid);

        $property = new \ReflectionProperty($invoice, 'id');
        $property->setValue($invoice, 19);

        return $invoice;
    }

        // ---------------------------------------------------------- German account

    /**
     * Tests that a EUR invoice uses the German account when it is enabled.
     */
    public function testEurInvoiceUsesTheGermanAccount(): void
    {
        $this->config['softsolutions4u_invoice.bank_de_enabled'] = true;
        $this->config['softsolutions4u_invoice.bank_de_account_holder'] = 'Example Seller GmbH';
        $this->config['softsolutions4u_invoice.bank_de_bank_name'] = 'Example Bank AG';
        $this->config['softsolutions4u_invoice.bank_de_iban'] = 'DE00123456780000000000';
        $this->config['softsolutions4u_invoice.bank_de_bic'] = 'EXAMDEFF';
        $this->rebuildProvider();

        $invoice = $this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00);
        $invoice->setCurrency('EUR');

        $details = $this->provider->getPaymentDetails($invoice);

        self::assertSame('de', $details['region']);
        self::assertSame('DE00123456780000000000', $details['fields']['softsolutions4u.invoice.bank.iban.label']);
        self::assertSame('EXAMDEFF', $details['fields']['softsolutions4u.invoice.bank.bic.label']);
    }

    /**
     * Tests that a USD invoice stays on the US account even when German is enabled.
     */
    public function testUsdInvoiceStaysOnTheUsAccountWhenGermanIsAlsoEnabled(): void
    {
        $this->config['softsolutions4u_invoice.bank_de_enabled'] = true;
        $this->config['softsolutions4u_invoice.bank_de_iban'] = 'DE00123456780000000000';
        $this->rebuildProvider();

        $details = $this->provider->getPaymentDetails($this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00));

        self::assertSame('us', $details['region']);
    }

    /**
     * Tests that an EUR invoice falls back to the US account when German is not enabled.
     */
    public function testEurInvoiceFallsBackToUsWhenGermanIsDisabled(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00);
        $invoice->setCurrency('EUR');

        $details = $this->provider->getPaymentDetails($invoice);

        // No EUR account is available, so it falls back to US.
        self::assertSame('us', $details['region']);
    }

    /**
     * Tests that a USD invoice falls back to German when US is not enabled.
     */
    public function testUsdInvoiceFallsBackToGermanWhenUsIsDisabled(): void
    {
        $this->config['softsolutions4u_invoice.bank_us_enabled'] = false;
        $this->config['softsolutions4u_invoice.bank_de_enabled'] = true;
        $this->config['softsolutions4u_invoice.bank_de_iban'] = 'DE00123456780000000000';
        $this->rebuildProvider();

        $details = $this->provider->getPaymentDetails($this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00));

        self::assertSame('de', $details['region']);
    }

    /**
     * Tests that no payment details are returned when no account is enabled.
     */
    public function testNoPaymentDetailsWhenNoAccountIsEnabled(): void
    {
        $this->config['softsolutions4u_invoice.bank_us_enabled'] = false;
        $this->config['softsolutions4u_invoice.bank_de_enabled'] = false;
        $this->rebuildProvider();

        self::assertNull(
            $this->provider->getPaymentDetails($this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00))
        );
    }

    /**
     * Tests that missing bank fields cause the details to be withheld.
     */
    public function testNoPaymentDetailsWhenBankFieldsAreEmpty(): void
    {
        $this->config['softsolutions4u_invoice.bank_us_account_holder'] = '';
        $this->config['softsolutions4u_invoice.bank_us_bank_name'] = '';
        $this->config['softsolutions4u_invoice.bank_us_routing_number'] = '';
        $this->config['softsolutions4u_invoice.bank_us_account_number'] = '';
        $this->config['softsolutions4u_invoice.bank_us_swift'] = '';
        $this->rebuildProvider();

        self::assertNull(
            $this->provider->getPaymentDetails($this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00))
        );
    }

    // ------------------------------------------------------ online payment URL

    /**
     * Tests that an unsaved invoice produces no online payment link.
     */
    public function testUnsavedInvoiceHasNoOnlineUrl(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setStatus(Invoice::STATUS_POSTED);
        $invoice->setCurrency('USD');
        $invoice->setAmount(100.00);
        $invoice->setAmountPaid(0.00);
        // No id set - it's an unsaved entity.

        $details = $this->provider->getPaymentDetails($invoice);

        self::assertTrue($details['payable']);
        self::assertNull($details['onlineUrl']);
    }

    /**
     * Tests that an empty application URL produces no online payment link.
     */
    public function testEmptyApplicationUrlHasNoOnlineUrl(): void
    {
        $this->config['oro_ui.application_url'] = '';
        $this->rebuildProvider();

        $details = $this->provider->getPaymentDetails($this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00));

        self::assertTrue($details['payable']);
        self::assertNull($details['onlineUrl']);
    }

    /**
     * Tests that a URL generation failure produces no online payment link.
     */
    public function testUrlGenerationFailureProducesNoOnlineUrl(): void
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturnCallback(fn (string $name) => $this->config[$name] ?? null);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willThrowException(new \RuntimeException('route missing'));

        $provider = new InvoicePaymentDetailsProvider(
            $configManager,
            $urlGenerator,
            new BankTransferReferenceGenerator()
        );

        $details = $provider->getPaymentDetails($this->invoice(Invoice::STATUS_POSTED, 100.00, 0.00));

        self::assertTrue($details['payable']);
        self::assertNull($details['onlineUrl']);
    }

    // ------------------------------------------------------ fallback reference

    /**
     * Tests that an unsaved invoice falls back to the invoice number for the reference.
     */
    public function testUnsavedInvoiceFallsBackToInvoiceNumberForReference(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setStatus(Invoice::STATUS_POSTED);
        $invoice->setCurrency('USD');
        $invoice->setAmount(100.00);
        $invoice->setAmountPaid(0.00);

        $details = $this->provider->getPaymentDetails($invoice);

        // No id means BankTransferReferenceGenerator returns null.
        self::assertSame('INV-2026-09-00019', $details['reference']);
    }
}
