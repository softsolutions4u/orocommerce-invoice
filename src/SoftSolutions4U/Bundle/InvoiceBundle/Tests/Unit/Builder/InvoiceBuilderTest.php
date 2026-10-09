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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Builder;

use SoftSolutions4U\Bundle\InvoiceBundle\Builder\InvoiceBuilder;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Generator\InvoiceNumberGenerator;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceLineItemManager;
use Oro\Bundle\AddressBundle\Entity\Country;
use Oro\Bundle\AddressBundle\Entity\Region;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderAddress;
use Oro\Bundle\PaymentBundle\Entity\PaymentStatus;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use Oro\Bundle\PaymentBundle\Manager\PaymentStatusManager;
use Oro\Bundle\PaymentBundle\Provider\PaymentTransactionProvider;
use Oro\Bundle\TaxBundle\Model\Result;
use Oro\Bundle\TaxBundle\Model\ResultElement;
use Oro\Bundle\TaxBundle\Provider\TaxProviderInterface;
use Oro\Bundle\TaxBundle\Provider\TaxProviderRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Oro\Bundle\PaymentTermBundle\Provider\PaymentTermProviderInterface;

/**
 * Unit tests for building a draft invoice from an order.
 */
class InvoiceBuilderTest extends TestCase
{
    private InvoiceLineItemManager&MockObject $lineItemManager;
    private PaymentTransactionProvider&MockObject $transactionProvider;
    private PaymentStatusManager&MockObject $paymentStatusManager;

    /** @var ResultElement $shippingTax */
    private ResultElement $shippingTax;

    /** @var InvoiceBuilder $builder */
    private InvoiceBuilder $builder;
    private PaymentTermProviderInterface&MockObject $paymentTermProvider;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $numberGenerator = $this->createMock(
            InvoiceNumberGenerator::class
        );
        $numberGenerator->method('generate')->willReturn('INV-2026-09-00019');

        $this->lineItemManager = $this->createMock(InvoiceLineItemManager::class);
        $this->lineItemManager->method('getOrderLevelDiscount')->willReturn(5.0);
        // Stand-in for the totals calculation: the invoice total is the order subtotal.
        $this->lineItemManager->method('recalculateInvoiceTotals')->willReturnCallback(
            static function (Invoice $invoice): void {
                $invoice->setAmount(50.48);
            }
        );

        $this->shippingTax = ResultElement::create('19.80', '18.00', '1.80');
        // Result is final, so a real one is used instead of a mock.
        $taxResult = new Result();
        $shippingKey = defined(Result::class . '::SHIPPING') ? constant(Result::class . '::SHIPPING') : 'shipping';
        $taxResult->offsetSet($shippingKey, $this->shippingTax);
        self::assertSame(
            $this->shippingTax,
            $taxResult->getShipping(),
            'Tax result fixture holds the shipping element'
        );

        $taxProvider = $this->createMock(
            TaxProviderInterface::class
        );
        $taxProvider->method('getTax')->willReturn($taxResult);

        $taxRegistry = $this->createMock(
            TaxProviderRegistry::class
        );
        $taxRegistry->method('getEnabledProvider')->willReturn($taxProvider);

        $this->transactionProvider = $this->createMock(PaymentTransactionProvider::class);
        $this->paymentStatusManager = $this->createMock(PaymentStatusManager::class);
        $this->paymentTermProvider = $this->createMock(PaymentTermProviderInterface::class);

