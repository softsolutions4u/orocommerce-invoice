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

namespace SoftSolutions4U\Bundle\InvoiceBundle\EventListener;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\Event\InvoicePaymentSuccessEvent;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoicePaymentCompletionManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use Psr\Log\LoggerInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\Money;

/**
 * Recalculates invoice paid amounts after a successful storefront payment.
 */
class RecalculateInvoicePaidAmountsListener
{
    /**
     * Creates a new RecalculateInvoicePaidAmountsListener instance.
     *
     * @param ManagerRegistry $registry
     * @param LoggerInterface $logger
     * @param InvoicePaidAmountProvider $paidAmountProvider
     * @param OrderPaymentTransactionSyncGuard $syncGuard
     * @param InvoicePaymentCompletionManager $completionManager
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly InvoicePaidAmountProvider $paidAmountProvider,
        private readonly OrderPaymentTransactionSyncGuard $syncGuard,
        private readonly InvoicePaymentCompletionManager $completionManager,
    ) {
    }

    /**
     * Recalculates the given data.
     *
     * @param InvoicePaymentSuccessEvent $event
     */
    public function recalculate(InvoicePaymentSuccessEvent $event): void
    {
        $invoicePayment = $event->getInvoicePayment();

        if (!$invoicePayment->isActive()) {
            $this->logger->info(
                'RecalculateInvoicePaidAmountsListener: InvoicePayment {invoicePaymentId} already processed - '
                . 'skipping.',
                ['invoicePaymentId' => $invoicePayment->getId()]
            );

            return;
        }

        $em = $this->registry->getManagerForClass(Invoice::class);

        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        /** @var array<int, Invoice> $invoices */
        $invoices = [];

        foreach ($invoicePayment->getLineItems() as $lineItem) {
            $invoice = $lineItem->getInvoice();

            if ($invoice instanceof Invoice) {
                $invoices[(int) $invoice->getId()] = $invoice;
            }
        }

        if (!$invoices) {
            $this->logger->warning(
                'RecalculateInvoicePaidAmountsListener: InvoicePayment {invoicePaymentId} has no line items '
                . 'pointing at an Invoice - nothing to credit.',
                ['invoicePaymentId' => $invoicePayment->getId()]
            );

            return;
        }

        if (null === $invoicePayment->getInvoice() && count($invoices) === 1) {
            $invoicePayment->setInvoice(reset($invoices));
        }

        // Commit as processed before recalculating: the paid total is read back from the database.
        $invoicePayment->setActive(false);
        $em->persist($invoicePayment);
        $em->flush();

        $this->logger->info(
            'RecalculateInvoicePaidAmountsListener: processing InvoicePayment {invoicePaymentId} with '
            . '{lineItemCount} line item(s).',
            [
                'invoicePaymentId' => $invoicePayment->getId(),
                'lineItemCount' => $invoicePayment->getLineItems()->count(),
            ]
        );

        foreach ($invoicePayment->getLineItems() as $lineItem) {
            try {
                $this->recordOrderPaymentTransaction($lineItem, $invoicePayment, $em);
            } catch (\Throwable $exception) {
                $this->logger->error(
                    'RecalculateInvoicePaidAmountsListener: failed to record Order PaymentTransaction for '
                    . 'InvoicePayment {invoicePaymentId}: {message}',
                    [
                        'invoicePaymentId' => $invoicePayment->getId(),
                        'message' => $exception->getMessage(),
                        'exception' => $exception,
                    ]
                );
            }
        }

        foreach ($invoices as $invoice) {
            $this->paidAmountProvider->applyTo($invoice);
            $em->persist($invoice);

            $this->logger->info(
                'RecalculateInvoicePaidAmountsListener: Invoice {invoiceId} amountPaid={amountPaid} '
                . 'balance={balance} paymentStatus={paymentStatus}.',
                [
                    'invoiceId' => $invoice->getId(),
                    'amountPaid' => $invoice->getAmountPaid(),
                    'balance' => $invoice->getBalance(),
                    'paymentStatus' => $invoice->getPaymentStatus(),
                ]
            );
        }

        $em->flush();

        // Must run after the flush above: the Order status is derived from the committed transaction.
        foreach ($invoices as $invoice) {
            $this->completionManager->onPaymentApplied($invoice);
        }
    }

    /**
     * Writes the Order-side payment transaction mirroring an invoice payment line.
     *
     * @param InvoicePaymentLineItem $lineItem
     * @param InvoicePayment $invoicePayment
     * @param EntityManagerInterface $em
     */
    private function recordOrderPaymentTransaction(
        InvoicePaymentLineItem $lineItem,
        InvoicePayment $invoicePayment,
        EntityManagerInterface $em
    ): void {
        $invoice = $lineItem->getInvoice();

        if (!$invoice instanceof Invoice) {
            return;
        }

        $order = $invoice->getOrder();

        if (!$order instanceof Order) {
            return;
        }

        $amount = Money::round($lineItem->getAmount());

        if ($amount <= 0) {
            return;
        }

        $transaction = new PaymentTransaction();
        $transaction->setEntityClass(Order::class);
        $transaction->setEntityIdentifier($order->getId());
        $transaction->setPaymentMethod((string) $invoicePayment->getPaymentMethod());
        $transaction->setAction('capture');
        $transaction->setAmount((string) $amount);
        $transaction->setCurrency((string) $invoice->getCurrency());
        $transaction->setActive(true);
        $transaction->setSuccessful(true);
        $transaction->setAccessIdentifier(bin2hex(random_bytes(16)));
        $transaction->setAccessToken(bin2hex(random_bytes(16)));

        if (method_exists($transaction, 'setOrganization') && $order->getOrganization()) {
            $transaction->setOrganization($order->getOrganization());
        }

        $this->syncGuard->claim($transaction, $invoicePayment);

        $em->persist($transaction);

        $this->logger->info(
            'RecalculateInvoicePaidAmountsListener: recorded Order {orderId} PaymentTransaction for Invoice '
            . '{invoiceId} amount={amount}.',
            [
                'orderId' => $order->getId(),
                'invoiceId' => $invoice->getId(),
                'amount' => $amount,
            ]
        );
    }
}
