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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceCancellationManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceEmailManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\OrderCancellationChecker;
use Doctrine\ORM\EntityManagerInterface;
use Oro\Bundle\OrderBundle\Entity\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the manual, automatic and catch-up invoice cancellation paths.
 */
class InvoiceCancellationManagerTest extends TestCase
{
    private InvoiceEmailManager&MockObject $emailManager;
    private EntityManagerInterface&MockObject $entityManager;

    /** @var InvoiceCancellationManager $manager */
    private InvoiceCancellationManager $manager;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->emailManager = $this->createMock(InvoiceEmailManager::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->manager = new InvoiceCancellationManager(
            $this->emailManager,
            $this->entityManager,
            new NullLogger(),
            new OrderCancellationChecker()
        );
    }

    /**
     * Returns an invoice in the given state.
     *
     * @param string $status
     * @param float $amountPaid
     * @param bool $posted
     * @return Invoice
     */
    private function invoice(string $status, float $amountPaid = 0.0, bool $posted = false): Invoice
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00001');
        $invoice->setStatus($status);
        $invoice->setAmountPaid($amountPaid);

        if ($posted) {
            $invoice->setPostedAt(new \DateTime());
        }

        return $invoice;
    }

    /**
     * Returns an order whose internal status reports the given bare id.
     *
     * @param string|null $internalId
     * @return Order
     */
    private function order(?string $internalId): Order
    {
        $status = null === $internalId ? null : new class ($internalId) {
            /**
             * Creates a new InvoiceCancellationManagerTest instance.
             *
             * @param string $internalId
             */
            public function __construct(private readonly string $internalId)
            {
            }

            /**
             * Returns the id.
             *
             * @return string
             */
            public function getId(): string
            {
                return 'order_internal_status.' . $this->internalId;
            }

            /**
             * Returns the internal id.
             *
             * @return string
             */
            public function getInternalId(): string
            {
                return $this->internalId;
            }
        };

        return new class ($status) extends Order {
            /**
             * Creates a new InvoiceCancellationManagerTest instance.
             *
             * @param mixed $stubStatus
             */
            public function __construct(private mixed $stubStatus)
            {
                // Skip parent constructor - needs a full ORM/extend bootstrap.
            }

            /**
             * Returns the internal status.
             *
             * @return mixed
             */
            public function getInternalStatus(): mixed
            {
                return $this->stubStatus;
            }
        };
    }

    // ---------------------------------------------------------------- cancel()

    /**
     * Tests that a posted invoice is cancelled and the customer told about it.
     */
    public function testCancelCancelsAPostedInvoiceAndNotifiesTheCustomer(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 0.0, true);

        $this->emailManager->expects(self::once())->method('sendCancellationNotification')->with($invoice);

        $this->manager->cancel($invoice);

        self::assertTrue($invoice->isCancelled());
        self::assertTrue($invoice->isCancelledNotificationSent());
    }

    /**
     * Tests that a draft is cancelled silently, since it was never issued to the customer.
     */
    public function testCancelCancelsADraftWithoutEmailingTheCustomer(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_DRAFT);

        $this->emailManager->expects(self::never())->method('sendCancellationNotification');

        $this->manager->cancel($invoice);

        self::assertTrue($invoice->isCancelled());
        self::assertFalse($invoice->isCancelledNotificationSent());
    }

    /**
     * Tests that cancelling an already cancelled invoice is reported rather than repeated.
     */
    public function testCancelRefusesAnAlreadyCancelledInvoice(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_CANCELLED);

        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('softsolutions4u.invoice.messages.cancel_already_cancelled');

        $this->manager->cancel($invoice);
    }

    /**
     * Tests that a paid invoice can be cancelled, and the customer is told.
     */
    public function testCancelCancelsAPaidInvoiceAndNotifiesTheCustomer(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_PAID, 100.0, true);

        $this->emailManager->expects(self::once())->method('sendCancellationNotification')->with($invoice);

        $this->manager->cancel($invoice);

        self::assertTrue($invoice->isCancelled());
        self::assertTrue($invoice->isCancelledNotificationSent());
    }

    /**
     * Tests that a partially paid invoice can be cancelled too.
     */
    public function testCancelCancelsAPartiallyPaidInvoice(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_PARTIALLY_PAID, 25.0, true);

        $this->emailManager->expects(self::once())->method('sendCancellationNotification');

        $this->manager->cancel($invoice);

        self::assertTrue($invoice->isCancelled());
    }

    /**
     * Tests that a failed notification still leaves the invoice cancelled.
     */
    public function testCancelKeepsTheCancellationWhenTheEmailFails(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 0.0, true);

        $this->emailManager->method('sendCancellationNotification')
            ->willThrowException(new \RuntimeException('smtp down'));

        $this->manager->cancel($invoice);

        self::assertTrue($invoice->isCancelled());
        // Not marked as sent, so a later attempt can still reach the customer.
        self::assertFalse($invoice->isCancelledNotificationSent());
    }

    // --------------------------------------------------------- isCancellable()

    /**
     * Tests which states the Cancel controls are offered for.
     */
    public function testIsCancellableMatchesWhatCancelWillAccept(): void
    {
        self::assertTrue($this->manager->isCancellable($this->invoice(Invoice::STATUS_DRAFT)));
        self::assertTrue($this->manager->isCancellable($this->invoice(Invoice::STATUS_POSTED)));
        self::assertTrue($this->manager->isCancellable($this->invoice(Invoice::STATUS_OVERDUE)));

        self::assertTrue($this->manager->isCancellable($this->invoice(Invoice::STATUS_PAID, 100.0)));
        self::assertTrue($this->manager->isCancellable($this->invoice(Invoice::STATUS_PARTIALLY_PAID, 25.0)));

        // The only state that rules cancellation out.
        self::assertFalse($this->manager->isCancellable($this->invoice(Invoice::STATUS_CANCELLED)));
    }

    // -------------------------------------------------------- cancelForOrder()

    /**
     * Tests that an order cancellation cancels its posted invoice and notifies the customer.
     */
    public function testCancelForOrderCancelsAndNotifies(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 0.0, true);

        $this->emailManager->expects(self::once())->method('sendCancellationNotification');

        $this->manager->cancelForOrder($invoice);

        self::assertTrue($invoice->isCancelled());
    }

    /**
     * Tests that a cancelled order also cancels an invoice that was already paid.
     */
    public function testCancelForOrderCancelsPaidInvoicesToo(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_PAID, 100.0, true);

        $this->emailManager->expects(self::once())->method('sendCancellationNotification');

        $this->manager->cancelForOrder($invoice);

        self::assertTrue($invoice->isCancelled());
    }

    /**
     * Tests that re-running the automatic path does not re-notify the customer.
     */
    public function testCancelForOrderIsIdempotent(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_CANCELLED, 0.0, true);

        $this->emailManager->expects(self::never())->method('sendCancellationNotification');

        $this->manager->cancelForOrder($invoice);

        self::assertTrue($invoice->isCancelled());
    }

    // -------------------------------------------------- cancelIfOrderCancelled()

    /**
     * Tests the catch-up that fires when a draft on a cancelled order is posted.
     */
    public function testCancelIfOrderCancelledCancelsADraftOnACancelledOrder(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_DRAFT);
        $invoice->setOrder($this->order('cancelled'));

        $this->emailManager->expects(self::never())->method('sendCancellationNotification');

        self::assertTrue($this->manager->cancelIfOrderCancelled($invoice));
        self::assertTrue($invoice->isCancelled());
    }

    /**
     * Tests that an invoice on a live order is left alone.
     */
    public function testCancelIfOrderCancelledLeavesALiveOrderAlone(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_DRAFT);
        $invoice->setOrder($this->order('open'));

        self::assertFalse($this->manager->cancelIfOrderCancelled($invoice));
        self::assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus());
    }

    /**
     * Tests that a standalone invoice with no order is left alone.
     */
    public function testCancelIfOrderCancelledLeavesAnInvoiceWithoutAnOrderAlone(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_DRAFT);

        self::assertFalse($this->manager->cancelIfOrderCancelled($invoice));
        self::assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus());
    }

    /**
     * Tests that an already cancelled invoice reports no further change.
     */
    public function testCancelIfOrderCancelledReportsNoChangeWhenAlreadyCancelled(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_CANCELLED);
        $invoice->setOrder($this->order('cancelled'));

        self::assertFalse($this->manager->cancelIfOrderCancelled($invoice));
    }

    /**
     * Tests that a paid invoice on a cancelled order is cancelled by the catch-up too.
     */
    public function testCancelIfOrderCancelledCancelsPaidInvoices(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_PAID, 100.0, true);
        $invoice->setOrder($this->order('cancelled'));

        self::assertTrue($this->manager->cancelIfOrderCancelled($invoice));
        self::assertTrue($invoice->isCancelled());
    }

    // ------------------------------------ resendCancellationNotification()

    /**
     * Tests that the cancellation notice can be sent again on request.
     */
    public function testResendCancellationNotification(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_CANCELLED, 100.0, true);
        $invoice->setCancelledNotificationSentAt(new \DateTime('-1 day'));

        $this->emailManager->expects(self::once())->method('sendCancellationNotification')->with($invoice);

        $this->manager->resendCancellationNotification($invoice);

        self::assertTrue($invoice->isCancelledNotificationSent());
    }

    /**
     * Tests that there is nothing to resend for an invoice that is not cancelled.
     */
    public function testResendCancellationNotificationRefusesALiveInvoice(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 0.0, true);

        $this->emailManager->expects(self::never())->method('sendCancellationNotification');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('softsolutions4u.invoice.messages.resend_cancellation_not_cancelled');

        $this->manager->resendCancellationNotification($invoice);
    }
}