        $this->builder = new InvoiceBuilder(
            $numberGenerator,
            $this->lineItemManager,
            $taxRegistry,
            $this->transactionProvider,
            $this->paymentStatusManager,
            $this->paymentTermProvider,
        );
    }

    /**
     * Tests the invoice header copied from the order.
     */
    public function testCopiesTheOrderHeader(): void
    {
        $order = $this->order();

        $invoice = $this->builder->build($order);

        self::assertSame($order, $invoice->getOrder());
        self::assertSame($order->getCustomer(), $invoice->getCustomer());
        self::assertSame($order->getCustomerUser(), $invoice->getCustomerUser());
        self::assertSame('USD', $invoice->getCurrency());
        self::assertSame('PO-7781', $invoice->getPoNumber());
        self::assertSame('INV-2026-09-00019', $invoice->getInvoiceNo());
        self::assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus(), 'A new invoice is always a draft');
    }

    /**
     * Tests that the invoice is issued today and due in 30 days.
     */
    public function testIssuedTodayDueInThirtyDays(): void
    {
        $invoice = $this->builder->build($this->order());

        $today = new \DateTime('today');
        self::assertSame($today->format('Y-m-d'), $invoice->getIssueDate()->format('Y-m-d'));
        self::assertSame(
            (clone $today)->modify('+30 days')->format('Y-m-d'),
            $invoice->getDueDate()->format('Y-m-d')
        );
    }

    /**
     * Tests that billing and shipping addresses are copied field by field.
     */
    public function testCopiesAddresses(): void
    {
        $invoice = $this->builder->build($this->order());

        self::assertSame(
            [
                'John Smith',
                'ABC Manufacturing',
                '123 Main Street',
                'Suite 100',
                'Los Angeles, California 90001',
                'US',
                '2135550123',
            ],
            $invoice->getBillingAddressLines()
        );
        self::assertSame('Austin, TX 73301', $invoice->getShippingAddressLines()[1] ?? null);
        self::assertSame(
            'TX',
            $invoice->getShippingAddressState(),
            'Free-text region is used when there is no region entity'
        );
    }

    /**
     * Tests that an order without addresses still builds.
     */
    public function testOrderWithoutAddresses(): void
    {
        $order = $this->order();
        $order->setBillingAddress(null);
        $order->setShippingAddress(null);

        $invoice = $this->builder->build($order);

        self::assertSame([], $invoice->getBillingAddressLines());
        self::assertSame([], $invoice->getShippingAddressLines());
    }

    /**
     * Tests that shipping comes from the tax result and totals include the order-level discount.
     */
    public function testShippingAndTotals(): void
    {
        $order = $this->order();

        $this->lineItemManager->expects(self::once())
            ->method('copyFromOrder')
            ->with(self::isInstanceOf(Invoice::class), $order, self::isInstanceOf(Result::class));
        $this->lineItemManager->expects(self::once())
            ->method('recalculateInvoiceTotals')
            ->with(self::isInstanceOf(Invoice::class), $this->shippingTax, 5.0);

        $invoice = $this->builder->build($order);

        self::assertSame(18.0, $invoice->getShippingAmount(), 'Shipping is taken excluding tax');
    }

    /**
     * Tests that the order's payment method and status are carried over.
     */
    public function testCarriesPaymentMethodAndStatus(): void
    {
        $transaction = new PaymentTransaction();
        $transaction->setPaymentMethod('payment_term_1');
        $this->transactionProvider->method('getPaymentTransaction')->willReturn($transaction);
        $this->paymentStatusManager->method('getPaymentStatus')->willReturn($this->status('pending'));

        $invoice = $this->builder->build($this->order());

        self::assertSame('payment_term_1', $invoice->getPaymentMethod());
        self::assertSame('pending', $invoice->getPaymentStatus());
        self::assertSame(0.0, $invoice->getAmountPaid());
    }

    /**
     * Tests that an order already paid in full produces an invoice with nothing left to pay.
     */
    public function testFullyPaidOrderIsPaidOnTheInvoice(): void
    {
        $this->paymentStatusManager->method('getPaymentStatus')->willReturn($this->status('full'));

        $invoice = $this->builder->build($this->order());

        self::assertSame(50.48, $invoice->getAmountPaid());
        self::assertTrue($invoice->isBalancePaid());
    }

    /**
     * Tests that an order with no payment yet builds as unpaid with no payment method.
     *
     * PaymentStatusManager::getPaymentStatus() never returns null: for an order
     * without payments it returns a status of "pending".
     */
    public function testOrderWithoutPayment(): void
    {
        $this->transactionProvider->method('getPaymentTransaction')->willReturn(null);
        $this->paymentStatusManager->method('getPaymentStatus')->willReturn($this->status('pending'));

        $invoice = $this->builder->build($this->order());

        self::assertNull($invoice->getPaymentMethod());
        self::assertSame('pending', $invoice->getPaymentStatus());
        self::assertSame(0.0, $invoice->getAmountPaid());
        self::assertFalse($invoice->isBalancePaid());
    }

    /**
     * Returns the status.
     *
     * @param string $code
     * @return PaymentStatus
     */
    private function status(string $code): PaymentStatus
    {
        $status = $this->createStub(PaymentStatus::class);
        $status->method('getPaymentStatus')->willReturn($code);

        return $status;
    }

    /**
     * Returns the order.
     *
     * @return Order
     */
    private function order(): Order
    {
        $customer = new Customer();
        $customerUser = new CustomerUser();
        $customerUser->setCustomer($customer);

        $region = new Region('US-CA');
        $region->setName('California');

        $billing = new OrderAddress();
        $billing->setFirstName('John');
        $billing->setLastName('Smith');
        $billing->setOrganization('ABC Manufacturing');
        $billing->setStreet('123 Main Street');
        $billing->setStreet2('Suite 100');
        $billing->setCity('Los Angeles');
        $billing->setRegion($region);
        $billing->setPostalCode('90001');
        $billing->setCountry(new Country('US'));
        $billing->setPhone('2135550123');

        $shipping = new OrderAddress();
        $shipping->setFirstName('Jane');
        $shipping->setCity('Austin');
        $shipping->setRegionText('TX');
        $shipping->setPostalCode('73301');

        $order = new Order();
        $order->setCustomer($customer);
        $order->setCustomerUser($customerUser);
        $order->setCurrency('USD');
        $order->setPoNumber('PO-7781');
        $order->setBillingAddress($billing);
        $order->setShippingAddress($shipping);

        return $order;
    }
}
