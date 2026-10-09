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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Entity;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoiceLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\ExtendEntityInitializerTrait;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Component\Testing\Unit\EntityTestCaseTrait;
use Oro\Component\Testing\Unit\EntityTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Invoice entity.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class InvoiceTest extends TestCase
{
    use EntityTestCaseTrait;
    use EntityTrait;
    use ExtendEntityInitializerTrait;

    /**
     * Sets up the fixtures used by the test cases.
     */
    protected function setUp(): void
    {
        $this->initializeExtendedEntityFields();
    }

    /**
     * Tests that every property round-trips through its accessors.
     */
    public function testProperties(): void
    {
        $now = new \DateTime('now');
        $properties = [
            ['id', 123],
            ['invoiceNo', 'invoice-test-01', 'INV001'],
            ['customer', new Customer()],
            ['createdAt', $now, false],
            ['updatedAt', $now, false],
            ['issueDate', $now, false],
            ['dueDate', $now, false],
            ['amount', 123.45, 10, 99999999.99],
            ['taxAmount', 0.0, 123.45, 10, 99999999.99],
            ['amountPaid', 0.0, 123.45, 10, 99999999.99],
        ];

        $this->assertPropertyAccessors(new Invoice(), $properties);
        $this->assertPropertyCollection(new Invoice(), 'lineItems', new InvoiceLineItem());
    }

    /**
     * Tests that the status round-trips as a plain string.
     */
    public function testSetInvoiceStatus(): void
    {
        $invoice = new Invoice();

        self::assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus());

        $invoice->setStatus(Invoice::STATUS_OPEN);

        self::assertSame(Invoice::STATUS_OPEN, $invoice->getStatus());
    }

    /**
     * Tests that every documented status is offered by getStatuses().
     */
    public function testInvoiceStatuses(): void
    {
        $statuses = Invoice::getStatuses();

        self::assertIsArray($statuses);
        self::assertNotEmpty($statuses);
        self::assertArrayHasKey(Invoice::STATUS_DRAFT, $statuses);
        self::assertArrayHasKey(Invoice::STATUS_OPEN, $statuses);
        self::assertArrayHasKey(Invoice::STATUS_CANCELLED, $statuses);
        self::assertArrayHasKey(Invoice::STATUS_PAID, $statuses);
        self::assertArrayHasKey(Invoice::STATUS_OVERDUE, $statuses);
    }

    /**
     * Tests that a draft can never be paid or shown to a customer.
     */
    public function testDraftIsNeitherPayableNorVisibleOnTheStorefront(): void
    {
        self::assertNotContains(Invoice::STATUS_DRAFT, Invoice::UNPAID_STATUSES);
        self::assertNotContains(Invoice::STATUS_DRAFT, Invoice::FRONTEND_VISIBLE_STATUSES);
    }

    /**
     * Tests that every payable and every visible status is a declared status.
     */
    public function testStatusGroupsOnlyContainDeclaredStatuses(): void
    {
        $declared = array_keys(Invoice::getStatuses());

        foreach (array_merge(Invoice::UNPAID_STATUSES, Invoice::FRONTEND_VISIBLE_STATUSES) as $status) {
            self::assertContains($status, $declared);
        }
    }

    /**
     * Tests that the price object reflects the amount and currency.
     */
    public function testInvoicePrices(): void
    {
        /** @var Invoice $invoice */
        $invoice = $this->getEntity(Invoice::class, [
            'amount' => 123.45,
            'currency' => 'NZD',
        ]);

        self::assertInstanceOf(Price::class, $invoice->getPrice());
        self::assertEquals(123.45, $invoice->getPrice()->getValue());
        self::assertEquals('NZD', $invoice->getPrice()->getCurrency());

        $invoice->setAmount(99.95);

        self::assertEquals(99.95, $invoice->getPrice()->getValue());
        self::assertEquals('NZD', $invoice->getPrice()->getCurrency());
    }

    /**
     * Tests that setting a Price writes the amount and currency back.
     */
    public function testSetPriceUpdatesAmountAndCurrency(): void
    {
        $invoice = new Invoice();
        $invoice->setPrice(Price::create('45.10', 'EUR'));

        self::assertSame(45.10, $invoice->getAmount());
        self::assertSame('EUR', $invoice->getCurrency());
    }

    /**
     * Tests that preSave stamps createdAt once and refreshes updatedAt every time.
     */
    public function testPreSaveStampsDates(): void
    {
        $invoice = new Invoice();
        $invoice->setAmount(10.00);
        $invoice->setCurrency('USD');

        $invoice->preSave();

        $createdAt = $invoice->getCreatedAt();
        self::assertInstanceOf(\DateTimeInterface::class, $createdAt);
        self::assertInstanceOf(\DateTimeInterface::class, $invoice->getUpdatedAt());

        $invoice->preSave();

        self::assertSame($createdAt, $invoice->getCreatedAt(), 'createdAt must not move on update');
        self::assertSame(10.00, $invoice->getAmount(), 'preSave must not disturb the amount');
    }

    /**
     * Tests that line items are added and linked back to the invoice.
     */
    public function testInvoiceLineItems(): void
    {
        /** @var Invoice $invoice */
        $invoice = $this->getEntity(Invoice::class, ['id' => 1]);

        /** @var InvoiceLineItem $first */
        $first = $this->getEntity(InvoiceLineItem::class, [
            'id' => 11,
            'summary' => 'Line Item A',
            'value' => 101.11,
            'currency' => 'USD',
        ]);

        /** @var InvoiceLineItem $second */
        $second = $this->getEntity(InvoiceLineItem::class, [
            'id' => 12,
            'summary' => 'Line Item B',
            'value' => 51.23,
            'currency' => 'USD',
        ]);

        self::assertEmpty($invoice->getLineItems());
        self::assertFalse($invoice->hasLineItem($first));

        $invoice->addLineItem($first);
        $invoice->addLineItem($second);
        $invoice->addLineItem($first);

        self::assertCount(2, $invoice->getLineItems(), 'Adding the same line item twice must be a no-op');
        self::assertTrue($invoice->hasLineItem($first));

        $lineItems = $invoice->getLineItems();

        self::assertContainsOnlyInstancesOf(InvoiceLineItem::class, $lineItems);
        self::assertEquals($invoice->getId(), $first->getInvoice()->getId());
        self::assertEquals($first, $lineItems->first());

        self::assertInstanceOf(Price::class, $lineItems->first()->getPrice());
        self::assertEquals(101.11, $lineItems->first()->getPrice()->getValue());
        self::assertEquals('USD', $lineItems->first()->getPrice()->getCurrency());

        $invoice->removeLineItem($first);

        self::assertCount(1, $invoice->getLineItems());
        self::assertFalse($invoice->hasLineItem($first));
    }

    /**
     * Tests that payments are added once and linked back to the invoice.
     */
    public function testInvoicePayments(): void
    {
        $invoice = new Invoice();
        $payment = new InvoicePayment();

        $invoice->addInvoicePayment($payment);
        $invoice->addInvoicePayment($payment);

        self::assertCount(1, $invoice->getInvoicePayments());
        self::assertSame($invoice, $payment->getInvoice());

        $invoice->removeInvoicePayment($payment);

        self::assertCount(0, $invoice->getInvoicePayments());
    }

    /**
     * Tests that the balance and paid state derive from amount and amountPaid.
     *
     * @param float $amount
     * @param float $amountPaid
     * @param float $expectedBalance
     * @param bool $expectedPaidState
     * @dataProvider invoiceBalancesDataProvider
     */
    #[DataProvider('invoiceBalancesDataProvider')]
    public function testInvoiceBalances(
        float $amount,
        float $amountPaid,
        float $expectedBalance,
        bool $expectedPaidState
    ): void {
        /** @var Invoice $invoice */
        $invoice = $this->getEntity(Invoice::class, [
            'amount' => $amount,
            'amountPaid' => $amountPaid,
        ]);

        self::assertEquals($expectedBalance, $invoice->getBalance());
        self::assertEquals($expectedPaidState, $invoice->isBalancePaid());
    }

    /**
     * Provides the data sets for invoice balances data.
     *
     * @return \Generator<string, array<string, float|bool>>
     */
    public static function invoiceBalancesDataProvider(): \Generator
    {
        // Keys match the test method's parameter names: PHPUnit passes
        // string-keyed data sets as named arguments on newer versions.
        yield 'Unpaid Invoice' => [
            'amount' => 123.45,
            'amountPaid' => 0.00,
            'expectedBalance' => 123.45,
            'expectedPaidState' => false,
        ];

        yield 'Partially Paid Invoice' => [
            'amount' => 45.67,
            'amountPaid' => 23.21,
            'expectedBalance' => 22.46,
            'expectedPaidState' => false,
        ];

        yield 'Fully Paid Invoice' => [
            'amount' => 987.65,
            'amountPaid' => 987.6500,
            'expectedBalance' => 0.00,
            'expectedPaidState' => true,
        ];

        yield 'Overpaid Invoice' => [
            'amount' => 12123.51,
            'amountPaid' => 12197.65,
            'expectedBalance' => -74.14,
            'expectedPaidState' => true,
        ];
    }

    /**
     * Tests that the posted flag follows postedAt.
     */
    public function testPostedFlagFollowsPostedAt(): void
    {
        $invoice = new Invoice();

        self::assertFalse($invoice->isPosted());

        $invoice->setPostedAt(new \DateTime());

        self::assertTrue($invoice->isPosted());
    }

    /**
     * Tests that the paid-notification guard follows its timestamp.
     */
    public function testPaidNotificationFlagFollowsSentAt(): void
    {
        $invoice = new Invoice();

        self::assertFalse($invoice->isPaidNotificationSent());

        $invoice->setPaidNotificationSentAt(new \DateTime());

        self::assertTrue($invoice->isPaidNotificationSent());

        $invoice->setPaidNotificationSentAt(null);

        self::assertFalse($invoice->isPaidNotificationSent());
    }

    /**
     * Tests that a cloned invoice loses its identity so it can be persisted as new.
     */
    public function testCloneResetsId(): void
    {
        /** @var Invoice $invoice */
        $invoice = $this->getEntity(Invoice::class, ['id' => 42, 'invoiceNo' => 'INV-2026-09-00001']);

        $copy = clone $invoice;

        self::assertNull($copy->getId());
        self::assertSame(42, $invoice->getId());
        self::assertSame('INV-2026-09-00001', $copy->getInvoiceNo());
    }

    /**
     * Tests the full-name helpers, which return null rather than an empty string.
     */
    public function testAddressFullNames(): void
    {
        $invoice = new Invoice();

        self::assertNull($invoice->getBillingAddressFullName());
        self::assertNull($invoice->getShippingAddressFullName());

        $invoice->setBillingAddressFirstName('John');
        $invoice->setShippingAddressLastName('Smith');

        self::assertSame('John', $invoice->getBillingAddressFullName());
        self::assertSame('Smith', $invoice->getShippingAddressFullName());
    }

    /**
     * Tests that a complete billing address renders in the documented order.
     */
    public function testBillingAddressLinesForACompleteAddress(): void
    {
        $invoice = (new Invoice())
            ->setBillingAddressFirstName('John')
            ->setBillingAddressLastName('Smith')
            ->setBillingAddressOrganization('ABC Manufacturing')
            ->setBillingAddressStreet1('123 Main Street')
            ->setBillingAddressStreet2('Suite 100')
            ->setBillingAddressCity('Los Angeles')
            ->setBillingAddressState('CA')
            ->setBillingAddressPostalCode('90001')
            ->setBillingAddressCountry('US')
            ->setBillingAddressPhone('+1 213-555-0123');

        self::assertSame(
            [
                'John Smith',
                'ABC Manufacturing',
                '123 Main Street',
                'Suite 100',
                'Los Angeles, CA 90001',
                'US',
                '+1 213-555-0123',
            ],
            $invoice->getBillingAddressLines()
        );
    }

    /**
     * Tests that the city line never carries a dangling comma or stray spaces.
     *
     * @param string|null $city
     * @param string|null $state
     * @param string|null $postalCode
     * @param array $expectedLines
     * @dataProvider cityLineDataProvider
     */
    #[DataProvider('cityLineDataProvider')]
    public function testShippingAddressCityLine(
        ?string $city,
        ?string $state,
        ?string $postalCode,
        array $expectedLines
    ): void {
        $invoice = (new Invoice())
            ->setShippingAddressCity($city)
            ->setShippingAddressState($state)
            ->setShippingAddressPostalCode($postalCode);

        self::assertSame($expectedLines, $invoice->getShippingAddressLines());
    }

    /**
     * Provides the data sets for city line data.
     *
     * @return \Generator<string, array<string, string|array<int, string>|null>>
     */
    public static function cityLineDataProvider(): \Generator
    {
        yield 'Nothing set' => [
            'city' => null,
            'state' => null,
            'postalCode' => null,
            'expectedLines' => [],
        ];

        yield 'City and postal code only' => [
            'city' => 'Wellington',
            'state' => null,
            'postalCode' => '6011',
            'expectedLines' => ['Wellington 6011'],
        ];

        yield 'State only' => [
            'city' => null,
            'state' => 'CA',
            'postalCode' => null,
            'expectedLines' => ['CA'],
        ];

        yield 'City and state' => [
            'city' => 'Austin',
            'state' => 'TX',
            'postalCode' => null,
            'expectedLines' => ['Austin, TX'],
        ];

        yield 'Postal code only' => [
            'city' => '',
            'state' => '',
            'postalCode' => '2000',
            'expectedLines' => ['2000'],
        ];
    }

    /**
     * Tests the rule the Cancel operation's precondition reads as
     * $.data.cancellable, and that InvoiceCancellationManager enforces.
     */
    public function testIsCancellable(): void
    {
        $statuses = [Invoice::STATUS_DRAFT, Invoice::STATUS_POSTED, Invoice::STATUS_OPEN, Invoice::STATUS_OVERDUE];
        foreach ($statuses as $status) {
            $invoice = new Invoice();
            $invoice->setStatus($status);

            self::assertTrue($invoice->isCancellable(), sprintf('A %s invoice should be cancellable.', $status));
        }
    }

    /**
     * Tests that an already cancelled invoice cannot be cancelled again.
     */
    public function testACancelledInvoiceIsNotCancellable(): void
    {
        $invoice = new Invoice();
        $invoice->setStatus(Invoice::STATUS_CANCELLED);

        self::assertFalse($invoice->isCancellable());
    }

    /**
     * Tests that money recorded against an invoice no longer rules cancellation out.
     */
    public function testAnInvoiceWithPaymentsIsStillCancellable(): void
    {
        $paid = new Invoice();
        $paid->setStatus(Invoice::STATUS_PAID);
        $paid->setAmountPaid(100.0);
        self::assertTrue($paid->isCancellable());

        $partiallyPaid = new Invoice();
        $partiallyPaid->setStatus(Invoice::STATUS_PARTIALLY_PAID);
        $partiallyPaid->setAmountPaid(25.0);
        self::assertTrue($partiallyPaid->isCancellable());
    }
}
