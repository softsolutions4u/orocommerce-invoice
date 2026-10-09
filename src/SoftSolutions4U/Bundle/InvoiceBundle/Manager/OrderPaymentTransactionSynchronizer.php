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
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\InvoicePaymentFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use Psr\Log\LoggerInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\OrderPaymentTransactionSyncGuard;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\Money;

/**
 * Mirrors successful order and invoice payment transactions into the invoice ledger.
 *
 * Called by OrderPaymentTransactionListener after a flush; holds all ledger logic so it can be tested on its own.
 */
class OrderPaymentTransactionSynchronizer
{
    private const RELEVANT_ACTIONS = ['capture', 'purchase', 'charge'];

    /**
     * Creates a new OrderPaymentTransactionSynchronizer instance.
     *
     * @param ManagerRegistry $registry
     * @param LoggerInterface $logger
     * @param InvoicePaymentFactory $invoicePaymentFactory
     * @param InvoicePaidAmountProvider $paidAmountProvider
     * @param OrderPaymentTransactionSyncGuard $syncGuard
     * @param InvoicePaymentCompletionManager $completionManager
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly InvoicePaymentFactory $invoicePaymentFactory,
        private readonly InvoicePaidAmountProvider $paidAmountProvider,
        private readonly OrderPaymentTransactionSyncGuard $syncGuard,
        private readonly InvoicePaymentCompletionManager $completionManager,
    ) {
    }

    /**
     * Returns whether the transaction moves money for an order or an invoice payment.
     *
     * @param PaymentTransaction $transaction
     * @return bool
     */
    public function isRelevant(PaymentTransaction $transaction): bool
    {
        if (!$transaction->isSuccessful()) {
            return false;
        }

        if (!in_array($transaction->getAction(), self::RELEVANT_ACTIONS, true)) {
            return false;
        }

        return in_array($transaction->getEntityClass(), [Order::class, InvoicePayment::class], true);
    }

    /**
     * Mirrors the queued transactions into the invoice ledger.
     *
     * @param array<int, PaymentTransaction> $queue
     */
    public function process(array $queue): void
    {
        $em = $this->registry->getManagerForClass(Invoice::class);

        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        /** @var array<int, Invoice> $affectedInvoices */
        $affectedInvoices = [];

        // Phase 1 - write ledger records (or link existing ones).
        foreach ($queue as $transaction) {
            foreach ($this->safeSync($transaction, $em) as $invoice) {
                $affectedInvoices[(int) $invoice->getId()] = $invoice;
            }
        }

        if (!$affectedInvoices) {
            return;
        }

        $em->flush();

        foreach ($affectedInvoices as $invoice) {
            $this->paidAmountProvider->applyTo($invoice);
            $em->persist($invoice);

            $this->logger->info(
                'Invoice {invoiceId} recalculated from payment ledger: amountPaid={amountPaid}, balance={balance}, '
                . 'paymentStatus={paymentStatus}.',
                [
                    'invoiceId' => $invoice->getId(),
                    'amountPaid' => $invoice->getAmountPaid(),
                    'balance' => $invoice->getBalance(),
                    'paymentStatus' => $invoice->getPaymentStatus(),
                ]
            );
        }

        $em->flush();

        foreach ($affectedInvoices as $invoice) {
            $this->completionManager->onPaymentApplied($invoice);
        }
    }

    /**
     * Returns the safe sync.
     *
     * @param PaymentTransaction $transaction
     * @param EntityManagerInterface $em
     * @return array<int, Invoice> invoices touched by this transaction
     */
    private function safeSync(PaymentTransaction $transaction, EntityManagerInterface $em): array
    {
        try {
            if ($transaction->getEntityClass() === Order::class) {
                return $this->syncFromOrder($transaction, $em);
            }

            if ($transaction->getEntityClass() === InvoicePayment::class) {
                return $this->syncFromInvoicePayment($transaction, $em);
            }
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Failed to sync Invoice from PaymentTransaction {transactionId}: {message}',
                [
                    'transactionId' => $transaction->getId(),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]
            );
        }

