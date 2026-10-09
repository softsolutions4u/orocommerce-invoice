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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\OrderPaymentTransactionSyncGuard;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\InvoicePaymentFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoicePaymentCompletionManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\OrderPaymentTransactionSynchronizer;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;

/**
 * Unit tests for the edge cases of OrderPaymentTransactionSynchronizer.
 *
 * The main recording and mirroring flows are covered through OrderPaymentTransactionListenerTest.
 */
class OrderPaymentTransactionSynchronizerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private ManagerRegistry&MockObject $registry;
    private LoggerInterface&MockObject $logger;
    private InvoicePaidAmountProvider&MockObject $paidAmountProvider;
    private InvoicePaymentCompletionManager&MockObject $completionManager;

    /** @var OrderPaymentTransactionSyncGuard $syncGuard */
    private OrderPaymentTransactionSyncGuard $syncGuard;

    /** @var OrderPaymentTransactionSynchronizer $synchronizer */
    private OrderPaymentTransactionSynchronizer $synchronizer;

    /** @var array<int, Order> */
    private array $orders = [];

    /** @var array<int, array<int, Invoice>> orderId => invoices */
    private array $invoicesByOrder = [];

    /** @var array<int, InvoicePayment> */
    private array $paymentsById = [];

    /** @var array<int, object> */
    private array $persisted = [];

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('find')->willReturnCallback(fn ($id) => $this->orders[(int) $id] ?? null);

        $invoiceRepository = $this->createMock(EntityRepository::class);
        $invoiceRepository->method('findBy')->willReturnCallback(
            fn (array $criteria) => $this->invoicesByOrder[(int) $criteria['order']->getId()] ?? []
        );

        $paymentRepository = $this->createMock(EntityRepository::class);
        $paymentRepository->method('findOneBy')->willReturn(null);
        $paymentRepository->method('find')->willReturnCallback(fn ($id) => $this->paymentsById[(int) $id] ?? null);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class) => match ($class) {
                Order::class => $orderRepository,
                Invoice::class => $invoiceRepository,
                InvoicePayment::class => $paymentRepository,
            }
        );
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->paidAmountProvider = $this->createMock(InvoicePaidAmountProvider::class);
        $this->paidAmountProvider->method('getRemainingBalance')->willReturnCallback(
            static fn (Invoice $invoice): float => max(0.0, $invoice->getAmount() - $invoice->getAmountPaid())
        );
        $this->completionManager = $this->createMock(InvoicePaymentCompletionManager::class);
        $this->syncGuard = new OrderPaymentTransactionSyncGuard();

        $this->synchronizer = $this->createSynchronizer($this->registry);
    }

    /**
     * Tests which transactions are relevant for the invoice ledger.
     *
     * @param string $entityClass
     * @param string $action
     * @param bool $successful
     * @param bool $expected
     * @dataProvider relevanceDataProvider
     */
    #[DataProvider('relevanceDataProvider')]
    public function testIsRelevant(string $entityClass, string $action, bool $successful, bool $expected): void
    {
        $transaction = $this->transaction(1, 1, $action, '10.00', $successful);
        $transaction->setEntityClass($entityClass);

        self::assertSame($expected, $this->synchronizer->isRelevant($transaction));
    }

    /**
     * Provides the data sets for testIsRelevant.
     *
     * @return array<string, array{string, string, bool, bool}>
     */
    public static function relevanceDataProvider(): array
    {
        return [
            'order capture' => [Order::class, 'capture', true, true],
            'order purchase' => [Order::class, 'purchase', true, true],
            'invoice payment charge' => [InvoicePayment::class, 'charge', true, true],
            'failed capture' => [Order::class, 'capture', false, false],
            'authorize only' => [Order::class, 'authorize', true, false],
            'other entity' => [Customer::class, 'capture', true, false],
        ];
    }

    /**
     * Tests that nothing happens when no ORM entity manager is configured for invoices.
     */
    public function testProcessWithoutEntityManagerDoesNothing(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $this->completionManager->expects(self::never())->method('onPaymentApplied');

        $this->createSynchronizer($registry)->process([$this->transaction(900, 101, 'capture', '10.00')]);
    }

    /**
     * Tests that an empty queue does not flush.
     */
    public function testEmptyQueueDoesNotFlush(): void
    {
        $this->entityManager->expects(self::never())->method('flush');

        $this->synchronizer->process([]);
    }

    /**
     * Tests that a transaction without an order id is skipped.
     */
    public function testTransactionWithoutOrderIdIsSkipped(): void
    {
        $transaction = new PaymentTransaction();
        $transaction->setEntityClass(Order::class);
        $transaction->setAction('capture');
        $transaction->setAmount('10.00');
        $transaction->setSuccessful(true);

        $this->entityManager->expects(self::never())->method('flush');

        $this->synchronizer->process([$transaction]);
    }

    /**
     * Tests that a transaction for a missing order is logged and skipped.
     */
    public function testMissingOrderIsLogged(): void
    {
        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('was not found'));
        $this->entityManager->expects(self::never())->method('flush');

        $this->synchronizer->process([$this->transaction(900, 404, 'capture', '10.00')]);
    }

    /**
     * Tests that an order with only cancelled invoices is not credited.
     */
    public function testOrderWithOnlyCancelledInvoicesIsNotCredited(): void
    {
        $invoice = $this->orderWithInvoice(101, 100.0);
        $invoice->setStatus(Invoice::STATUS_CANCELLED);

        $this->logger->expects(self::atLeastOnce())->method('info')->with(self::stringContains('only cancelled'));
        $this->entityManager->expects(self::never())->method('flush');

        $this->synchronizer->process([$this->transaction(900, 101, 'capture', '100.00')]);

        self::assertSame([], $this->persistedPayments());
    }

    /**
     * Tests that the first invoice that is not cancelled is credited.
     */
    public function testFirstInvoiceThatIsNotCancelledIsCredited(): void
    {
        $cancelled = $this->orderWithInvoice(101, 100.0);
        $cancelled->setStatus(Invoice::STATUS_CANCELLED);

        $replacement = new Invoice();
        $replacement->setOrder($this->orders[101]);
        $replacement->setCurrency('USD');
        $replacement->setAmount(100.0);
        (new \ReflectionProperty($replacement, 'id'))->setValue($replacement, 2000);
        $this->invoicesByOrder[101][] = $replacement;

        $this->completionManager->expects(self::once())->method('onPaymentApplied')->with($replacement);

        $this->synchronizer->process([$this->transaction(900, 101, 'capture', '30.00')]);

        $payments = $this->persistedPayments();
        self::assertCount(1, $payments);
        self::assertSame($replacement, $payments[0]->getInvoice());
    }

    /**
     * Tests that a zero-amount capture is ignored.
     */
    public function testZeroAmountCaptureIsIgnored(): void
    {
        $this->orderWithInvoice(101, 100.0);

        $this->entityManager->expects(self::never())->method('flush');

        $this->synchronizer->process([$this->transaction(900, 101, 'capture', '0.00')]);

        self::assertSame([], $this->persistedPayments());
    }

    /**
     * Tests that the invoice organization is copied onto the recorded payment.
     */
    public function testRecordedPaymentTakesTheInvoiceOrganization(): void
    {
        $invoice = $this->orderWithInvoice(101, 100.0);
        $organization = new Organization();
        $invoice->setOrganization($organization);

        $this->synchronizer->process([$this->transaction(900, 101, 'capture', '25.00')]);

        $payments = $this->persistedPayments();
        self::assertCount(1, $payments);
        self::assertSame($organization, $payments[0]->getOrganization());
        self::assertFalse($payments[0]->isActive());
        self::assertSame(900, $payments[0]->getPaymentTransactionId());
    }

    /**
     * Tests that a claimed invoice payment keeps an already linked transaction id.
     */
    public function testClaimedPaymentKeepsItsExistingTransactionId(): void
    {
        $this->orderWithInvoice(101, 100.0);

        $payment = new InvoicePayment();
        $payment->setPaymentTransactionId(555);
        $transaction = $this->transaction(900, 101, 'capture', '40.00');
        $this->syncGuard->claim($transaction, $payment);

        $this->synchronizer->process([$transaction]);

        self::assertSame(555, $payment->getPaymentTransactionId());
        self::assertNull($this->syncGuard->getClaimedInvoicePayment($transaction));
        self::assertSame([], $this->persistedPayments());
    }

    /**
     * Tests that a transaction for an unknown invoice payment is skipped.
     */
    public function testUnknownInvoicePaymentIsSkipped(): void
    {
        $transaction = $this->transaction(950, 77, 'capture', '40.00');
        $transaction->setEntityClass(InvoicePayment::class);

        $this->entityManager->expects(self::never())->method('flush');

        $this->synchronizer->process([$transaction]);
    }

    /**
     * Tests that a payment covering two invoices is not pinned to one and recalculates both.
     */
    public function testPaymentForTwoInvoicesRecalculatesBoth(): void
    {
        $first = $this->orderWithInvoice(101, 100.0);
        $second = $this->orderWithInvoice(102, 50.0);

        $payment = new InvoicePayment();
        $payment->setActive(false);
        $payment->addLineItem((new InvoicePaymentLineItem())->setInvoice($first)->setAmount(10.0));
        $payment->addLineItem((new InvoicePaymentLineItem())->setInvoice($second)->setAmount(5.0));
        $this->paymentsById[7] = $payment;

        $transaction = $this->transaction(950, 7, 'capture', '15.00');
        $transaction->setEntityClass(InvoicePayment::class);

        $this->paidAmountProvider->expects(self::exactly(2))->method('applyTo');

        $this->synchronizer->process([$transaction]);

        self::assertNull($payment->getInvoice());
        self::assertSame(950, $payment->getPaymentTransactionId());
    }

    /**
     * Tests that line items that cannot be mirrored to an order are skipped, and the organization is copied.
     */
    public function testMirrorSkipsLineItemsWithoutAnOrderOrAmount(): void
    {
        $mirrored = $this->orderWithInvoice(101, 100.0);
        $organization = new Organization();
        $this->orders[101]->setOrganization($organization);

        $withoutOrder = new Invoice();
        (new \ReflectionProperty($withoutOrder, 'id'))->setValue($withoutOrder, 3001);

        $unsavedOrder = new Invoice();
        $unsavedOrder->setOrder(new Order());
        (new \ReflectionProperty($unsavedOrder, 'id'))->setValue($unsavedOrder, 3002);

        $payment = new InvoicePayment();
        $payment->setActive(true)->setPaymentMethod('credit_card_3');
        $payment->addLineItem((new InvoicePaymentLineItem())->setInvoice($mirrored)->setAmount(40.0));
        $payment->addLineItem((new InvoicePaymentLineItem())->setInvoice($mirrored)->setAmount(0.0));
        $payment->addLineItem((new InvoicePaymentLineItem())->setInvoice($withoutOrder)->setAmount(5.0));
        $payment->addLineItem((new InvoicePaymentLineItem())->setInvoice($unsavedOrder)->setAmount(5.0));
        $this->paymentsById[7] = $payment;

        $transaction = $this->transaction(950, 7, 'capture', '40.00');
        $transaction->setEntityClass(InvoicePayment::class);

        $this->synchronizer->process([$transaction]);

        $mirrors = array_values(array_filter(
            $this->persisted,
            static fn (object $entity): bool => $entity instanceof PaymentTransaction
        ));
        self::assertCount(1, $mirrors);
        self::assertFalse($payment->isActive());

        if (method_exists($mirrors[0], 'getOrganization')) {
            self::assertSame($organization, $mirrors[0]->getOrganization());
        }
    }

    /**
     * Creates the synchronizer with the given registry.
     *
     * @param ManagerRegistry $registry
     * @return OrderPaymentTransactionSynchronizer
     */
    private function createSynchronizer(ManagerRegistry $registry): OrderPaymentTransactionSynchronizer
    {
        $factory = new InvoicePaymentFactory(
            $this->getMockBuilder(TokenAccessor::class)->disableOriginalConstructor()->getMock(),
            $registry
        );

        return new OrderPaymentTransactionSynchronizer(
            $registry,
            $this->logger,
            $factory,
            $this->paidAmountProvider,
            $this->syncGuard,
            $this->completionManager
        );
    }

    /**
     * Returns a payment transaction for an order.
     *
     * @param int $id
     * @param int $entityId
     * @param string $action
     * @param string $amount
     * @param bool $successful
     * @return PaymentTransaction
     */
    private function transaction(
        int $id,
        int $entityId,
        string $action,
        string $amount,
        bool $successful = true
    ): PaymentTransaction {
        $transaction = new PaymentTransaction();
        $transaction->setEntityClass(Order::class);
        $transaction->setEntityIdentifier($entityId);
        $transaction->setAction($action);
        $transaction->setAmount($amount);
        $transaction->setCurrency('USD');
        $transaction->setPaymentMethod('credit_card_3');
        $transaction->setSuccessful($successful);
        (new \ReflectionProperty($transaction, 'id'))->setValue($transaction, $id);

        return $transaction;
    }

    /**
     * Creates an order with one invoice and returns the invoice.
     *
     * @param int $orderId
     * @param float $amount
     * @return Invoice
     */
    private function orderWithInvoice(int $orderId, float $amount): Invoice
    {
        $order = new Order();
        $class = new \ReflectionClass($order);
        while ($class && !$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }
        $class->getProperty('id')->setValue($order, $orderId);

        $customer = new Customer();
        $customerUser = new CustomerUser();
        $customerUser->setCustomer($customer);
        $order->setCustomer($customer);
        $order->setCustomerUser($customerUser);
        $this->orders[$orderId] = $order;

        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setCurrency('USD');
        $invoice->setAmount($amount);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, $orderId + 1000);

        $this->invoicesByOrder[$orderId] = [$invoice];

        return $invoice;
    }

    /**
     * Returns the invoice payments persisted by the synchronizer.
     *
     * @return array<int, InvoicePayment>
     */
    private function persistedPayments(): array
    {
        return array_values(array_filter(
            $this->persisted,
            static fn (object $entity): bool => $entity instanceof InvoicePayment
        ));
    }
}
