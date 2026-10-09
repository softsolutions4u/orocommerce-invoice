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
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PaymentBundle\Manager\PaymentStatusManager;
use Psr\Log\LoggerInterface;

/**
 * Recomputes and persists the Order-side payment status after a payment is recorded.
 */
class OrderPaymentStatusUpdater
{
    /**
     * Creates a new OrderPaymentStatusUpdater instance.
     *
     * @param PaymentStatusManager $paymentStatusManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PaymentStatusManager $paymentStatusManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Updates for invoice.
     *
     * @param Invoice $invoice
     * @return string|null the status now stored, or null if nothing was done
     */
    public function updateForInvoice(Invoice $invoice): ?string
    {
        $order = $invoice->getOrder();

        if (!$order instanceof Order) {
            return null;
        }

        return $this->updateForOrder($order);
    }

    /**
     * Updates for order.
     *
     * @param Order $order
     * @return string|null the resulting status code, or null if it could not be set
     */
    public function updateForOrder(Order $order): ?string
    {
        if (null === $order->getId()) {
            return null;
        }

        try {
            $paymentStatus = $this->paymentStatusManager->updatePaymentStatus($order);

            $status = $paymentStatus->getPaymentStatus();

            $this->logger->info(
                'OrderPaymentStatusUpdater: Order {orderId} payment status recalculated to {status}.',
                [
                    'orderId' => $order->getId(),
                    'status' => $status,
                ]
            );

            return $status;
        } catch (\Throwable $exception) {
            $this->logger->error(
                'OrderPaymentStatusUpdater: failed to update payment status for Order {orderId}: {message}',
                [
                    'orderId' => $order->getId(),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]
            );

            return null;
        }
    }
}