        return [];
    }

    /**
     * Picks the invoice to credit.
     *
     * @param array<int, Invoice> $invoices ordered by id ascending
     * @return Invoice|null
     */
    private function pickInvoiceToCredit(array $invoices): ?Invoice
    {
        foreach ($invoices as $invoice) {
            if (!$invoice->isCancelled()) {
                return $invoice;
            }
        }

        return null;
    }

    /**
     * Syncs from order.
     *
     * @param PaymentTransaction $transaction
     * @param EntityManagerInterface $em
     * @return array<int, Invoice>
     */
    private function syncFromOrder(PaymentTransaction $transaction, EntityManagerInterface $em): array
    {
        $orderId = $transaction->getEntityIdentifier();

        if (!$orderId) {
            return [];
        }

        $order = $em->getRepository(Order::class)->find($orderId);

        if (!$order instanceof Order) {
            $this->logger->warning(
                'Unable to synchronize payment transaction {transactionId}: Order {orderId} was not found.',
                ['transactionId' => $transaction->getId(), 'orderId' => $orderId]
            );

            return [];
        }

        $invoices = $em->getRepository(Invoice::class)->findBy(['order' => $order], ['id' => 'ASC']);

        if (!$invoices) {
            $this->logger->info(
                'No invoice found for Order {orderId}. PaymentTransaction {transactionId} will not be synchronized.',
                ['orderId' => $order->getId(), 'transactionId' => $transaction->getId()]
            );

            return [];
        }

        $invoice = $this->pickInvoiceToCredit($invoices);

        if (!$invoice instanceof Invoice) {
            $this->logger->info(
                'Order {orderId} has only cancelled invoices. PaymentTransaction {transactionId} '
                . 'will not be synchronized.',
                ['orderId' => $order->getId(), 'transactionId' => $transaction->getId()]
            );

            return [];
        }

        $claimedInvoicePayment = $this->syncGuard->getClaimedInvoicePayment($transaction);

        if ($claimedInvoicePayment instanceof InvoicePayment) {
            $this->syncGuard->release($transaction);

            if (null === $claimedInvoicePayment->getPaymentTransactionId()) {
                $claimedInvoicePayment->setPaymentTransactionId($transaction->getId());
                $em->persist($claimedInvoicePayment);
            }

            $this->logger->info(
                'PaymentTransaction {transactionId} is the Order-side record of storefront InvoicePayment '
                . '{invoicePaymentId} - linked, not duplicated.',
                [
                    'transactionId' => $transaction->getId(),
                    'invoicePaymentId' => $claimedInvoicePayment->getId(),
                ]
            );

            return [$invoice];
        }

        $existingInvoicePayment = $em->getRepository(InvoicePayment::class)
            ->findOneBy(['paymentTransactionId' => $transaction->getId()]);

        if ($existingInvoicePayment instanceof InvoicePayment) {
            return [$invoice];
        }

        // --- Case 1: a genuine Order-side capture.
        $transactionAmount = Money::round($transaction->getAmount());

        if ($transactionAmount <= 0) {
            return [];
        }

        $orderCustomerUser = $order->getCustomerUser();

        if (!$orderCustomerUser instanceof CustomerUser) {
            $this->logger->warning(
                'Unable to synchronize payment transaction {transactionId}: Order {orderId} has no CustomerUser.',
                ['transactionId' => $transaction->getId(), 'orderId' => $order->getId()]
            );

            return [];
        }

        $remaining = $this->paidAmountProvider->getRemainingBalance($invoice);
        $paymentAmount = min($transactionAmount, $remaining);

        if ($paymentAmount < $transactionAmount) {
            $this->logger->warning(
                'PaymentTransaction {transactionId} amount {transactionAmount} exceeds Invoice {invoiceId} '
                . 'remaining balance {remaining} - recording {paymentAmount}.',
                [
                    'transactionId' => $transaction->getId(),
                    'transactionAmount' => $transactionAmount,
                    'invoiceId' => $invoice->getId(),
                    'remaining' => $remaining,
                    'paymentAmount' => $paymentAmount,
                ]
            );
        }

        if ($paymentAmount <= 0) {
            return [$invoice];
        }

        $invoicePayment = new InvoicePayment();
        $invoicePayment
            ->setCustomerUser($orderCustomerUser)
            ->setCustomer($orderCustomerUser->getCustomer() ?? $order->getCustomer())
            ->setCurrency($invoice->getCurrency())
            ->setPaymentMethod((string) $transaction->getPaymentMethod())
            ->setPaymentTransactionId($transaction->getId())
            ->setActive(false);

        $invoicePayment->setInvoice($invoice);

        if ($invoice->getOrganization()) {
            $invoicePayment->setOrganization($invoice->getOrganization());
        }

        $this->invoicePaymentFactory->createInvoicePaymentLineItem($invoice, $invoicePayment, $paymentAmount);
        $invoicePayment->recalculateAmount();

        $em->persist($invoicePayment);

        $this->logger->info(
            'Order payment synchronized to invoice ledger. Transaction {transactionId}, Order {orderId}, Invoice '
            . '{invoiceId}, amount {amount}.',
            [
                'transactionId' => $transaction->getId(),
                'orderId' => $order->getId(),
                'invoiceId' => $invoice->getId(),
                'amount' => $paymentAmount,
            ]
        );

        return [$invoice];
    }

    /**
     * Links a claimed transaction back to the invoice payment that created it.
     *
     * @param PaymentTransaction $transaction
     * @param EntityManagerInterface $em
     * @return array
     */
    private function syncFromInvoicePayment(PaymentTransaction $transaction, EntityManagerInterface $em): array
    {
        $invoicePayment = $em->getRepository(InvoicePayment::class)->find($transaction->getEntityIdentifier());

        if (!$invoicePayment instanceof InvoicePayment) {
            return [];
        }

        if ($invoicePayment->isActive()) {
            $invoicePayment->setActive(false);
            $em->persist($invoicePayment);

            $this->logger->info(
                'InvoicePayment {invoicePaymentId} marked processed from successful PaymentTransaction '
                . '{transactionId}.',
                [
                    'invoicePaymentId' => $invoicePayment->getId(),
                    'transactionId' => $transaction->getId(),
                    'action' => $transaction->getAction(),
                ]
            );

            $this->mirrorToOrder($invoicePayment, $em);
        }

        if (null === $invoicePayment->getPaymentTransactionId()) {
            $invoicePayment->setPaymentTransactionId($transaction->getId());
            $em->persist($invoicePayment);
        }

        $invoices = [];

        foreach ($invoicePayment->getLineItems() as $lineItem) {
            $invoice = $lineItem->getInvoice();

            if ($invoice instanceof Invoice) {
                $invoices[(int) $invoice->getId()] = $invoice;
            }
        }

        if (null === $invoicePayment->getInvoice() && count($invoices) === 1) {
            $invoicePayment->setInvoice(reset($invoices));
            $em->persist($invoicePayment);
        }

        return $invoices;
    }

    /**
     * Creates a PaymentTransaction against the Invoice's Order for a payment that arrived through a.
     *
     * @param InvoicePayment $invoicePayment
     * @param EntityManagerInterface $em
     */
    private function mirrorToOrder(InvoicePayment $invoicePayment, EntityManagerInterface $em): void
    {
        foreach ($invoicePayment->getLineItems() as $lineItem) {
            if (!$lineItem instanceof InvoicePaymentLineItem) {
                continue;
            }

            $invoice = $lineItem->getInvoice();

            if (!$invoice instanceof Invoice) {
                continue;
            }

            $order = $invoice->getOrder();

            if (!$order instanceof Order || null === $order->getId()) {
                continue;
            }

            $amount = Money::round($lineItem->getAmount());

            if ($amount <= 0) {
                continue;
            }

            $mirror = new PaymentTransaction();
            $mirror->setEntityClass(Order::class);
            $mirror->setEntityIdentifier($order->getId());
            $mirror->setPaymentMethod((string) $invoicePayment->getPaymentMethod());
            $mirror->setAction('capture');
            $mirror->setAmount((string) $amount);
            $mirror->setCurrency((string) $invoice->getCurrency());
            $mirror->setActive(true);
            $mirror->setSuccessful(true);

            $mirror->setAccessIdentifier(bin2hex(random_bytes(16)));
            $mirror->setAccessToken(bin2hex(random_bytes(16)));

            if (method_exists($mirror, 'setOrganization') && $order->getOrganization()) {
                $mirror->setOrganization($order->getOrganization());
            }

            $this->syncGuard->claim($mirror, $invoicePayment);

            $em->persist($mirror);

            $this->logger->info(
                'Mirrored gateway payment onto Order {orderId} for Invoice {invoiceId} amount={amount}.',
                [
                    'orderId' => $order->getId(),
                    'invoiceId' => $invoice->getId(),
                    'amount' => $amount,
                ]
            );
        }
    }
}
