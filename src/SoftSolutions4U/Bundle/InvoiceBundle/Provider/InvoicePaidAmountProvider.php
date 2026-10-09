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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Provider;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\Model\InvoicePaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\Money;

/**
 * Derives an invoice's amountPaid and paymentStatus from the InvoicePayment ledger.
 */
class InvoicePaidAmountProvider
{
    /**
     * Creates a new InvoicePaidAmountProvider instance.
     *
     * @param ManagerRegistry $registry
     */
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    /**
     * Total actually paid against this Invoice, summed over the line items of every processed.
     *
     * @param Invoice $invoice
     * @return float
     */
    public function getPaidAmount(Invoice $invoice): float
    {
        if (null === $invoice->getId()) {
            return 0.0;
        }

        $em = $this->getEntityManager();

        $paid = $em->createQueryBuilder()
            ->select('COALESCE(SUM(lineItem.amount), 0)')
            ->from(InvoicePaymentLineItem::class, 'lineItem')
            ->innerJoin('lineItem.invoicePayment', 'invoicePayment')
            ->where('lineItem.invoice = :invoice')
            // See the class docblock: active = false means processed.
            ->andWhere('invoicePayment.active = :processed')
            ->setParameter('invoice', $invoice)
            ->setParameter('processed', false)
            ->getQuery()
            ->getSingleScalarResult();

        return Money::round($paid);
    }

    /**
     * How much is still owed, according to the ledger.
     *
     * @param Invoice $invoice
     * @return float
     */
    public function getRemainingBalance(Invoice $invoice): float
    {
        return max(0.0, Money::subtract($invoice->getAmount(), $this->getPaidAmount($invoice)));
    }

    /**
     * Applies the derived paid amount, payment status and lifecycle status.
     *
     * @param Invoice $invoice
     * @return float
     */
    public function applyTo(Invoice $invoice): float
    {
        $paid = $this->getPaidAmount($invoice);

        $invoice->setAmountPaid($paid);
        $invoice->setPaymentStatus(
            InvoicePaymentStatus::fromAmounts($paid, $invoice->getAmount(), $invoice->getPaymentStatus())
        );

        $this->applyLifecycleStatus($invoice, $paid);

        return $paid;
    }

    /**
     * Moves the invoice along Draft -> Posted -> Partially Paid -> Paid, or to
     * Overdue when the due date has passed with a balance outstanding.
     *
     * Draft and Cancelled are never changed here: a draft has not been issued,
     * and a cancelled invoice should stay cancelled whatever the ledger says.
     * Paid and Partially Paid take precedence over Overdue.
     *
     * @param Invoice $invoice
     * @param float $paid
     */
    private function applyLifecycleStatus(Invoice $invoice, float $paid): void
    {
        $current = $invoice->getStatus();

        if (in_array($current, [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED], true)) {
            return;
        }

        $total = Money::round($invoice->getAmount());
        $paid = Money::round($paid);

        if ($total > 0.0 && Money::compare($paid, $total) >= 0) {
            $invoice->setStatus(Invoice::STATUS_PAID);

            return;
        }

        // A part-paid invoice keeps Partially Paid after its due date, matching the overdue process.
        if ($paid > 0.0) {
            $invoice->setStatus(Invoice::STATUS_PARTIALLY_PAID);

            return;
        }

        if ($this->isPastDue($invoice)) {
            $invoice->setStatus(Invoice::STATUS_OVERDUE);

            return;
        }

        // Nothing paid and not yet due: an invoice that was Paid or Partially
        // Paid before - a refund, or a payment removed - returns to Posted.
        if (in_array($current, [Invoice::STATUS_PAID, Invoice::STATUS_PARTIALLY_PAID], true)) {
            $invoice->setStatus(Invoice::STATUS_POSTED);
        }
    }

    /**
     * Returns whether the due date has passed.
     *
     * @param Invoice $invoice
     * @return bool
     */
    private function isPastDue(Invoice $invoice): bool
    {
        $dueDate = $invoice->getDueDate();

        if (null === $dueDate) {
            return false;
        }

        return $dueDate < new \DateTime('today');
    }

    /**
     * Returns the entity manager.
     *
     * @return EntityManagerInterface
     */
    private function getEntityManager(): EntityManagerInterface
    {
        $em = $this->registry->getManagerForClass(Invoice::class);

        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('No Doctrine ORM entity manager is configured for ' . Invoice::class . '.');
        }

        return $em;
    }
}
