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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\EventListener;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\OrderPaymentTransactionListener;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\OrderPaymentTransactionSynchronizer;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\OrderPaymentTransactionSyncGuard;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\InvoicePaymentFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoicePaymentCompletionManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for mirroring payment transactions into the invoice payment ledger.
 *
 * Covers both directions: a capture taken on an Order is recorded against its
 * invoice, and a storefront invoice payment is marked processed and mirrored
 * onto the Order - without either side ever recording the same money twice.
 */
class OrderPaymentTransactionListenerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private LoggerInterface&MockObject $logger;
    private InvoicePaidAmountProvider&MockObject $paidAmountProvider;
    private InvoicePaymentCompletionManager&MockObject $completionManager;

    /** @var OrderPaymentTransactionSyncGuard $syncGuard */
    private OrderPaymentTransactionSyncGuard $syncGuard;

    /** @var OrderPaymentTransactionListener $listener */
    private OrderPaymentTransactionListener $listener;

    /** @var array<int, Order> */
    private array $orders = [];

    /** @var array<int, array<int, Invoice>> orderId => invoices */
    private array $invoicesByOrder = [];

    /** @var array<int, InvoicePayment> transactionId => payment already in the ledger */
    private array $paymentsByTransactionId = [];

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
        $paymentRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria) => $this->paymentsByTransactionId[$criteria['paymentTransactionId']] ?? null
        );
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

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->paidAmountProvider = $this->createMock(InvoicePaidAmountProvider::class);
        $this->paidAmountProvider->method('getRemainingBalance')->willReturnCallback(
            static fn (Invoice $invoice): float => max(0.0, $invoice->getAmount() - $invoice->getAmountPaid())
        );
        $this->completionManager = $this->createMock(InvoicePaymentCompletionManager::class);
        $this->syncGuard = new OrderPaymentTransactionSyncGuard();

        // The real factory: only createInvoicePaymentLineItem() is used, which needs no collaborators.
        $factory = new InvoicePaymentFactory(
            $this->getMockBuilder(TokenAccessor::class)->disableOriginalConstructor()->getMock(),
            $registry
        );

        $this->listener = new OrderPaymentTransactionListener(
            $this->syncGuard,
            new OrderPaymentTransactionSynchronizer(
                $registry,
                $this->logger,
                $factory,
                $this->paidAmountProvider,
                $this->syncGuard,
                $this->completionManager
            )
        );
    }

    /**
     * Tests that a capture on an order is recorded on its invoice as a processed payment.
     */
    public function testOrderCaptureIsRecordedOnTheInvoice(): void
    {
        $invoice = $this->orderWithInvoice(101, 100.0);

        $this->paidAmountProvider->expects(self::once())->method('applyTo')->with($invoice);
        $this->completionManager->expects(self::once())->method('onPaymentApplied')->with($invoice);

        $this->persistAndFlush($this->orderTransaction(900, 101, 'capture', '100.00'));

        $payments = $this->persistedPayments();
        self::assertCount(1, $payments);
        self::assertFalse($payments[0]->isActive());
        self::assertSame(900, $payments[0]->getPaymentTransactionId());
        self::assertSame($invoice, $payments[0]->getInvoice());
        self::assertSame('credit_card_3', $payments[0]->getPaymentMethod());
        self::assertEquals(100.0, $payments[0]->getAmount());
        self::assertSame('USD', $payments[0]->getCurrency());
    }

    /**
     * Tests that nothing beyond the invoice balance is recorded.
     */
    public function testRecordsAtMostTheRemainingBalance(): void
    {
        $invoice = $this->orderWithInvoice(101, 100.0);
        $invoice->setAmountPaid(70.0);

        $this->persistAndFlush($this->orderTransaction(900, 101, 'capture', '100.00'));

        self::assertEquals(30.0, $this->persistedPayments()[0]->getAmount());
    }

    /**
     * Tests that an invoice already paid in full gets no further ledger entry.
     */
    public function testNothingRecordedOnAPaidInvoice(): void
    {
        $invoice = $this->orderWithInvoice(101, 100.0);
        $invoice->setAmountPaid(100.0);

        $this->persistAndFlush($this->orderTransaction(900, 101, 'capture', '100.00'));

        self::assertSame([], $this->persistedPayments());
    }

    /**
     * Tests that a transaction already in the ledger is not recorded twice.
     */
    public function testTransactionAlreadyInTheLedgerIsNotDuplicated(): void
    {
        $this->orderWithInvoice(101, 100.0);
        $this->paymentsByTransactionId[900] = new InvoicePayment();

        $this->persistAndFlush($this->orderTransaction(900, 101, 'capture', '100.00'));

        self::assertSame([], $this->persistedPayments());
    }

    /**
     * Tests that an order transaction created for a storefront payment is linked, not recorded twice.
     */
    public function testClaimedTransactionIsLinkedNotDuplicated(): void
    {
        $this->orderWithInvoice(101, 100.0);
        $storefrontPayment = new InvoicePayment();

        $transaction = $this->orderTransaction(900, 101, 'capture', '40.00');
        $this->syncGuard->claim($transaction, $storefrontPayment);

        $this->persistAndFlush($transaction);

        self::assertSame(900, $storefrontPayment->getPaymentTransactionId());
        self::assertFalse($this->syncGuard->isClaimed($transaction), 'The claim is released once linked');
        self::assertSame([$storefrontPayment], $this->persistedPayments(), 'Only the link is saved');
    }

    /**
     * Tests transactions that do not move money, or are not about orders or invoices.
     *
     * @param string $entityClass
     * @param string $action
     * @param bool $successful
     * @dataProvider irrelevantTransactionsDataProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('irrelevantTransactionsDataProvider')]
    public function testIgnoresIrrelevantTransactions(string $entityClass, string $action, bool $successful): void
    {
        $this->orderWithInvoice(101, 100.0);
        $this->entityManager->expects(self::never())->method('flush');

        $transaction = $this->orderTransaction(900, 101, $action, '100.00', $successful);
        $transaction->setEntityClass($entityClass);

        $this->persistAndFlush($transaction);

        self::assertSame([], $this->syncGuard->drainQueue());
    }

    /**
     * Provides the data sets for irrelevant transactions data.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function irrelevantTransactionsDataProvider(): array
    {
        return [
            'failed capture' => [Order::class, 'capture', false],
            'authorisation only' => [Order::class, 'authorize', true],
            'payment term invoice' => [Order::class, 'invoice', true],
            'payment term pending' => [Order::class, 'pending', true],
            'another entity' => [\stdClass::class, 'capture', true],
        ];
    }

    /**
     * Tests that purchase and charge are treated like capture.
     */
    public function testPurchaseAndChargeAreRecorded(): void
    {
        $this->orderWithInvoice(101, 100.0);

        $this->persistAndFlush($this->orderTransaction(900, 101, 'purchase', '60.00'));
        $this->persistAndFlush($this->orderTransaction(901, 101, 'charge', '40.00'));

        self::assertCount(2, $this->persistedPayments());
    }

    /**
     * Tests that updates to a transaction (e.g. authorize -> capture) are picked up too.
     */
    public function testPostUpdateIsHandled(): void
    {
        $this->orderWithInvoice(101, 100.0);
        $transaction = $this->orderTransaction(900, 101, 'capture', '100.00');

        $this->listener->postUpdate($transaction, new PostUpdateEventArgs($transaction, $this->entityManager));
        $this->listener->postFlush();

        self::assertCount(1, $this->persistedPayments());
    }

    /**
     * Tests that an order without an invoice yet is left for invoice creation to pick up later.
     */
    public function testOrderWithoutAnInvoiceIsLeftAlone(): void
    {
        $this->orders[101] = $this->order(101, true);
        $this->paidAmountProvider->expects(self::never())->method('applyTo');

        $this->persistAndFlush($this->orderTransaction(900, 101, 'capture', '100.00'));

        self::assertSame([], $this->persistedPayments());
    }

    /**
     * Tests that an order without a customer user cannot be recorded and is logged.
     */
    public function testOrderWithoutACustomerUserIsLogged(): void
    {
        $this->orderWithInvoice(101, 100.0, false);
        $this->logger->expects(self::atLeastOnce())->method('warning')->with(self::stringContains('no CustomerUser'));

        $this->persistAndFlush($this->orderTransaction(900, 101, 'capture', '100.00'));

        self::assertSame([], $this->persistedPayments());
    }

    /**
     * Tests that a successful gateway payment for an invoice payment marks it processed and mirrors it to the order.
     */
    public function testInvoicePaymentTransactionIsProcessedAndMirrored(): void
    {
        $invoice = $this->orderWithInvoice(101, 100.0);

        $payment = new InvoicePayment();
        $payment->setActive(true)->setPaymentMethod('credit_card_3');
        (new \ReflectionProperty($payment, 'id'))->setValue($payment, 7);
        $lineItem = (new InvoicePaymentLineItem())->setInvoice($invoice)->setAmount(40.0)->setCurrency('USD');
        $payment->addLineItem($lineItem);
        $this->paymentsById[7] = $payment;

        $this->paidAmountProvider->expects(self::once())->method('applyTo')->with($invoice);

        $transaction = $this->orderTransaction(950, 7, 'capture', '40.00');
        $transaction->setEntityClass(InvoicePayment::class);
        $this->persistAndFlush($transaction);

        self::assertFalse($payment->isActive());
        self::assertSame(950, $payment->getPaymentTransactionId());
        self::assertSame($invoice, $payment->getInvoice());

        $mirrors = array_values(array_filter(
            $this->persisted,
            static fn (object $entity): bool => $entity instanceof PaymentTransaction
        ));
        self::assertCount(1, $mirrors);
        self::assertSame(Order::class, $mirrors[0]->getEntityClass());
        self::assertSame(101, (int) $mirrors[0]->getEntityIdentifier());
        self::assertEquals(40.0, (float) $mirrors[0]->getAmount());
        self::assertSame($payment, $this->syncGuard->getClaimedInvoicePayment($mirrors[0]));
    }

    /**
     * Tests that a gateway payment already processed is not mirrored a second time.
     */
    public function testProcessedInvoicePaymentIsNotMirroredAgain(): void
    {
        $invoice = $this->orderWithInvoice(101, 100.0);

        $payment = new InvoicePayment();
        $payment->setActive(false);
        (new \ReflectionProperty($payment, 'id'))->setValue($payment, 7);
        $payment->addLineItem((new InvoicePaymentLineItem())->setInvoice($invoice)->setAmount(40.0));
        $this->paymentsById[7] = $payment;

        $transaction = $this->orderTransaction(950, 7, 'capture', '40.00');
        $transaction->setEntityClass(InvoicePayment::class);
        $this->persistAndFlush($transaction);

        foreach ($this->persisted as $entity) {
            self::assertNotInstanceOf(PaymentTransaction::class, $entity);
        }
    }

    /**
     * Tests that a failure syncing one transaction is logged and does not break the flush.
     */
    public function testSyncFailureIsLogged(): void
    {
        $invoice = $this->orderWithInvoice(101, 100.0);

        // An order that blows up when read (getRemainingBalance is already stubbed in setUp,
        // so the failure is injected here instead).
        $brokenOrder = $this->createMock(Order::class);
        $brokenOrder->method('getId')->willReturn(101);
        $brokenOrder->method('getCustomerUser')->willThrowException(new \RuntimeException('boom'));
        $this->orders[101] = $brokenOrder;
        $invoice->setOrder($brokenOrder);

        $this->paidAmountProvider->expects(self::never())->method('applyTo');
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('Failed to sync'));

        $this->persistAndFlush($this->orderTransaction(900, 101, 'capture', '100.00'));
    }

    /**
     * Tests that the listener's own flush does not re-enter processing.
     */
    public function testDoesNotReenterDuringItsOwnFlush(): void
    {
        $this->orderWithInvoice(101, 100.0);
        $reentered = false;

        $this->paidAmountProvider->method('applyTo')->willReturnCallback(function () use (&$reentered): float {
            self::assertTrue($this->syncGuard->isProcessing());
            $this->listener->postFlush();
            $reentered = true;

            return 0.0;
        });

        $this->persistAndFlush($this->orderTransaction(900, 101, 'capture', '100.00'));

        self::assertTrue($reentered);
        self::assertFalse($this->syncGuard->isProcessing(), 'Processing ends even after re-entry');
    }

    /**
     * Persists and flush.
     *
     * @param PaymentTransaction $transaction
     */
    private function persistAndFlush(PaymentTransaction $transaction): void
    {
        $this->listener->postPersist($transaction, new PostPersistEventArgs($transaction, $this->entityManager));
        $this->listener->postFlush();
    }

    /**
     * Returns the order transaction.
     *
     * @param int $id
     * @param int $entityId
     * @param string $action
     * @param string $amount
     * @param bool $successful
     * @return PaymentTransaction
     */
    private function orderTransaction(
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
     * Returns the order with invoice.
     *
     * @param int $orderId
     * @param float $amount
     * @param bool $withCustomerUser
     * @return Invoice
     */
    private function orderWithInvoice(int $orderId, float $amount, bool $withCustomerUser = true): Invoice
    {
        $order = $this->order($orderId, $withCustomerUser);
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
     * Returns the order.
     *
     * @param int $id
     * @param bool $withCustomerUser
     * @return Order
     */
    private function order(int $id, bool $withCustomerUser): Order
    {
        $order = new Order();
        $class = new \ReflectionClass($order);
        while ($class && !$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }
        $class->getProperty('id')->setValue($order, $id);

        if ($withCustomerUser) {
            $customer = new Customer();
            $customerUser = new CustomerUser();
            $customerUser->setCustomer($customer);
            $order->setCustomer($customer);
            $order->setCustomerUser($customerUser);
        }

        return $order;
    }

    /**
     * Returns the persisted payments.
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
