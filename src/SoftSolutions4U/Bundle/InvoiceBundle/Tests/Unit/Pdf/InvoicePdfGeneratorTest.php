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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Pdf\InvoicePdfGenerator;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaymentDetailsProvider;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\AttachmentBundle\Manager\FileManager;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Covers the invoice PDF header: brand on the left; VAT number, currency,
 * due date and the customer the invoice is payable by on the right.
 *
 * The real Twig template is rendered with stand-ins for Oro's formatting
 * filters, so these tests exercise the actual markup without a container or
 * the Dompdf engine. `trans` returns the key itself, which lets the tests
 * assert on labels independently of locale.
 */
class InvoicePdfGeneratorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * Sets up default configuration.
     */
    protected function setUp(): void
    {
        $this->config = [
            'softsolutions4u_invoice.company_name' => 'Example Seller Ltd',
            'softsolutions4u_invoice.company_vat_number' => 'DE123456789',
            'softsolutions4u_invoice.company_logo' => null,
        ];
    }

    /**
     * Tests that the configured VAT number is passed to the template, trimmed.
     */
    public function testContextContainsTheVatNumber(): void
    {
        $this->config['softsolutions4u_invoice.company_vat_number'] = '  DE123456789 ';

        $context = $this->createGenerator()->buildTemplateContext($this->createInvoice());

        self::assertSame('DE123456789', $context['companyVatNumber']);
    }

    /**
     * Tests that a blank VAT number is treated as not configured.
     */
    public function testBlankVatNumberBecomesNull(): void
    {
        $this->config['softsolutions4u_invoice.company_vat_number'] = '   ';

        $context = $this->createGenerator()->buildTemplateContext($this->createInvoice());

        self::assertNull($context['companyVatNumber']);
    }

    /**
     * Tests that the currency is normalised to an upper-case ISO code.
     */
    public function testContextContainsTheCurrencyCode(): void
    {
        $invoice = $this->createInvoice();
        $invoice->setCurrency('eur');

        $context = $this->createGenerator()->buildTemplateContext($invoice);

        self::assertSame('EUR', $context['currency']);
    }

    /**
     * Tests that "Payable By" lists the customer, the contact person and their email.
     */
    public function testPayableByIsTheCustomer(): void
    {
        $context = $this->createGenerator()->buildTemplateContext($this->createInvoiceWithCustomer());

        self::assertSame(
            ['ABC Manufacturing', 'John Smith', 'john.smith@example.com'],
            $context['payableBy']
        );
    }

    /**
     * Tests that without an Oro customer the billing address identifies the payer.
     */
    public function testPayableByFallsBackToTheBillingAddress(): void
    {
        $invoice = $this->createInvoice();
        $invoice->setBillingAddressOrganization('Acme Ltd');
        $invoice->setBillingAddressFirstName('Jane');
        $invoice->setBillingAddressLastName('Doe');

        $context = $this->createGenerator()->buildTemplateContext($invoice);

        self::assertSame(['Acme Ltd', 'Jane Doe'], $context['payableBy']);
    }

    /**
     * Tests that a sole trader whose company and contact names match is listed once.
     */
    public function testPayableByDropsRepeatedValues(): void
    {
        $invoice = $this->createInvoice();
        $invoice->setBillingAddressOrganization('Jane Doe');
        $invoice->setBillingAddressFirstName('Jane');
        $invoice->setBillingAddressLastName('Doe');

        $context = $this->createGenerator()->buildTemplateContext($invoice);

        self::assertSame(['Jane Doe'], $context['payableBy']);
    }

    /**
     * Tests that nothing is offered when the payer is unknown.
     */
    public function testPayableByIsNullWhenThePayerIsUnknown(): void
    {
        $context = $this->createGenerator()->buildTemplateContext($this->createInvoice());

        self::assertNull($context['payableBy']);
    }

    /**
     * Tests that the left header column holds the brand only.
     */
    public function testLeftColumnContainsOnlyTheBrand(): void
    {
        $invoice = $this->createInvoiceWithCustomer();
        $invoice->setDueDate(new \DateTime('2026-10-15'));

        $left = $this->extract($this->render($invoice), '#<td class="header-brand">(.*?)</td>#s');

        self::assertStringContainsString('Example Seller Ltd', $left);
        foreach (['DE123456789', 'softsolutions4u.invoice.', 'ABC Manufacturing', 'USD', '2026-10-15'] as $unexpected) {
            self::assertStringNotContainsString($unexpected, $left);
        }
    }

    /**
     * Tests that the right column shows the VAT number, currency, due date and payer.
     */
    public function testRightColumnContainsAllInvoiceDetails(): void
    {
        $invoice = $this->createInvoiceWithCustomer();
        $invoice->setDueDate(new \DateTime('2026-10-15'));

        $html = $this->render($invoice);
        $right = $this->extract($html, '#<td class="header-details">(.*?)<table class="address-table">#s');

        self::assertStringContainsString('INV-2026-09-00001', $this->row($right, 'invoice-no'));
        self::assertStringContainsString('2026-10-15', $this->row($right, 'due-date'));
        self::assertStringContainsString('USD', $this->row($right, 'currency'));
        self::assertStringContainsString('DE123456789', $this->row($right, 'vat-number'));

        $payableBy = $this->extract($right, '#<div class="payable-by">(.*?)</div>#s');
        self::assertStringContainsString('softsolutions4u.invoice.payable_by.label', $payableBy);
        self::assertMatchesRegularExpression('#class="payable-by__name">\s*ABC Manufacturing\s*<#', $payableBy);
        self::assertStringContainsString('John Smith', $payableBy);
        self::assertStringContainsString('john.smith@example.com', $payableBy);
    }

    /**
     * Tests that no empty VAT row is printed when no VAT number is configured.
     */
    public function testPdfOmitsTheVatRowWhenNotConfigured(): void
    {
        $this->config['softsolutions4u_invoice.company_vat_number'] = '';

        $html = $this->render($this->createInvoice());

        self::assertStringNotContainsString('softsolutions4u.invoice.vat_number.label', $html);
        self::assertStringNotContainsString('class="vat-number"', $html);
    }

    /**
     * Tests that the Payable By block is omitted when the payer is unknown.
     */
    public function testPdfOmitsPayableByWhenThePayerIsUnknown(): void
    {
        $html = $this->render($this->createInvoice());

        self::assertStringNotContainsString('softsolutions4u.invoice.payable_by.label', $html);
    }

    /**
     * Tests that the due date keeps its own label and is not printed twice.
     */
    public function testPdfShowsTheDueDateOnce(): void
    {
        $invoice = $this->createInvoice();
        $invoice->setDueDate(new \DateTime('2026-10-15'));

        $html = $this->render($invoice);

        self::assertStringContainsString('softsolutions4u.invoice.due_date.label', $this->row($html, 'due-date'));
        self::assertSame(1, substr_count($html, '2026-10-15'));
    }

    /**
     * Tests that the logo-to-Payable-By header is a fixed block, so Dompdf repeats it on every page.
     */
    public function testHeaderRepeatsOnEveryPage(): void
    {
        $html = $this->render($this->createInvoiceWithCustomer());

        $header = $this->extract($html, '#<div class="page-header">(.*?)<div class="page-footer">#s');
        self::assertStringContainsString('class="header-brand"', $header);
        self::assertStringContainsString('class="invoice-meta"', $header);
        self::assertStringContainsString('class="payable-by"', $header);

        $css = $this->extract($html, '#<style>(.*?)</style>#s');
        self::assertMatchesRegularExpression('#\.page-header\s*\{[^}]*position:\s*fixed#', $css);

        // Fixed elements only repeat if they come before the page content.
        self::assertLessThan(strpos($html, 'class="address-table"'), strpos($html, 'class="page-header"'));
    }

    /**
     * Tests that every page carries a footer with the invoice number.
     */
    public function testFooterWithTheInvoiceNumberRepeatsOnEveryPage(): void
    {
        $html = $this->render($this->createInvoice());

        $footer = $this->extract($html, '#<div class="page-footer">(.*?)</div>#s');
        self::assertStringContainsString('INV-2026-09-00001', $footer);

        $css = $this->extract($html, '#<style>(.*?)</style>#s');
        self::assertMatchesRegularExpression('#\.page-footer\s*\{[^}]*position:\s*fixed#', $css);
        self::assertLessThan(strpos($html, 'class="address-table"'), strpos($html, 'class="page-footer"'));
    }

    /**
     * Tests that payment instructions start on a page of their own and are never split.
     */
    public function testPaymentInstructionsAreOnASeparatePage(): void
    {
        $html = $this->render($this->createInvoiceWithCustomer(), $this->paymentDetails());

        $page = $this->extract($html, '#<div class="pay-page">(.*)</body>#s');
        self::assertStringContainsString('<div class="pay-keep-together">', $page);
        self::assertStringContainsString('softsolutions4u.invoice.bank.title', $page);
        self::assertStringContainsString('DE00 1234 5678 0000 0000 00', $page);
        self::assertStringContainsString('https://example.com/pay/1', $page);

        // The instructions come after the invoice totals, not in the middle of them.
        self::assertGreaterThan(strpos($html, 'class="totals-table"'), strpos($html, 'class="pay-page"'));

        $css = $this->extract($html, '#<style>(.*?)</style>#s');
        self::assertMatchesRegularExpression('#\.pay-page\s*\{[^}]*page-break-before:\s*always#', $css);
        self::assertMatchesRegularExpression('#\.pay-keep-together\s*\{[^}]*page-break-inside:\s*avoid#', $css);
    }

    /**
     * Tests that Payable By is shown once, in the repeating header, and not again in the payment instructions.
     */
    public function testPayableByIsOnlyInTheHeader(): void
    {
        $html = $this->render($this->createInvoiceWithCustomer(), $this->paymentDetails());

        $page = $this->extract($html, '#<div class="pay-page">(.*)</body>#s');
        self::assertStringNotContainsString('softsolutions4u.invoice.payable_by.label', $page);
        self::assertStringNotContainsString('ABC Manufacturing', $page);

        self::assertSame(1, substr_count($html, 'class="payable-by__name">'));
    }

    /**
     * Tests the "Reference" label, the bank transfer reference and the note telling the customer to quote it.
     */
    public function testBankTransferShowsItsOwnReference(): void
    {
        $html = $this->render($this->createInvoice(), $this->paymentDetails());

        $row = $this->row($html, 'pay__reference');
        self::assertStringContainsString('softsolutions4u.invoice.bank.reference.label', $row);
        self::assertStringContainsString('BT-0000009-12', $row);
        self::assertStringContainsString('softsolutions4u.invoice.bank.reference.note', $html);
    }

    /**
     * Tests that a paid invoice still shows bank transfer instructions, on their own page, full width.
     */
    public function testPaidInvoiceStillShowsBankTransferInstructions(): void
    {
        $html = $this->render($this->createInvoiceWithCustomer(), $this->paidPaymentDetails());

        $page = $this->extract($html, '#<div class="pay-page">(.*)</body>#s');
        self::assertStringContainsString('softsolutions4u.invoice.bank.title', $page);
        self::assertStringContainsString('softsolutions4u.invoice.bank.transfer.label', $page);
        self::assertStringContainsString('DE00 1234 5678 0000 0000 00', $page);
        self::assertStringContainsString('BT-0000009-12', $this->row($page, 'pay__reference'));

        self::assertMatchesRegularExpression(
            '#<td class="pay__col pay__col--bank"\s+colspan="2"#',
            $page,
            'Without the Pay Online column the bank details span the full width'
        );
    }

    /**
     * Tests that a paid invoice shows no amount due and no Pay Online link.
     */
    public function testPaidInvoiceHasNoAmountDueOrPayOnline(): void
    {
        $html = $this->render($this->createInvoiceWithCustomer(), $this->paidPaymentDetails());

        foreach (
            ['softsolutions4u.invoice.bank.amount_due.label', 'class="pay__due"', 'class="pay__col pay__col--online"',
            'softsolutions4u.invoice.bank.online.label', 'softsolutions4u.invoice.bank.online.cta', 'class="pay__cta"',
            'https://example.com/pay/1'] as $unexpected
        ) {
            self::assertStringNotContainsString($unexpected, $html);
        }
    }

    /**
     * Tests that an unpaid invoice shows the amount due and the Pay Online link beside the bank details.
     */
    public function testUnpaidInvoiceShowsAmountDueAndPayOnline(): void
    {
        $html = $this->render($this->createInvoiceWithCustomer(), $this->paymentDetails());

        self::assertStringContainsString('softsolutions4u.invoice.bank.amount_due.label', $html);
        self::assertStringContainsString('EUR 100.00', $this->extract($html, '#<span class="pay__due">(.*?)</span>#s'));
        self::assertStringContainsString('class="pay__col pay__col--online"', $html);
        self::assertStringContainsString('https://example.com/pay/1', $html);
        self::assertDoesNotMatchRegularExpression('#<td class="pay__col pay__col--bank"\s+colspan="2"#', $html);
    }

    /**
     * Tests that an invoice with bank details switched off has no payment instructions page.
     */
    public function testNoPaymentInstructionsPageWhenBankDetailsAreOff(): void
    {
        $html = $this->render($this->createInvoiceWithCustomer(), null);

        self::assertStringNotContainsString('class="pay-page"', $html);
        self::assertStringNotContainsString('softsolutions4u.invoice.bank.title', $html);
    }

    /**
     * Returns the paid payment details.
     *
     * @return array<string, mixed> details for an invoice with nothing left to pay
     */
    private function paidPaymentDetails(): array
    {
        return array_merge($this->paymentDetails(), [
            'payable' => false,
            'amountDue' => null,
            'onlineUrl' => null,
        ]);
    }

    /**
     * Returns the payment details.
     *
     * @return array<string, mixed>
     */
    private function paymentDetails(): array
    {
        return [
            'fields' => ['softsolutions4u.invoice.bank.iban.label' => 'DE00 1234 5678 0000 0000 00'],
            'instructions' => 'Transfer within 30 days.',
            'reference' => 'BT-0000009-12',
            'invoiceNo' => 'INV-2026-09-00001',
            'payable' => true,
            'amountDue' => 100.0,
            'currency' => 'EUR',
            'onlineUrl' => 'https://example.com/pay/1',
        ];
    }

    /**
     * Tests that every literal translation key in the PDF template exists in every shipped locale.
     *
     * @return void
     */
    public function testEveryPdfLabelIsTranslated(): void
    {
        $template = (string) file_get_contents($this->viewsDir() . '/Pdf/invoice.html.twig');
        preg_match_all("/'([a-z0-9_.]+)'\\|trans/", $template, $matches);
        $keys = array_unique($matches[1]);

        self::assertContains('softsolutions4u.invoice.vat_number.label', $keys);
        self::assertContains('softsolutions4u.invoice.payable_by.label', $keys);
        self::assertContains('softsolutions4u.invoice.currency.label', $keys);
        self::assertContains('softsolutions4u.invoice.due_date.label', $keys);

        $translationFiles = glob(dirname(__DIR__, 3) . '/Resources/translations/messages.*.yml') ?: [];
        self::assertNotEmpty($translationFiles);

        foreach ($translationFiles as $file) {
            $messages = $this->flatten((array) Yaml::parseFile($file));

            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $messages, sprintf('"%s" is missing from %s', $key, basename($file)));
            }
        }
    }

    /**
     * Creates the generator.
     *
     * @param array<string, mixed>|null $paymentDetails
     * @param Environment|null $twig
     * @return InvoicePdfGenerator
     */
    private function createGenerator(?Environment $twig = null, ?array $paymentDetails = null): InvoicePdfGenerator
    {
        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturnCallback(
            fn (string $name) => $this->config[$name] ?? null
        );

        $paymentDetailsProvider = $this->createMock(InvoicePaymentDetailsProvider::class);
        $paymentDetailsProvider->method('getPaymentDetails')->willReturn($paymentDetails);

        return new InvoicePdfGenerator(
            $twig ?? $this->createMock(Environment::class),
            $configManager,
            $this->createMock(FileManager::class),
            $this->createMock(ManagerRegistry::class),
            $paymentDetailsProvider
        );
    }

    /**
     * Renders the given data.
     *
     * @param array<string, mixed>|null $paymentDetails
     * @param Invoice $invoice
     * @return string
     */
    private function render(Invoice $invoice, ?array $paymentDetails = null): string
    {
        return $this->createGenerator($this->createTwig(), $paymentDetails)->renderHtml($invoice);
    }

    /**
     * Builds a Twig environment over the bundle's views with stand-ins for Oro's filters.
     *
     * @return Environment
     */
    private function createTwig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath($this->viewsDir(), 'SoftSolutions4UInvoice');

        $twig = new Environment($loader, ['strict_variables' => false, 'cache' => false]);
        $twig->addFilter(new TwigFilter('trans', static fn ($key): string => (string) $key));
        $twig->addFilter(new TwigFilter(
            'oro_format_date',
            static fn (\DateTimeInterface $date, array $options = []): string => $date->format('Y-m-d')
        ));
        $twig->addFilter(new TwigFilter(
            'oro_format_currency',
            static fn ($value, array $options = []): string => sprintf(
                '%s %.2f',
                $options['currency'] ?? '',
                (float) $value
            )
        ));

        return $twig;
    }

    /**
     * Returns the first capture group of $pattern, failing the test if it does not match.
     *
     * @param string $html
     * @param string $pattern
     * @return string
     */
    private function extract(string $html, string $pattern): string
    {
        self::assertMatchesRegularExpression($pattern, $html);
        preg_match($pattern, $html, $match);

        return $match[1];
    }

    /**
     * Returns the markup of the meta table row with the given CSS class.
     *
     * @param string $html
     * @param string $class
     * @return string
     */
    private function row(string $html, string $class): string
    {
        return $this->extract($html, sprintf('#<tr class="%s">(.*?)</tr>#s', preg_quote($class, '#')));
    }

    /**
     * Creates the invoice with customer.
     *
     * @return Invoice
     */
    private function createInvoiceWithCustomer(): Invoice
    {
        $customer = new Customer();
        $customer->setName('ABC Manufacturing');

        $customerUser = new CustomerUser();
        $customerUser->setCustomer($customer);
        $customerUser->setFirstName('John');
        $customerUser->setLastName('Smith');
        $customerUser->setEmail('john.smith@example.com');

        $invoice = $this->createInvoice();
        $invoice->setCustomer($customer);
        $invoice->setCustomerUser($customerUser);

        return $invoice;
    }

    /**
     * Creates the invoice.
     *
     * @return Invoice
     */
    private function createInvoice(): Invoice
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00001');
        $invoice->setCurrency('USD');
        $invoice->setAmount(100.00);

        return $invoice;
    }

    /**
     * Returns the views dir.
     *
     * @return string
     */
    private function viewsDir(): string
    {
        return dirname(__DIR__, 3) . '/Resources/views';
    }

    /**
     * Flattens the given data.
     *
     * @param array<mixed> $messages
     * @param string $prefix
     * @return array<string, mixed>
     */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];

        foreach ($messages as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
