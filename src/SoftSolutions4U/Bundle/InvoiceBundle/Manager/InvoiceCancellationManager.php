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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Manager;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\OrderCancellationChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cancels invoices, either automatically (the order behind them was cancelled)
 * or on request from the backoffice, and notifies the customer once.
 *
 * Any invoice that is not already cancelled can be cancelled, Paid and
 * Partially Paid ones included: cancelling records that the invoice is void,
 * and refunding whatever was already taken is a separate decision made outside
 * this bundle. The customer is always told, so a paid invoice that is later
 * cancelled still sends its cancellation notice.
 *
 * The one exception is a Draft: it is cancelled (so it can no longer be posted,
 * edited or paid) but never triggers a customer email, because a Draft was
 * never issued to the customer in the first place.
 */
class InvoiceCancellationManager
{
    /**
     * Creates a new InvoiceCancellationManager instance.
     *
     * @param InvoiceEmailManager $invoiceEmailManager
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $logger
     * @param OrderCancellationChecker $orderCancellationChecker
     */
    public function __construct(
        private readonly InvoiceEmailManager $invoiceEmailManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly OrderCancellationChecker $orderCancellationChecker = new OrderCancellationChecker(),
    ) {
    }

    /**
     * Whether this invoice can be cancelled right now.
     *
     * Drives both the guard below and the visibility of every "Cancel Invoice"
     * control, so the button is never offered for something the manager would
     * refuse.
     *
     * @param Invoice $invoice
     * @return bool
     */
    public function isCancellable(Invoice $invoice): bool
    {
        return $invoice->isCancellable();
    }

    /**
     * Cancels the invoice on explicit request from the backoffice.
     *
     * @param Invoice $invoice
     * @throws \LogicException if the invoice is already cancelled or has payments
     */
    public function cancel(Invoice $invoice): void
    {
        if ($invoice->isCancelled()) {
            throw new \LogicException('softsolutions4u.invoice.messages.cancel_already_cancelled');
        }

        $this->applyCancellation($invoice, 'cancelled from the backoffice');
    }

    /**
     * Cancels the invoice because its own order was cancelled.
     *
     * Unlike cancel(), this never throws: it runs from a Doctrine postFlush
     * listener where an exception would take the whole request down.
     *
     * @param Invoice $invoice
     */
    public function cancelForOrder(Invoice $invoice): void
    {
        if ($invoice->isCancelled()) {
            return;
        }

        $this->applyCancellation($invoice, 'its order was cancelled');
    }

    /**
     * Cancels a not-yet-cancelled invoice whose order is already cancelled.
     *
     * This is the catch-up for the case the order cancellation itself could not
     * cover: a Draft that was sitting on a cancelled order is cancelled at the
     * moment someone tries to post it, rather than being sent to a customer
     * whose order no longer exists.
     *
     * @param Invoice $invoice
     * @return bool whether the invoice was cancelled by this call
     */
    public function cancelIfOrderCancelled(Invoice $invoice): bool
    {
        if ($invoice->isCancelled()) {
            return false;
        }

        $order = $invoice->getOrder();

        if (null === $order || !$this->orderCancellationChecker->isCancelled($order)) {
            return false;
        }

        $this->applyCancellation($invoice, 'its order was already cancelled');

        return true;
    }

    /**
     * Re-sends the cancellation notice for an already cancelled invoice.
     *
     * Bypasses the once-only guard that applyCancellation() honours, so a
     * customer who missed the first notice - or whose invoice was cancelled
     * after it had already been paid - can be told again on request.
     *
     * @param Invoice $invoice
     * @throws \LogicException if the invoice is not cancelled
     */
    public function resendCancellationNotification(Invoice $invoice): void
    {
        if (!$invoice->isCancelled()) {
            throw new \LogicException('softsolutions4u.invoice.messages.resend_cancellation_not_cancelled');
        }

        $this->invoiceEmailManager->sendCancellationNotification($invoice);

        $invoice->setCancelledNotificationSentAt(new \DateTime('now', new \DateTimeZone('UTC')));
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
    }

    /**
     * Writes the cancellation and notifies the customer when the invoice had been issued to them.
     *
     * @param Invoice $invoice
     * @param string $reason
     */
    private function applyCancellation(Invoice $invoice, string $reason): void
    {
        $wasDraft = $invoice->getStatus() === Invoice::STATUS_DRAFT;

        $invoice->setStatus(Invoice::STATUS_CANCELLED);
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        $this->logger->info(
            'InvoiceCancellationManager: Invoice {invoiceId} cancelled because {reason}.',
            ['invoiceId' => $invoice->getId(), 'reason' => $reason]
        );

        if ($wasDraft) {
            // Never issued to the customer - nothing to notify them about.
            return;
        }

        $this->sendCancellationNotificationIfNeeded($invoice);
    }

    /**
     * Sends the cancellation notice the first time this invoice is cancelled.
     *
     * @param Invoice $invoice
     */
    private function sendCancellationNotificationIfNeeded(Invoice $invoice): void
    {
        if ($invoice->isCancelledNotificationSent()) {
            return;
        }

        try {
            $this->invoiceEmailManager->sendCancellationNotification($invoice);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'InvoiceCancellationManager: could not send cancellation notification for Invoice {invoiceId}: '
                . '{message}',
                [
                    'invoiceId' => $invoice->getId(),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]
            );

            return;
        }

        $invoice->setCancelledNotificationSentAt(new \DateTime('now', new \DateTimeZone('UTC')));
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        $this->logger->info(
            'InvoiceCancellationManager: cancellation notification sent for Invoice {invoiceId}.',
            ['invoiceId' => $invoice->getId()]
        );
    }
}
