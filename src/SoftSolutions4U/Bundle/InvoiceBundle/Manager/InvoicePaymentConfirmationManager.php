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

use Psr\Log\LoggerInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Event\InvoicePaymentSuccessEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Applies offline invoice payments (Payment Term, Money Order, ...) once an administrator confirms them.
 */
class InvoicePaymentConfirmationManager
{
    public const ERROR_NOTHING_TO_CONFIRM = 'softsolutions4u.invoice.messages.no_pending_payments';

    /**
     * Creates a new InvoicePaymentConfirmationManager instance.
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Confirms every payment of the invoice that awaits confirmation and credits it to the invoice.
     *
     * @param Invoice $invoice
     * @return int Number of confirmed payments.
     * @throws \LogicException When the invoice has no payment awaiting confirmation.
     */
    public function confirmPendingPayments(Invoice $invoice): int
    {
        $payments = $invoice->getPendingConfirmationPayments();

        if (!$payments) {
            throw new \LogicException(self::ERROR_NOTHING_TO_CONFIRM);
        }

        foreach ($payments as $payment) {
            $payment->setPendingConfirmation(false);

            $this->eventDispatcher->dispatch(
                new InvoicePaymentSuccessEvent($payment, ['successful' => true, 'confirmedManually' => true]),
                InvoicePaymentSuccessEvent::NAME
            );

            $this->logger->info(
                'Invoice {invoiceNo}: payment {paymentId} confirmed by an administrator.',
                ['invoiceNo' => $invoice->getInvoiceNo(), 'paymentId' => $payment->getId()]
            );
        }

        return count($payments);
    }
}
