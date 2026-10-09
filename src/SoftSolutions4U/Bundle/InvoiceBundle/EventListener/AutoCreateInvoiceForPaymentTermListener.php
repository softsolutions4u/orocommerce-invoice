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

use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\Configuration;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceManager;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Auto create invoice for payment term listener.
 */
class AutoCreateInvoiceForPaymentTermListener implements ResetInterface
{
    private const PAYMENT_TERM_PREFIX = 'payment_term_';

    /** Payment Term records the order as pending payment. */
    private const TRIGGER_ACTION = 'pending';

    /** @var array<int, int> orderId => orderId, queued during flush */
    private array $queue = [];

    /** @var bool $processing */
    private bool $processing = false;

    /**
     * Creates a new AutoCreateInvoiceForPaymentTermListener instance.
     *
     * @param ManagerRegistry $registry
     * @param InvoiceManager $invoiceManager
     * @param ConfigManager $configManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly InvoiceManager $invoiceManager,
        private readonly ConfigManager $configManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Queues a newly persisted payment term transaction for invoice creation.
     *
     * @param PaymentTransaction $transaction
     * @param PostPersistEventArgs $args
     */
    public function postPersist(PaymentTransaction $transaction, PostPersistEventArgs $args): void
    {
        if (!$this->isPaymentTermOrderPlacement($transaction)) {
            return;
        }

        $orderId = (int) $transaction->getEntityIdentifier();

        if ($orderId > 0) {
            $this->queue[$orderId] = $orderId;
        }
    }

    /**
     * Creates invoices for the transactions queued during this flush.
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
                $this->createInvoiceFor($orderId);
            }
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Returns whether payment term order placement.
     *
     * @param PaymentTransaction $transaction
     * @return bool
     */
    private function isPaymentTermOrderPlacement(PaymentTransaction $transaction): bool
    {
        if ($transaction->getEntityClass() !== Order::class) {
            return false;
        }

        if ($transaction->getAction() !== self::TRIGGER_ACTION) {
            return false;
        }

        return str_starts_with((string) $transaction->getPaymentMethod(), self::PAYMENT_TERM_PREFIX);
    }

    /**
     * Never allowed to throw.
     *
     * @param int $orderId
     */
    private function createInvoiceFor(int $orderId): void
    {
        try {
            if (!$this->isEnabled()) {
                return;
            }

            $em = $this->registry->getManagerForClass(Order::class);

            if (!$em instanceof EntityManagerInterface) {
                return;
            }

            $order = $em->getRepository(Order::class)->find($orderId);

            if (!$order instanceof Order) {
                return;
            }

            try {
                $this->invoiceManager->assertCanCreateForOrder($order);
            } catch (\LogicException $exception) {
                $this->logger->info(
                    'Auto-invoice skipped for Order {orderId}: {reason}',
                    ['orderId' => $orderId, 'reason' => $exception->getMessage()]
                );

                return;
            }

            $invoice = $this->invoiceManager->createFromOrder($order);

            $this->invoiceManager->save($invoice);

            $this->logger->info(
                'Auto-created draft Invoice {invoiceNo} for Payment Term Order {orderId}.',
                [
                    'invoiceNo' => $invoice->getInvoiceNo(),
                    'orderId' => $orderId,
                    'status' => Invoice::STATUS_DRAFT,
                ]
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Failed to auto-create Invoice for Payment Term Order {orderId}: {message}',
                [
                    'orderId' => $orderId,
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]
            );
        }
    }

    /**
     * Returns whether enabled.
     *
     * @return bool
     */
    private function isEnabled(): bool
    {
        if (!$this->configManager->get(Configuration::getConfigKeyByName(Configuration::INVOICE_ENABLED))) {
            return false;
        }

        return (bool) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::AUTO_CREATE_FOR_PAYMENT_TERM)
        );
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
