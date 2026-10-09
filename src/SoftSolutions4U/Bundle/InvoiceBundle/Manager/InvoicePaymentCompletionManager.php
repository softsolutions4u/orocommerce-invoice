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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Runs the follow-up work once an invoice payment has been committed.
 */
class InvoicePaymentCompletionManager
{
    /**
     * Creates a new InvoicePaymentCompletionManager instance.
     *
     * @param OrderPaymentStatusUpdater $orderPaymentStatusUpdater
     * @param InvoiceEmailManager $invoiceEmailManager
     * @param ManagerRegistry $registry
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly OrderPaymentStatusUpdater $orderPaymentStatusUpdater,
        private readonly InvoiceEmailManager $invoiceEmailManager,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Call once per Invoice, after the ledger has been flushed AND.
     *
     * @param Invoice $invoice
     */
    public function onPaymentApplied(Invoice $invoice): void
    {
        $this->orderPaymentStatusUpdater->updateForInvoice($invoice);
        $this->sendPaymentConfirmationIfNewlyPaid($invoice);
    }

    /**
     * Sends the paid-in-full confirmation the first time an invoice clears.
     *
     * @param Invoice $invoice
     */
    private function sendPaymentConfirmationIfNewlyPaid(Invoice $invoice): void
    {
        if (!$invoice->isBalancePaid()) {
            return;
        }

        if (null !== $invoice->getPaidNotificationSentAt()) {
            return;
        }

        if ($invoice->getStatus() === Invoice::STATUS_DRAFT) {
            $this->logger->info(
                'InvoicePaymentCompletionManager: Invoice {invoiceId} is fully paid but still draft - confirmation '
                . 'email not sent.',
                ['invoiceId' => $invoice->getId()]
            );

            return;
        }

        try {
            $this->invoiceEmailManager->sendPaymentConfirmation($invoice);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'InvoicePaymentCompletionManager: could not send payment confirmation for Invoice {invoiceId}: '
                . '{message}',
                [
                    'invoiceId' => $invoice->getId(),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]
            );

            return;
        }

        $invoice->setPaidNotificationSentAt(new \DateTime('now', new \DateTimeZone('UTC')));

        $em = $this->registry->getManagerForClass(Invoice::class);

        if ($em instanceof EntityManagerInterface) {
            $em->persist($invoice);
            $em->flush();
        }

        $this->logger->info(
            'InvoicePaymentCompletionManager: payment confirmation sent for Invoice {invoiceId}.',
            ['invoiceId' => $invoice->getId()]
        );
    }
}
