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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Factory;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\InvoicePaymentFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\ExtendEntityInitializerTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessor;
use Oro\Component\Testing\Unit\EntityTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InvoicePaymentFactory.
 */
class InvoicePaymentFactoryTest extends TestCase
{
    use EntityTrait;
    use ExtendEntityInitializerTrait;

    private TokenAccessor&MockObject $tokenAccessor;
    private ManagerRegistry&MockObject $registry;

    /** @var CustomerUser $customerUser */
    private CustomerUser $customerUser;

    /** @var InvoicePaymentFactory $factory */
    private InvoicePaymentFactory $factory;

    /**
     * Sets up the fixtures used by the test cases.
     */
    protected function setUp(): void
    {
        $this->initializeExtendedEntityFields();

        // Built through real setters: Customer and CustomerUser are Oro extended
        // entities, and writing ids through the property accessor would go via
        // their magic __set and the extend processor.
        $customer = new Customer();
        $customer->setName('Test Customer');

        $this->customerUser = new CustomerUser();
        $this->customerUser->setCustomer($customer);
        $this->customerUser->setFirstName('Test');
        $this->customerUser->setLastName('CustomerUser');

        $this->tokenAccessor = $this->getMockBuilder(TokenAccessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUser'])
            ->getMock();
        $this->tokenAccessor->method('getUser')->willReturn($this->customerUser);

        $this->registry = $this->createMock(ManagerRegistry::class);

        $this->factory = new InvoicePaymentFactory(
            $this->tokenAccessor,
            $this->registry
        );
    }

    /**
     * Tests invoice payments can be created.
     *
     * @param array<int, array<string, mixed>> $invoiceData
     * @param float $expectedAmount
     *
     * @dataProvider invoicesWithExpectedAmountProvider
     */
    #[DataProvider('invoicesWithExpectedAmountProvider')]
    public function testInvoicePaymentsCanBeCreated(array $invoiceData, float $expectedAmount): void
    {
        $invoices = $this->setInvoiceCurrency($this->buildInvoices($invoiceData), 'AUD');

        $invoicePayment = $this->factory->create($invoices);

        self::assertInstanceOf(InvoicePayment::class, $invoicePayment);
        self::assertCount(count($invoiceData), $invoicePayment->getLineItems());
        self::assertContainsOnlyInstancesOf(InvoicePaymentLineItem::class, $invoicePayment->getLineItems());

        self::assertSame($this->customerUser->getCustomer(), $invoicePayment->getCustomer());
        self::assertSame($this->customerUser, $invoicePayment->getCustomerUser());
        self::assertTrue($invoicePayment->isActive());

        // Payment total is the sum of the invoice balances.
        self::assertEquals($expectedAmount, $invoicePayment->getAmount());
        self::assertSame('AUD', $invoicePayment->getCurrency());

        // With several invoices the payment is not pinned to any single one.
        self::assertNull($invoicePayment->getInvoice());
    }

    /**
     * Tests that a payment for a single invoice is linked directly to it.
     */
    public function testSingleInvoicePaymentIsLinkedToTheInvoice(): void
    {
        [$invoice] = $this->buildInvoices([
            ['id' => 7, 'invoiceNo' => 'INV007', 'amount' => 40.00, 'amountPaid' => 15.00, 'currency' => 'USD'],
        ]);

        $invoicePayment = $this->factory->createFromInvoice($invoice);

        self::assertSame($invoice, $invoicePayment->getInvoice());
        self::assertEquals(25.00, $invoicePayment->getAmount());
    }

    /**
     * Tests that nothing is persisted unless explicitly requested.
     */
    public function testCreateDoesNotPersistByDefault(): void
    {
        $this->registry->expects($this->never())->method('getManagerForClass');

        $this->factory->create($this->buildInvoices([
            ['id' => 1, 'invoiceNo' => 'INV001', 'amount' => 10.00, 'currency' => 'USD'],
        ]));
    }

    /**
     * Tests that persisting flushes the new payment.
     */
    public function testCreateCanPersist(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->atLeastOnce())
            ->method('persist')
            ->with($this->isInstanceOf(InvoicePayment::class));
        $entityManager->expects($this->atLeastOnce())->method('flush');

        $this->registry->method('getManagerForClass')->willReturn($entityManager);

        $this->factory->create(
            $this->buildInvoices([['id' => 1, 'invoiceNo' => 'INV001', 'amount' => 10.00, 'currency' => 'USD']]),
            true
        );
    }

