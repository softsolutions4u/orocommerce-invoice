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
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use Doctrine\ORM\EntityManagerInterface;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use Psr\Log\LoggerInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\Money;

/**
 * Records an order's payments on its invoice when they were taken before the invoice existed.
 *
 * A customer who pays at checkout is charged before anyone creates the invoice.
 * OrderPaymentTransactionListener only mirrors a transaction onto an invoice
 * that already exists, so those earlier payments never reached the invoice
 * ledger and the invoice looked unpaid. This closes that gap.
 *
 * Only money that was actually taken counts: successful capture, purchase or
 * charge transactions - the same actions the listener mirrors. Authorisations
 * and Payment Term "invoice" transactions are promises to pay, not payments.
 *
 * Safe to run repeatedly: a transaction already in the ledger (matched on
 * paymentTransactionId) is skipped, and nothing beyond the invoice total is
 * recorded. The caller flushes.
 */
class OrderPaymentLedgerSynchronizer
{
    /** Transaction actions that move money, mirroring OrderPaymentTransactionListener. */
    public const PAID_ACTIONS = ['capture', 'purchase', 'charge'];

    /**
     * Creates a new OrderPaymentLedgerSynchronizer instance.
     *
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Adds ledger entries for the order's payments that the invoice does not have yet.
     *
     * @param float $alreadyPaid what the invoice ledger already holds
     * @param Invoice $invoice
     * @return float the amount newly recorded (0.0 when nothing was missing)
     *
     */
    public function recordMissingPayments(Invoice $invoice, float $alreadyPaid): float
    {
        $order = $invoice->getOrder();

        if (!$order instanceof Order || null === $order->getId() || null === $invoice->getId()) {
            return 0.0;
        }

        $remaining = Money::subtract($invoice->getAmount(), $alreadyPaid);
        $recorded = 0.0;

        foreach ($this->findPaidTransactions($order) as $transaction) {
            if ($remaining <= 0.0) {
                break;
            }

            if ($this->isAlreadyRecorded($transaction)) {
                continue;
            }

            $amount = min(Money::round($transaction->getAmount()), $remaining);

            if ($amount <= 0.0) {
                continue;
            }

            $this->entityManager->persist($this->createPayment($invoice, $order, $transaction, $amount));

            $remaining = Money::subtract($remaining, $amount);
            $recorded = Money::add($recorded, $amount);

            $this->logger->info(
                'OrderPaymentLedgerSynchronizer: recorded PaymentTransaction {transactionId} from Order {orderId} '
                . 'on Invoice {invoiceId}, amount {amount}.',
                [
                    'transactionId' => $transaction->getId(),
                    'orderId' => $order->getId(),
                    'invoiceId' => $invoice->getId(),
                    'amount' => $amount,
                ]
            );
        }

        return $recorded;
    }

    /**
     * Finds the paid transactions.
     *
     * @param Order $order
     * @return array<int, PaymentTransaction> successful money-moving transactions, oldest first
     */
    private function findPaidTransactions(Order $order): array
    {
        try {
            $transactions = $this->entityManager->getRepository(PaymentTransaction::class)->findBy(
                [
                    'entityClass' => Order::class,
                    'entityIdentifier' => $order->getId(),
                    'successful' => true,
                ],
                ['id' => 'ASC']
            );
        } catch (\Throwable $exception) {
            // Loading a transaction decrypts its secure fields. That fails for data
            // encrypted with a different application secret (e.g. a database copied
            // from another installation). Fall back to loading only the transactions
            // that moved money, one by one, skipping the unreadable ones.
            $this->logger->warning(
                'OrderPaymentLedgerSynchronizer: payment transactions of Order {orderId} could not be loaded '
                . 'in one query, loading them one by one: {message}',
                ['orderId' => $order->getId(), 'message' => $exception->getMessage()]
            );
            $transactions = $this->findPaidTransactionsOneByOne($order);
        }

        return array_values(array_filter(
            $transactions,
            static fn (PaymentTransaction $transaction): bool => $transaction->isSuccessful()
                && in_array($transaction->getAction(), self::PAID_ACTIONS, true)
        ));
    }

    /**
     * Loads the order's successful money-moving transactions one at a time.
     *
     * The ids are selected with a scalar query (no decryption), so transactions
     * that did not move money are never hydrated, and an unreadable transaction
     * is skipped and logged instead of breaking the whole synchronisation.
     *
     * @param Order $order
     * @return array<int, PaymentTransaction>
     */
    private function findPaidTransactionsOneByOne(Order $order): array
    {
        $ids = $this->entityManager->createQueryBuilder()
            ->select('transaction.id')
            ->from(PaymentTransaction::class, 'transaction')
            ->where('transaction.entityClass = :entityClass')
            ->andWhere('transaction.entityIdentifier = :entityIdentifier')
            ->andWhere('transaction.successful = true')
            ->andWhere('transaction.action IN (:actions)')
            ->setParameter('entityClass', Order::class)
            ->setParameter('entityIdentifier', (int) $order->getId())
            ->setParameter('actions', self::PAID_ACTIONS)
            ->orderBy('transaction.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        $transactions = [];
        foreach ($ids as $id) {
            try {
                $transaction = $this->entityManager->find(PaymentTransaction::class, (int) $id);
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    'OrderPaymentLedgerSynchronizer: skipped unreadable PaymentTransaction {transactionId}: {message}',
                    ['transactionId' => $id, 'message' => $exception->getMessage()]
                );
                continue;
            }
            if ($transaction instanceof PaymentTransaction) {
                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }

    /**
     * Returns whether it is already recorded.
     *
     * @param PaymentTransaction $transaction
     * @return bool
     */
    private function isAlreadyRecorded(PaymentTransaction $transaction): bool
    {
        if (null === $transaction->getId()) {
            return false;
        }

        return null !== $this->entityManager->getRepository(InvoicePayment::class)
            ->findOneBy(['paymentTransactionId' => $transaction->getId()]);
    }

    /**
     * Creates the payment.
     *
     * @param Invoice $invoice
     * @param Order $order
     * @param PaymentTransaction $transaction
     * @param float $amount
     * @return InvoicePayment
     */
    private function createPayment(
        Invoice $invoice,
        Order $order,
        PaymentTransaction $transaction,
        float $amount
    ): InvoicePayment {
        $currency = (string) ($invoice->getCurrency() ?: $transaction->getCurrency());

        $payment = new InvoicePayment();
        $payment
            ->setPaymentMethod((string) $transaction->getPaymentMethod())
            ->setPaymentTransactionId($transaction->getId())
            // Processed: the money was taken at checkout. InvoicePaidAmountProvider
            // only counts payments with active = false.
            ->setActive(false);
        $payment->setInvoice($invoice);

        $customerUser = $invoice->getCustomerUser() ?? $order->getCustomerUser();
        if (null !== $customerUser) {
            $payment->setCustomerUser($customerUser);
        }

        $customer = $invoice->getCustomer() ?? $order->getCustomer();
        if (null !== $customer) {
            $payment->setCustomer($customer);
        }

        if (null !== $invoice->getOrganization()) {
            $payment->setOrganization($invoice->getOrganization());
        }

        $lineItem = new InvoicePaymentLineItem();
        $lineItem
            ->setInvoice($invoice)
            ->setInvoicePayment($payment)
            ->setAmount($amount)
            ->setCurrency($currency);

        $payment->addLineItem($lineItem);
        $payment->setCurrency($currency);

        return $payment;
    }
}
