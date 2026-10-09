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
use SoftSolutions4U\Bundle\InvoiceBundle\Event\InvoicePaymentSuccessEvent;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\OrderPaymentTransactionSyncGuard;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\RecalculateInvoicePaidAmountsListener;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoicePaymentCompletionManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for crediting invoices after a successful storefront payment.
 *
 * @covers \SoftSolutions4U\Bundle\InvoiceBundle\EventListener\RecalculateInvoicePaidAmountsListener
 */
class RecalculateInvoicePaidAmountsListenerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private LoggerInterface&MockObject $logger;
    private InvoicePaidAmountProvider&MockObject $paidAmountProvider;
    private InvoicePaymentCompletionManager&MockObject $completionManager;

    /** @var OrderPaymentTransactionSyncGuard $syncGuard */
    private OrderPaymentTransactionSyncGuard $syncGuard;

    /** @var RecalculateInvoicePaidAmountsListener $listener */
    private RecalculateInvoicePaidAmountsListener $listener;

    /** @var array<int, object> */
    private array $persisted = [];

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->paidAmountProvider = $this->createMock(InvoicePaidAmountProvider::class);
        $this->completionManager = $this->createMock(InvoicePaymentCompletionManager::class);
        $this->syncGuard = new OrderPaymentTransactionSyncGuard();

        $this->listener = new RecalculateInvoicePaidAmountsListener(
            $registry,
            $this->logger,
            $this->paidAmountProvider,
            $this->syncGuard,
            $this->completionManager
        );
    }

    /**
     * Tests the whole flow for a payment covering two invoices.
     */
    public function testCreditsEveryInvoiceOnThePayment(): void
    {
        $first = $this->invoice(1, 101);
        $second = $this->invoice(2, 102);
        $payment = $this->payment([[$first, 40.0], [$second, 60.0]]);

        $this->paidAmountProvider->expects(self::exactly(2))->method('applyTo');
        $this->completionManager->expects(self::exactly(2))->method('onPaymentApplied');

        $this->listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));

        self::assertFalse($payment->isActive(), 'The payment is marked processed');
        self::assertNull($payment->getInvoice(), 'A payment spanning several invoices is not pinned to one');
    }

    /**
     * Tests that each paid line is mirrored onto its order as a successful capture, claimed by the guard.
     */
    public function testMirrorsEachLineOntoItsOrder(): void
    {
        $invoice = $this->invoice(1, 101);
        $payment = $this->payment([[$invoice, 40.0]]);
        $payment->setPaymentMethod('credit_card_3');

        $this->listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));

        $transactions = array_values(array_filter(
            $this->persisted,
            static fn (object $entity): bool => $entity instanceof PaymentTransaction
        ));

        self::assertCount(1, $transactions);
        $transaction = $transactions[0];
        self::assertSame(Order::class, $transaction->getEntityClass());
        self::assertSame(101, (int) $transaction->getEntityIdentifier());
        self::assertSame('capture', $transaction->getAction());
        self::assertEquals(40.0, (float) $transaction->getAmount());
        self::assertSame('USD', $transaction->getCurrency());
        self::assertSame('credit_card_3', $transaction->getPaymentMethod());
        self::assertTrue($transaction->isSuccessful());
        self::assertNotEmpty($transaction->getAccessIdentifier());
        self::assertNotEmpty($transaction->getAccessToken());

        // Claimed, so OrderPaymentTransactionListener links it instead of recording the payment twice.
        self::assertSame($payment, $this->syncGuard->getClaimedInvoicePayment($transaction));
    }

    /**
     * Tests that a payment for a single invoice is linked directly to it.
     */
    public function testSingleInvoicePaymentIsLinked(): void
    {
        $invoice = $this->invoice(1, 101);
        $payment = $this->payment([[$invoice, 40.0]]);

        $this->listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));

        self::assertSame($invoice, $payment->getInvoice());
    }

    /**
     * Tests that the payment is committed as processed before totals are recalculated from the database.
     */
    public function testCommitsThePaymentBeforeRecalculating(): void
    {
        $payment = $this->payment([[$this->invoice(1, 101), 40.0]]);
        $calls = [];

        $this->entityManager->method('flush')->willReturnCallback(function () use (&$calls, $payment): void {
            $calls[] = 'flush(active=' . var_export($payment->isActive(), true) . ')';
        });
        $this->paidAmountProvider->method('applyTo')->willReturnCallback(function () use (&$calls): float {
            $calls[] = 'applyTo';

            return 0.0;
        });
        $this->completionManager->method('onPaymentApplied')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'onPaymentApplied';
        });

        $this->listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));

        self::assertSame(['flush(active=false)', 'applyTo', 'flush(active=false)', 'onPaymentApplied'], $calls);
    }

    /**
     * Tests that a payment already processed is never credited twice.
     */
    public function testAlreadyProcessedPaymentIsSkipped(): void
    {
        $payment = $this->payment([[$this->invoice(1, 101), 40.0]]);
        $payment->setActive(false);

        $this->entityManager->expects(self::never())->method('flush');
        $this->paidAmountProvider->expects(self::never())->method('applyTo');

        $this->listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));
    }

    /**
     * Tests that a payment with no invoices is logged and left alone.
     */
    public function testPaymentWithoutInvoicesIsLogged(): void
    {
        $payment = new InvoicePayment();
        $payment->setActive(true);

        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('nothing to credit'));
        $this->entityManager->expects(self::never())->method('flush');

        $this->listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));

        self::assertTrue($payment->isActive());
    }

    /**
     * Tests that lines without an order, or for nothing, do not create order transactions.
     */
    public function testNoOrderTransactionWithoutOrderOrAmount(): void
    {
        $withoutOrder = new Invoice();
        $withoutOrder->setCurrency('USD');
        (new \ReflectionProperty($withoutOrder, 'id'))->setValue($withoutOrder, 3);

        $payment = $this->payment([[$withoutOrder, 25.0], [$this->invoice(4, 104), 0.0]]);

        $this->listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));

        foreach ($this->persisted as $entity) {
            self::assertNotInstanceOf(PaymentTransaction::class, $entity);
        }
    }

    /**
     * Tests that a line item without an invoice does not create an order transaction.
     */
    public function testLineItemWithoutInvoiceIsIgnored(): void
    {
        $payment = new InvoicePayment();
        $payment->setActive(true);

        $lineItem = new InvoicePaymentLineItem();
        $lineItem->setAmount(40.0)->setCurrency('USD');
        // No invoice set on the line item.
        $payment->addLineItem($lineItem);

        $this->logger->expects(self::once())->method('warning');

        $this->listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));

        foreach ($this->persisted as $entity) {
            self::assertNotInstanceOf(PaymentTransaction::class, $entity);
        }
    }

    /**
     * Tests that the transaction copies the order's organization when supported.
     */
    public function testOrderTransactionCopiesOrganization(): void
    {
        $invoice = $this->invoice(1, 101);
        $order = $invoice->getOrder();

        $organization = new Organization();
        $order->setOrganization($organization);

        $payment = $this->payment([[$invoice, 40.0]]);

        $this->listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));

        $transactions = array_values(array_filter(
            $this->persisted,
            static fn (object $entity): bool => $entity instanceof PaymentTransaction
        ));

        self::assertCount(1, $transactions);
        self::assertSame($organization, $transactions[0]->getOrganization());
    }

    /**
     * Tests that processing stops when the registry does not provide an
     * EntityManager.
     */
    public function testReturnsWhenRegistryManagerIsNotEntityManager(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $manager = $this->createMock(\Doctrine\Persistence\ObjectManager::class);

        $registry
            ->expects(self::once())
            ->method('getManagerForClass')
            ->with(Invoice::class)
            ->willReturn($manager);

        $listener = new RecalculateInvoicePaidAmountsListener(
            $registry,
            $this->logger,
            $this->paidAmountProvider,
            $this->syncGuard,
            $this->completionManager
        );

        $payment = $this->payment([[$this->invoice(1, 101), 40.0]]);

        $this->paidAmountProvider
            ->expects(self::never())
            ->method('applyTo');

        $this->completionManager
            ->expects(self::never())
            ->method('onPaymentApplied');

        $listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));

        self::assertTrue($payment->isActive());
    }

    /**
     * Tests that an order transaction failure is logged and does not prevent
     * the invoice payment totals from being recalculated.
     */
    public function testLogsOrderTransactionFailureAndContinues(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $entityManager
            ->method('persist')
            ->willReturnCallback(function (object $entity): void {
                if ($entity instanceof PaymentTransaction) {
                    throw new \RuntimeException('Unable to persist payment transaction.');
                }

                $this->persisted[] = $entity;
            });

        $entityManager
            ->method('flush')
            ->willReturn(null);

        $registry = $this->createMock(ManagerRegistry::class);

        $registry
            ->method('getManagerForClass')
            ->with(Invoice::class)
            ->willReturn($entityManager);

        $listener = new RecalculateInvoicePaidAmountsListener(
            $registry,
            $this->logger,
            $this->paidAmountProvider,
            $this->syncGuard,
            $this->completionManager
        );

        $invoice = $this->invoice(1, 101);
        $payment = $this->payment([[$invoice, 40.0]]);

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('failed to record Order PaymentTransaction'),
                self::arrayHasKey('exception')
            );

        $this->paidAmountProvider
            ->expects(self::once())
            ->method('applyTo')
            ->with($invoice);

        $this->completionManager
            ->expects(self::once())
            ->method('onPaymentApplied')
            ->with($invoice);

        $listener->recalculate(new InvoicePaymentSuccessEvent($payment, []));

        self::assertFalse($payment->isActive());
        self::assertNotEmpty($this->persisted);
    }

    /**
     * Returns the invoice.
     *
     * @param int $id
     * @param int $orderId
     * @return Invoice
     */
    private function invoice(int $id, int $orderId): Invoice
    {
        $order = new Order();
        $class = new \ReflectionClass($order);
        while ($class && !$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }
        $class->getProperty('id')->setValue($order, $orderId);

        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setCurrency('USD');
        $invoice->setAmount(100.0);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, $id);

        return $invoice;
    }

    /**
     * Returns the payment.
     *
     * @param array<int, array{Invoice, float}> $lines
     * @return InvoicePayment
     */
    private function payment(array $lines): InvoicePayment
    {
        $payment = new InvoicePayment();
        $payment->setActive(true);

        foreach ($lines as [$invoice, $amount]) {
            $lineItem = new InvoicePaymentLineItem();
            $lineItem->setInvoice($invoice)->setAmount($amount)->setCurrency('USD');
            $payment->addLineItem($lineItem);
        }

        return $payment;
    }
}
