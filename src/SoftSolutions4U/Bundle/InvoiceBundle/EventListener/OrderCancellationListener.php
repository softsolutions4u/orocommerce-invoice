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
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceCancellationManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\OrderCancellationChecker;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\OrderBundle\Entity\Order;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Order cancellation listener.
 */
class OrderCancellationListener implements ResetInterface
{
    /** @var array<int, int> orderId => orderId, queued during flush */
    private array $queue = [];

    /** @var bool $processing */
    private bool $processing = false;

    /**
     * Creates a new OrderCancellationListener instance.
     *
     * @param ManagerRegistry $registry
     * @param InvoiceCancellationManager $invoiceCancellationManager
     * @param OrderCancellationChecker $orderCancellationChecker
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly InvoiceCancellationManager $invoiceCancellationManager,
        private readonly OrderCancellationChecker $orderCancellationChecker,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Queues a just-updated order for its invoice to be cancelled, if it is now cancelled.
     *
     * @param Order $order
     * @param PostUpdateEventArgs $args
     */
    public function postUpdate(Order $order, PostUpdateEventArgs $args): void
    {
        if (!$this->orderCancellationChecker->isCancelled($order)) {
            return;
        }

        $orderId = (int) $order->getId();

        if ($orderId > 0) {
            $this->queue[$orderId] = $orderId;
        }
    }

    /**
     * Cancels invoices for the orders queued during this flush.
     */
    public function postFlush(): void
    {
        if ($this->processing || !$this->queue) {
            return;
        }

        $queue = $this->queue;
        $this->queue = [];

        $this->processing = true;

        try {
            foreach ($queue as $orderId) {
                $this->cancelInvoiceFor($orderId);
            }
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Never allowed to throw.
     *
     * @param int $orderId
     */
    private function cancelInvoiceFor(int $orderId): void
    {
        try {
            $em = $this->registry->getManagerForClass(Invoice::class);

            if (!$em instanceof EntityManagerInterface) {
                return;
            }

            /** @var \SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository $repository */
            $repository = $em->getRepository(Invoice::class);
            $invoice = $repository->findOneByOrderId($orderId);

            if (!$invoice instanceof Invoice) {
                return;
            }

            $this->invoiceCancellationManager->cancelForOrder($invoice);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Failed to cancel Invoice for cancelled Order {orderId}: {message}',
                [
                    'orderId' => $orderId,
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]
            );
        }
    }

    /**
     * Clears queued work between requests and consumer messages, so nothing leaks after an error.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->queue = [];
        $this->processing = false;
    }
}
