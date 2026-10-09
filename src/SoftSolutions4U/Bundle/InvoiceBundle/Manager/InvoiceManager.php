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
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\OrderCancellationChecker;
use Doctrine\ORM\EntityManagerInterface;
use Oro\Bundle\OrderBundle\Entity\Order;
use SoftSolutions4U\Bundle\InvoiceBundle\Builder\InvoiceBuilder;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\Money;

/**
 * Invoice manager.
 */
class InvoiceManager
{
    /** Same tolerance InvoicePaidAmountProvider uses to call an invoice paid. */

    /** @var OrderPaymentLedgerSynchronizer $orderPaymentLedgerSynchronizer */
    private OrderPaymentLedgerSynchronizer $orderPaymentLedgerSynchronizer;

    /** @var OrderCancellationChecker $orderCancellationChecker */
    private OrderCancellationChecker $orderCancellationChecker;

    /** @var LoggerInterface $logger */
    private LoggerInterface $logger;

    /**
     * Creates a new InvoiceManager instance.
     *
     * @param InvoiceBuilder $invoiceBuilder
     * @param InvoiceLineItemManager $invoiceLineItemManager
     * @param EntityManagerInterface $entityManager
     * @param InvoicePaidAmountProvider $paidAmountProvider
     * @param OrderPaymentLedgerSynchronizer|null $orderPaymentLedgerSynchronizer
     * @param OrderCancellationChecker|null $orderCancellationChecker
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        private InvoiceBuilder $invoiceBuilder,
        private InvoiceLineItemManager $invoiceLineItemManager,
        private EntityManagerInterface $entityManager,
        private InvoicePaidAmountProvider $paidAmountProvider,
        ?OrderPaymentLedgerSynchronizer $orderPaymentLedgerSynchronizer = null,
        ?OrderCancellationChecker $orderCancellationChecker = null,
        ?LoggerInterface $logger = null
    ) {
        $this->orderPaymentLedgerSynchronizer = $orderPaymentLedgerSynchronizer
            ?? new OrderPaymentLedgerSynchronizer($entityManager, new NullLogger());
        $this->orderCancellationChecker = $orderCancellationChecker ?? new OrderCancellationChecker();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Creates the from order.
     *
     * @param Order $order
     * @return Invoice
     */
    public function createFromOrder(Order $order): Invoice
    {
        return $this->invoiceBuilder->build($order);
    }

    /**
     * Validates, builds and saves a draft invoice for an order (used by the "Create Invoice" operation).
     *
     * @param Order $order
     * @return Invoice
     * @throws \LogicException When the order cannot be invoiced (message is a translation key).
     */
    public function createDraftFromOrder(Order $order): Invoice
    {
        $this->assertCanCreateForOrder($order);

        $invoice = $this->createFromOrder($order);
        $this->save($invoice);

        return $invoice;
    }

    /**
     * Returns the translation key of the reason the order cannot be invoiced, or null when it can.
     *
     * @param Order $order
     * @return string|null
     */
    public function getCreateError(Order $order): ?string
    {
        try {
            $this->assertCanCreateForOrder($order);
        } catch (\LogicException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Creates and saves a draft invoice for the order, or returns null and logs the error when that fails.
     *
     * @param Order $order
     * @return Invoice|null
     */
    public function tryCreateDraftFromOrder(Order $order): ?Invoice
    {
        try {
            return $this->createDraftFromOrder($order);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Invoice creation failed for order {orderId}.',
                ['orderId' => $order->getId(), 'exception' => $exception]
            );

            return null;
        }
    }

    /**
     * Asserts the can create for order.
     *
     * @param Order $order
     * @throws \LogicException if the order is cancelled, or already has an invoice
     */
    public function assertCanCreateForOrder(Order $order): void
    {
        if ($this->orderCancellationChecker->isCancelled($order)) {
            throw new \LogicException('softsolutions4u.invoice.messages.order_cancelled');
        }

        /** @var \SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository $repository */
        $repository = $this->entityManager->getRepository(Invoice::class);
        $existingInvoice = $repository->findOneByOrderId((int) $order->getId());

        if (!$existingInvoice instanceof Invoice) {
            return;
        }

        if ($existingInvoice->getStatus() === Invoice::STATUS_DRAFT) {
            throw new \LogicException('softsolutions4u.invoice.messages.already_draft');
        }

        throw new \LogicException('softsolutions4u.invoice.messages.already_created');
    }

    /**
     * Saves the given data.
     *
     * @param Invoice $invoice
     */
    public function save(Invoice $invoice): void
    {
        $order = $invoice->getOrder();

        if ($order) {
            $this->invoiceLineItemManager->recalculateInvoiceTotalsFromOrder(
                $invoice,
                $order
            );
        } else {
            $this->invoiceLineItemManager->recalculateInvoiceTotals($invoice);
        }

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        if ($order) {
            $this->applyOrderPayments($invoice);
        }
    }

    /**
     * Brings an order invoice's paid amount and status in line with what the order has been paid.
     *
     * - Payments the customer made at checkout, before the invoice existed, are
     *   recorded on the invoice ledger.
     * - A draft created for an order that is already paid in full is issued as
     *   Paid straight away: there is nothing left to bill, so it never needs
     *   to be posted.
     * - A posted invoice moves to Paid, Partially Paid or Overdue as the ledger
     *   dictates, so posting a paid order's invoice no longer leaves it Posted.
     *
     * Invoices without any recorded payment are left untouched, so their amounts
     * are never zeroed by a recalculation.
     *
     * @param Invoice $invoice
     * @return bool whether any order payment was newly recorded
     */
    public function applyOrderPayments(Invoice $invoice): bool
    {
        if (null === $invoice->getOrder() || null === $invoice->getId()) {
            return false;
        }

        $alreadyPaid = $this->paidAmountProvider->getPaidAmount($invoice);
        $recorded = $this->orderPaymentLedgerSynchronizer->recordMissingPayments($invoice, $alreadyPaid);

        if ($recorded <= 0.0 && $alreadyPaid <= 0.0) {
            return false;
        }

        if ($recorded > 0.0) {
            $this->entityManager->flush();
        }

        $this->paidAmountProvider->applyTo($invoice);

        if ($recorded > 0.0 && $this->isDraftPaidInFull($invoice)) {
            $invoice->setStatus(Invoice::STATUS_PAID);
            $invoice->setPostedAt(new \DateTime());
        }

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $recorded > 0.0;
    }

    /**
     * Returns whether a draft's order payments already cover its total, compared exactly to the cent.
     *
     * @param Invoice $invoice
     * @return bool
     */
    private function isDraftPaidInFull(Invoice $invoice): bool
    {
        return $invoice->getStatus() === Invoice::STATUS_DRAFT
            && $invoice->getAmount() > 0.0
            && Money::compare($invoice->getAmountPaid(), $invoice->getAmount()) >= 0;
    }

    /**
     * Deletes an invoice, enforcing the same rules the controller previously checked inline.
     *
     * @param Invoice $invoice
     */
    public function delete(Invoice $invoice): void
    {
        if ($invoice->getAmountPaid() > 0) {
            throw new \LogicException('softsolutions4u.invoice.messages.delete_has_payments');
        }

        if ($invoice->getStatus() !== Invoice::STATUS_DRAFT) {
            throw new \LogicException('softsolutions4u.invoice.messages.delete_not_draft');
        }

        $this->entityManager->remove($invoice);
        $this->entityManager->flush();
    }
}
