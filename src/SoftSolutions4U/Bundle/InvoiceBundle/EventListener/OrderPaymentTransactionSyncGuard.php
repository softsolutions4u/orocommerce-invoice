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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Breaks the feedback loop between the storefront and Order payment sync paths.
 */
class OrderPaymentTransactionSyncGuard implements ResetInterface
{
    /** @var \SplObjectStorage $claims */
    private \SplObjectStorage $claims;

    /** @var array<int, PaymentTransaction> */
    private array $queue = [];

    /** Re-entrancy guard: draining the queue flushes, which fires postFlush again. */
    private bool $processing = false;

    /**
     * Creates a new OrderPaymentTransactionSyncGuard instance.
     */
    public function __construct()
    {
        $this->claims = new \SplObjectStorage();
    }

    /**
     * Declare that $transaction is being written as the Order-side record of an InvoicePayment that.
     *
     * @param PaymentTransaction $transaction
     * @param InvoicePayment $invoicePayment
     */
    public function claim(PaymentTransaction $transaction, InvoicePayment $invoicePayment): void
    {
        $this->claims[$transaction] = $invoicePayment;
    }

    /**
     * Returns whether claimed.
     *
     * @param PaymentTransaction $transaction
     * @return bool
     */
    public function isClaimed(PaymentTransaction $transaction): bool
    {
        return $this->claims->offsetExists($transaction);
    }

    /**
     * Returns the claimed invoice payment.
     *
     * @param PaymentTransaction $transaction
     * @return InvoicePayment|null
     */
    public function getClaimedInvoicePayment(PaymentTransaction $transaction): ?InvoicePayment
    {
        return $this->claims->offsetExists($transaction) ? $this->claims[$transaction] : null;
    }

    /**
     * Consume the claim.
     *
     * @param PaymentTransaction $transaction
     */
    public function release(PaymentTransaction $transaction): void
    {
        // offsetExists()/offsetUnset() rather than contains()/detach(), which
        // PHP 8.5 deprecates; they behave identically on every PHP version.
        if ($this->claims->offsetExists($transaction)) {
            $this->claims->offsetUnset($transaction);
        }
    }

    /**
     * Queue a transaction for processing after the current flush completes.
     *
     * @param PaymentTransaction $transaction
     */
    public function enqueue(PaymentTransaction $transaction): void
    {
        $this->queue[spl_object_id($transaction)] = $transaction;
    }

    /**
     * Returns whether processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->processing;
    }

    /**
     * Returns the drain queue.
     *
     * @return array<int, PaymentTransaction>
     */
    public function drainQueue(): array
    {
        $queue = $this->queue;
        $this->queue = [];

        return $queue;
    }

    /**
     * Marks the start of a sync run, so nested events are not processed twice.
     */
    public function beginProcessing(): void
    {
        $this->processing = true;
    }

    /**
     * Marks the end of a sync run.
     */
    public function endProcessing(): void
    {
        $this->processing = false;
    }

    /**
     * Clears claims and queued transactions between requests and consumer messages.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->claims = new \SplObjectStorage();
        $this->queue = [];
        $this->processing = false;
    }
}
