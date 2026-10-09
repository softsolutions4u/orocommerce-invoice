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

use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\OrderPaymentTransactionSynchronizer;

/**
 * Queues successful payment transactions during a flush and hands them to the ledger synchronizer afterwards.
 */
class OrderPaymentTransactionListener
{
    /**
     * Creates a new OrderPaymentTransactionListener instance.
     *
     * @param OrderPaymentTransactionSyncGuard $syncGuard
     * @param OrderPaymentTransactionSynchronizer $synchronizer
     */
    public function __construct(
        private readonly OrderPaymentTransactionSyncGuard $syncGuard,
        private readonly OrderPaymentTransactionSynchronizer $synchronizer,
    ) {
    }

    /**
     * Queues a newly persisted payment transaction for syncing.
     *
     * @param PaymentTransaction $transaction
     * @param PostPersistEventArgs $args
     */
    public function postPersist(PaymentTransaction $transaction, PostPersistEventArgs $args): void
    {
        $this->enqueue($transaction);
    }

    /**
     * Queues an updated payment transaction for syncing.
     *
     * @param PaymentTransaction $transaction
     * @param PostUpdateEventArgs $args
     */
    public function postUpdate(PaymentTransaction $transaction, PostUpdateEventArgs $args): void
    {
        $this->enqueue($transaction);
    }

    /**
     * Processes the queued transactions once the flush has completed.
     */
    public function postFlush(): void
    {
        if ($this->syncGuard->isProcessing()) {
            return;
        }

        $queue = $this->syncGuard->drainQueue();

        if (!$queue) {
            return;
        }

        $this->syncGuard->beginProcessing();

        try {
            $this->synchronizer->process($queue);
        } finally {
            $this->syncGuard->endProcessing();
        }
    }

    /**
     * Queues a transaction to be handled once the flush completes.
     *
     * @param PaymentTransaction $transaction
     */
    private function enqueue(PaymentTransaction $transaction): void
    {
        if (!$this->synchronizer->isRelevant($transaction)) {
            return;
        }

        $this->syncGuard->enqueue($transaction);
    }
}