    /**
     * Tests that a payment cannot be created without a storefront customer user.
     */
    public function testCreateRequiresACustomerUser(): void
    {
        $tokenAccessor = $this->getMockBuilder(TokenAccessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUser'])
            ->getMock();
        $tokenAccessor->method('getUser')->willReturn(null);

        $factory = new InvoicePaymentFactory(
            $tokenAccessor,
            $this->registry
        );

        $this->expectException(\LogicException::class);

        $factory->create([]);
    }

    /**
     * Tests single currency invoice payments are allowed.
     *
     * @param array<int, array<string, mixed>> $invoiceData
     *
     * @dataProvider invoicesProvider
     */
    #[DataProvider('invoicesProvider')]
    public function testSingleCurrencyInvoicePaymentsAreAllowed(array $invoiceData): void
    {
        $invoices = $this->setInvoiceCurrency($this->buildInvoices($invoiceData), 'USD');

        $this->factory->validateInvoiceCurrencies($invoices);

        // validateInvoiceCurrencies() returns nothing; not throwing is the assertion.
        $this->addToAssertionCount(1);
    }

    /**
     * Tests multi currency invoice payments are rejected.
     *
     * @param array<int, array<string, mixed>> $invoiceData
     *
     * @dataProvider invoicesProvider
     */
    #[DataProvider('invoicesProvider')]
    public function testMultiCurrencyInvoicePaymentsAreRejected(array $invoiceData): void
    {
        $invoices = $this->buildInvoices($invoiceData);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('softsolutions4u.invoice.frontend.payment.errors.multi_currency');

        $this->factory->validateInvoiceCurrencies($invoices);
    }

    /**
     * Tests invoice payment line items can be created.
     *
     * @param array<int, array<string, mixed>> $invoiceData
     *
     * @dataProvider invoicesProvider
     */
    #[DataProvider('invoicesProvider')]
    public function testInvoicePaymentLineItemsCanBeCreated(array $invoiceData): void
    {
        $invoicePayment = new InvoicePayment();

        foreach ($this->buildInvoices($invoiceData) as $invoice) {
            $lineItem = $this->factory->createInvoicePaymentLineItem($invoice, $invoicePayment);

            self::assertSame($invoice, $lineItem->getInvoice());
            self::assertEquals($invoice->getBalance(), $lineItem->getAmount());
            self::assertSame($invoice->getCurrency(), $lineItem->getCurrency());
            self::assertSame($invoicePayment, $lineItem->getInvoicePayment());
            self::assertTrue($invoicePayment->hasLineItem($lineItem));
        }
    }

    /**
     * Tests invoice payment line items can be created with custom amounts.
     *
     * @param array<int, array<string, mixed>> $invoiceData
     *
     * @dataProvider invoicesProvider
     */
    #[DataProvider('invoicesProvider')]
    public function testInvoicePaymentLineItemsCanBeCreatedWithCustomAmounts(array $invoiceData): void
    {
        $invoicePayment = new InvoicePayment();
        $invoice = $this->buildInvoices($invoiceData)[0];

        // Pay $1.23 less than the remaining balance.
        $amount = $invoice->getBalance() - 1.23;

        $lineItem = $this->factory->createInvoicePaymentLineItem($invoice, $invoicePayment, $amount);

        self::assertEquals($amount, $lineItem->getAmount());
    }

    /**
     * Provides the data sets for invoices with expected amount.
     *
     * @return \Generator<string, array{invoiceData: array<int, array<string, mixed>>, expectedAmount: float}>
     */
    public static function invoicesWithExpectedAmountProvider(): \Generator
    {
        foreach (self::invoiceSets() as $name => $set) {
            yield $name => $set;
        }
    }

    /**
     * Provides the data sets for invoices.
     *
     * @return \Generator<string, array{invoiceData: array<int, array<string, mixed>>}>
     */
    public static function invoicesProvider(): \Generator
    {
        foreach (self::invoiceSets() as $name => $set) {
            yield $name => ['invoiceData' => $set['invoiceData']];
        }
    }

    /**
     * Returns the invoice sets.
     *
     * @return array<string, array{invoiceData: array<int, array<string, mixed>>, expectedAmount: float}>
     */
    private static function invoiceSets(): array
    {
        return [
            'Multiple Currency Invoices' => [
                'invoiceData' => [
                    ['id' => 1, 'invoiceNo' => 'INV001', 'amount' => 20.00, 'currency' => 'GBP'],
                    ['id' => 2, 'invoiceNo' => 'INV002', 'amount' => 10.00, 'currency' => 'AUD'],
                ],
                'expectedAmount' => 30.00,
            ],
            'Multiple Currency Invoices with duplicate Currency' => [
                'invoiceData' => [
                    ['id' => 1, 'invoiceNo' => 'INV001', 'amount' => 20.53, 'currency' => 'NZD'],
                    ['id' => 2, 'invoiceNo' => 'INV002', 'amount' => 10.00, 'currency' => 'USD'],
                    ['id' => 3, 'invoiceNo' => 'INV003', 'amount' => 50.01, 'currency' => 'NZD'],
                ],
                'expectedAmount' => 80.54,
            ],
            'Multiple Currency Invoices with Partial Payments Made' => [
                'invoiceData' => [
                    ['id' => 1, 'invoiceNo' => 'INV001', 'amount' => 20.00, 'amountPaid' => 1.18, 'currency' => 'GBP'],
                    ['id' => 2, 'invoiceNo' => 'INV002', 'amount' => 10.00, 'amountPaid' => 5.06, 'currency' => 'AUD'],
                ],
                'expectedAmount' => 23.76,
            ],
        ];
    }

    /**
     * Builds the invoices.
     *
     * @param array<int, array<string, mixed>> $invoiceData
     * @return array<int, Invoice>
     */
    private function buildInvoices(array $invoiceData): array
    {
        $invoices = [];
        foreach ($invoiceData as $properties) {
            $invoices[] = $this->getEntity(Invoice::class, $properties);
        }

        return $invoices;
    }

    /**
     * Sets the invoice currency.
     *
     * @param array<int, Invoice> $invoices
     * @param string $currency
     * @return array<int, Invoice>
     */
    private function setInvoiceCurrency(array $invoices, string $currency): array
    {
        foreach ($invoices as $invoice) {
            $invoice->setCurrency($currency);
        }

        return $invoices;
    }

    /**
     * Tests that an unsubmitted payment of the same customer user is reused instead of creating another one.
     */
    public function testPersistingReusesAnUnsubmittedPayment(): void
    {
        [$invoice] = $this->buildInvoices([
            ['id' => 8, 'invoiceNo' => 'INV008', 'amount' => 40.00, 'amountPaid' => 0.00, 'currency' => 'USD'],
        ]);

        $existing = new InvoicePayment();
        $existing->setActive(true);
        $existing->setPaymentMethod('');
        $existing->setCustomerUser($this->customerUser);
        $invoice->addInvoicePayment($existing);

        $this->registry->expects(self::never())->method('getManagerForClass');

        self::assertSame($existing, $this->factory->createFromInvoice($invoice, true));
    }

    /**
     * Tests that a payment already submitted with a payment method is not reused.
     */
    public function testSubmittedPaymentIsNotReused(): void
    {
        [$invoice] = $this->buildInvoices([
            ['id' => 9, 'invoiceNo' => 'INV009', 'amount' => 40.00, 'amountPaid' => 0.00, 'currency' => 'USD'],
        ]);

        $submitted = new InvoicePayment();
        $submitted->setActive(true);
        $submitted->setPaymentMethod('payment_term_1');
        $submitted->setCustomerUser($this->customerUser);
        $invoice->addInvoicePayment($submitted);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $this->registry->method('getManagerForClass')->willReturn($entityManager);

        self::assertNotSame($submitted, $this->factory->createFromInvoice($invoice, true));
    }
}
