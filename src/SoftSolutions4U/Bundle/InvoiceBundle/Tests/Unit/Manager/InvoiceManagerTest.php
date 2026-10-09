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

use SoftSolutions4U\Bundle\InvoiceBundle\Builder\InvoiceBuilder;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceLineItemManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\OrderPaymentLedgerSynchronizer;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;
use Doctrine\ORM\EntityManagerInterface;
use Oro\Bundle\OrderBundle\Entity\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for invoice deletion, creation guards and applying order payments.
 */
class InvoiceManagerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private InvoiceRepository&MockObject $repository;
    private InvoiceLineItemManager&MockObject $lineItemManager;
    private InvoicePaidAmountProvider&MockObject $paidAmountProvider;
    private OrderPaymentLedgerSynchronizer&MockObject $ledgerSynchronizer;

    /** @var InvoiceManager $manager */
    private InvoiceManager $manager;

    /**
     * Sets up the manager with mocked collaborators.
     */
    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(InvoiceRepository::class);
        $this->lineItemManager = $this->createMock(InvoiceLineItemManager::class);

        $this->paidAmountProvider = $this->createMock(InvoicePaidAmountProvider::class);
        $this->ledgerSynchronizer = $this->createMock(OrderPaymentLedgerSynchronizer::class);

        $this->entityManager->method('getRepository')->willReturn($this->repository);

        $this->manager = new InvoiceManager(
            $this->createMock(InvoiceBuilder::class),
            $this->lineItemManager,
            $this->entityManager,
            $this->paidAmountProvider,
            $this->ledgerSynchronizer
        );
    }

    /**
     * Returns an invoice in the given state.
     *
     * @param string $status
     * @param float $amountPaid
     * @return Invoice
     */
    private function invoice(string $status, float $amountPaid = 0.0): Invoice
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00001');
        $invoice->setStatus($status);
        $invoice->setAmountPaid($amountPaid);

        return $invoice;
    }

    /**
     * Tests that a draft invoice with no payments can be deleted.
     */
    public function testDeletesADraftInvoiceWithNoPayments(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_DRAFT);

        $this->entityManager->expects(self::once())->method('remove')->with($invoice);
        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->delete($invoice);
    }

    /**
     * Tests that an invoice with recorded payments cannot be deleted.
     */
    public function testRefusesToDeleteAnInvoiceWithPayments(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_DRAFT, 25.00);

        $this->entityManager->expects(self::never())->method('remove');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('softsolutions4u.invoice.messages.delete_has_payments');

        $this->manager->delete($invoice);
    }

    /**
     * Tests that a posted invoice cannot be deleted.
     */
    public function testRefusesToDeleteAPostedInvoice(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED);

        $this->entityManager->expects(self::never())->method('remove');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('softsolutions4u.invoice.messages.delete_not_draft');

        $this->manager->delete($invoice);
    }

    /**
     * Tests that an order without an invoice passes the creation guard.
     */
    public function testAllowsCreationWhenTheOrderHasNoInvoice(): void
    {
        $this->repository->method('findOneByOrderId')->willReturn(null);

        $this->manager->assertCanCreateForOrder($this->order(10));

        $this->expectNotToPerformAssertions();
    }

    /**
     * Tests that the create-from-order operation refuses an order that already has an invoice and saves nothing.
     */
    public function testCreateDraftFromOrderIsRefusedWhenAnInvoiceExists(): void
    {
        $this->repository->method('findOneByOrderId')->willReturn($this->invoice(Invoice::STATUS_DRAFT));
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('softsolutions4u.invoice.messages.already_draft');

        $this->manager->createDraftFromOrder($this->order(10));
    }

    /**
     * Tests that an order with a draft invoice is reported as such.
     */
    public function testRefusesCreationWhenADraftInvoiceExists(): void
    {
        $this->repository->method('findOneByOrderId')->willReturn($this->invoice(Invoice::STATUS_DRAFT));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('softsolutions4u.invoice.messages.already_draft');

        $this->manager->assertCanCreateForOrder($this->order(10));
    }

    /**
     * Tests the guard behind requirement "no invoice for a cancelled order".
     */
    public function testRefusesCreationForACancelledOrder(): void
    {
        $order = new class extends Order {
            /**
             * Creates a new InvoiceManagerTest instance.
             */
            public function __construct()
            {
                // Skip parent constructor.
            }

            /**
             * Returns the id.
             *
             * @return int|null
             */
            public function getId(): ?int
            {
                return 10;
            }

            /**
             * Returns the internal status.
             *
             * @return mixed
             */
            public function getInternalStatus(): mixed
            {
                return 'order_internal_status.cancelled';
            }
        };

        $this->repository->expects(self::never())->method('findOneByOrderId');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('softsolutions4u.invoice.messages.order_cancelled');

        $this->manager->assertCanCreateForOrder($order);
    }

    /**
     * Tests that an order with a posted invoice cannot get a second one.
     */
    public function testRefusesCreationWhenAPostedInvoiceExists(): void
    {
        $this->repository->method('findOneByOrderId')->willReturn($this->invoice(Invoice::STATUS_POSTED));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('softsolutions4u.invoice.messages.already_created');

        $this->manager->assertCanCreateForOrder($this->order(10));
    }

    /**
     * Tests that saving an invoice without an order recalculates from its line items.
     */
    public function testSaveRecalculatesTotalsForAnInvoiceWithoutAnOrder(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_DRAFT);

        $this->lineItemManager->expects(self::once())
            ->method('recalculateInvoiceTotals')
            ->with($invoice);
        $this->entityManager->expects(self::once())->method('persist')->with($invoice);
        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->save($invoice);
    }

    /**
     * Tests that saving an order invoice records the order's checkout payments.
     */
    public function testSaveAppliesOrderPaymentsToAnOrderInvoice(): void
    {
        $invoice = $this->orderInvoice(Invoice::STATUS_DRAFT);

        $this->lineItemManager->expects(self::once())->method('recalculateInvoiceTotalsFromOrder');
        $this->ledgerSynchronizer->expects(self::once())
            ->method('recordMissingPayments')
            ->with($invoice, 0.0)
            ->willReturn(0.0);

        $this->manager->save($invoice);
    }

    /**
     * Tests the reported bug: a draft for an order paid at checkout becomes Paid, not Posted.
     */
    public function testDraftForAnOrderPaidAtCheckoutBecomesPaid(): void
    {
        $invoice = $this->orderInvoice(Invoice::STATUS_DRAFT, 100.00);

        $this->ledgerSynchronizer->method('recordMissingPayments')->willReturn(100.00);
        $this->simulateLedgerPaid(100.00);

        self::assertTrue($this->manager->applyOrderPayments($invoice));

        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        self::assertTrue($invoice->isPosted(), 'A paid invoice is issued, so it carries a posted date');
        self::assertSame(100.00, $invoice->getAmountPaid());
    }

    /**
     * Tests that a posted invoice is handed to the ledger rules, which move it to Paid.
     */
    public function testPostedInvoiceStatusIsDerivedFromTheLedger(): void
    {
        $invoice = $this->orderInvoice(Invoice::STATUS_POSTED, 100.00);

        $this->ledgerSynchronizer->method('recordMissingPayments')->willReturn(100.00);
        $this->paidAmountProvider->expects(self::once())
            ->method('applyTo')
            ->with($invoice)
            ->willReturnCallback(static function (Invoice $invoice): float {
                $invoice->setAmountPaid(100.00);
                $invoice->setStatus(Invoice::STATUS_PAID);

                return 100.00;
            });

        $this->manager->applyOrderPayments($invoice);

        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
    }

    /**
     * Tests that a draft only partly covered by checkout payments stays a draft.
     */
    public function testPartlyPaidDraftStaysDraft(): void
    {
        $invoice = $this->orderInvoice(Invoice::STATUS_DRAFT, 100.00);

        $this->ledgerSynchronizer->method('recordMissingPayments')->willReturn(40.00);
        $this->simulateLedgerPaid(40.00);

        $this->manager->applyOrderPayments($invoice);

        self::assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus());
        self::assertFalse($invoice->isPosted());
    }

    /**
     * Tests that a zero-value draft is never marked paid.
     */
    public function testZeroValueDraftIsNotMarkedPaid(): void
    {
        $invoice = $this->orderInvoice(Invoice::STATUS_DRAFT, 0.00);

        $this->ledgerSynchronizer->method('recordMissingPayments')->willReturn(0.01);
        $this->simulateLedgerPaid(0.01);

        $this->manager->applyOrderPayments($invoice);

        self::assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus());
    }

    /**
     * Tests that an unpaid order invoice is left alone, so its amounts are never zeroed.
     */
    public function testUnpaidOrderInvoiceIsLeftUntouched(): void
    {
        $invoice = $this->orderInvoice(Invoice::STATUS_POSTED, 100.00);

        $this->paidAmountProvider->method('getPaidAmount')->willReturn(0.0);
        $this->ledgerSynchronizer->method('recordMissingPayments')->willReturn(0.0);
        $this->paidAmountProvider->expects(self::never())->method('applyTo');
        $this->entityManager->expects(self::never())->method('flush');

        self::assertFalse($this->manager->applyOrderPayments($invoice));
        self::assertSame(Invoice::STATUS_POSTED, $invoice->getStatus());
    }

    /**
     * Tests that an invoice already paid through its ledger is recalculated but not re-promoted.
     */
    public function testAlreadyRecordedPaymentsStillRecalculateStatus(): void
    {
        $invoice = $this->orderInvoice(Invoice::STATUS_DRAFT, 100.00);

        $this->paidAmountProvider->method('getPaidAmount')->willReturn(100.00);
        $this->ledgerSynchronizer->method('recordMissingPayments')->willReturn(0.0);
        $this->simulateLedgerPaid(100.00);

        self::assertFalse($this->manager->applyOrderPayments($invoice));

        // Nothing new came from the order in this call, so a draft someone kept
        // as a draft on purpose is not issued behind their back.
        self::assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus());
    }

    /**
     * Tests that invoices without an order, or not yet saved, are skipped.
     */
    public function testSkipsInvoicesWithoutASavedOrder(): void
    {
        $this->ledgerSynchronizer->expects(self::never())->method('recordMissingPayments');

        $withoutOrder = $this->invoice(Invoice::STATUS_POSTED);
        self::setId($withoutOrder, 1);

        $unsaved = $this->orderInvoice(Invoice::STATUS_POSTED, 100.00);
        self::setId($unsaved, null);

        self::assertFalse($this->manager->applyOrderPayments($withoutOrder));
        self::assertFalse($this->manager->applyOrderPayments($unsaved));
    }

    /**
     * Makes the mocked paid-amount provider behave like the real one for amounts.
     *
     * @param float $paid
     */
    private function simulateLedgerPaid(float $paid): void
    {
        $this->paidAmountProvider->method('applyTo')->willReturnCallback(
            static function (Invoice $invoice) use ($paid): float {
                $invoice->setAmountPaid($paid);

                return $paid;
            }
        );
    }

    /**
     * Returns a saved invoice linked to a saved order.
     *
     * @param string $status
     * @param float $amount
     * @return Invoice
     */
    private function orderInvoice(string $status, float $amount = 100.00): Invoice
    {
        $invoice = $this->invoice($status);
        $invoice->setOrder($this->order(55));
        $invoice->setAmount($amount);
        self::setId($invoice, 9);

        return $invoice;
    }

    /**
     * Sets a Doctrine-generated id, which has no public setter.
     *
     * @param object $entity
     * @param int|null $id
     */
    private static function setId(object $entity, ?int $id): void
    {
        $class = new \ReflectionClass($entity);

        while ($class && !$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }

        if ($class) {
            $class->getProperty('id')->setValue($entity, $id);
        }
    }

    /**
     * Returns an order with the given id.
     *
     * @param int $id
     * @return Order
     */
    private function order(int $id): Order
    {
        return new class ($id) extends Order {
            /**
             * Creates a new InvoiceManagerTest instance.
             *
             * @param int $stubId
             */
            public function __construct(private int $stubId)
            {
                // Skip parent constructor.
            }

            /**
             * Returns the id.
             *
             * @return int|null
             */
            public function getId(): ?int
            {
                return $this->stubId;
            }

            /**
             * Returns the internal status.
             *
             * @return mixed
             */
            public function getInternalStatus(): mixed
            {
                return null;
            }
        };
    }
}
