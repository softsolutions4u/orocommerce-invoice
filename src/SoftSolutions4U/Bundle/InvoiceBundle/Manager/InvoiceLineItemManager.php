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
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoiceLineItem;
use Doctrine\ORM\EntityManagerInterface;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderDiscount;
use Oro\Bundle\OrderBundle\Entity\OrderLineItem;
use Oro\Bundle\PromotionBundle\Entity\AppliedPromotion;
use Oro\Bundle\TaxBundle\Entity\TaxValue;
use Oro\Bundle\TaxBundle\Model\Result;
use Oro\Bundle\TaxBundle\Model\ResultElement;
use Oro\Bundle\TaxBundle\Provider\TaxProviderRegistry;
use Oro\Component\Math\BigDecimal;

/**
 * Invoice line item manager.
 */
class InvoiceLineItemManager
{
    /**
     * Creates a new InvoiceLineItemManager instance.
     *
     * @param TaxProviderRegistry $taxProviderRegistry
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        private TaxProviderRegistry $taxProviderRegistry,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Copies from order.
     *
     * @param Invoice $invoice
     * @param Order $order
     */
    public function copyFromOrder(Invoice $invoice, Order $order): void
    {
        $lineItemDiscounts = $this->getLineItemDiscounts($order);

        $lineItemTaxAmounts = $this->getStoredLineItemTaxAmounts(
            $order->getLineItems()->toArray()
        );

        foreach ($order->getLineItems() as $orderLineItem) {
            $lineItemId = $orderLineItem->getId();

            $taxAmount = 0.0;

            if ($lineItemId !== null && isset($lineItemTaxAmounts[$lineItemId])) {
                $taxAmount = $lineItemTaxAmounts[$lineItemId];
            }

            $discountAmount = $lineItemDiscounts[$lineItemId] ?? 0.0;

            $invoiceLineItem = $this->createFromOrderLineItem($orderLineItem, $taxAmount, $discountAmount);
            $invoice->addLineItem($invoiceLineItem);
        }

        $taxProvider = $this->taxProviderRegistry->getEnabledProvider();
        $orderTaxResult = $taxProvider->loadTax($order);

        $this->recalculateInvoiceTotals(
            $invoice,
            $orderTaxResult->getShipping(),
            $this->getOrderLevelDiscount($order)
        );
    }

    /**
     * Returns the stored line item tax amounts.
     *
     * @param OrderLineItem[] $lineItems
     * @return array<int, float>
     *
     */
    private function getStoredLineItemTaxAmounts(array $lineItems): array
    {
        $lineItemIds = [];

        foreach ($lineItems as $lineItem) {
            $lineItemId = $lineItem->getId();

            if ($lineItemId !== null) {
                $lineItemIds[] = $lineItemId;
            }
        }

        if (!$lineItemIds) {
            return [];
        }

        $taxValues = $this->entityManager
            ->getRepository(TaxValue::class)
            ->createQueryBuilder('taxValue')
            ->where('taxValue.entityClass = :entityClass')
            ->andWhere('taxValue.entityId IN (:entityIds)')
            ->setParameter('entityClass', OrderLineItem::class)
            ->setParameter('entityIds', $lineItemIds)
            ->getQuery()
            ->getResult();

        $taxAmounts = [];

        /** @var TaxValue $taxValue */
        foreach ($taxValues as $taxValue) {
            $entityId = $taxValue->getEntityId();

            if ($entityId === null) {
                continue;
            }

            $result = $taxValue->getResult();

            if (!$result instanceof Result) {
                continue;
            }

            $rowResult = $result->getRow();

            if (!$rowResult instanceof ResultElement) {
                $taxAmounts[(int) $entityId] = 0.0;
                continue;
            }

            $taxAmounts[(int) $entityId] = round(
                (float) $rowResult->getTaxAmount(),
                2
            );
        }

        return $taxAmounts;
    }

    /**
     * Returns the applied discounts.
     *
     * @param Order $order
     * @return array<int, mixed>
     */
    private function getAppliedDiscounts(Order $order): array
    {
        /** @var AppliedPromotion[] $appliedPromotions */
        $appliedPromotions = $this->entityManager->getRepository(AppliedPromotion::class)
            ->findBy([
                'order' => $order,
                'active' => true,
                'removed' => false,
            ]);

        $discounts = [];
        foreach ($appliedPromotions as $appliedPromotion) {
            foreach ($appliedPromotion->getAppliedDiscounts() as $discount) {
                $discounts[] = $discount;
            }
        }

        return $discounts;
    }

    /**
     * Returns the line item discounts.
     *
     * @param Order $order
     * @return array<int, float>
     */
    private function getLineItemDiscounts(Order $order): array
    {
        $map = [];
        foreach ($this->getAppliedDiscounts($order) as $discount) {
            $lineItem = $discount->getLineItem();
            if ($lineItem !== null) {
                $id = $lineItem->getId();
                if ($id !== null) {
                    $map[$id] = ($map[$id] ?? 0.0)
                        + (float) $discount->getAmount();
                }
            }
        }

        return $map;
    }

    /**
     * Sum of discount rows NOT tied to a line item (order-level promotions).
     *
     * @param Order $order
     * @return float
     */
    public function getOrderLevelDiscount(Order $order): float
    {
        $appliedTotal = 0.0;
        foreach ($this->getAppliedDiscounts($order) as $discount) {
            if ($discount->getLineItem() === null) {
                $appliedTotal += (float) $discount->getAmount();
            }
        }

        $orderDiscountTableTotal = $this->getOrderDiscountTableAmount($order);

        return $appliedTotal + $orderDiscountTableTotal;
    }

    /**
     * Sums Oro's separate "Special Discounts" for an order.
     *
     * Goes through the ORM rather than naming oro_order_discount in raw SQL:
     * the physical table name is Oro's to change, and a raw query also bypasses
     * the identity map, so discounts added in the current unit of work but not
     * yet flushed would be missed.
     *
     * @param Order $order
     * @return float
     */
    private function getOrderDiscountTableAmount(Order $order): float
    {
        $total = $this->entityManager
            ->createQueryBuilder()
            ->select('COALESCE(SUM(discount.amount), 0)')
            ->from(OrderDiscount::class, 'discount')
            ->where('discount.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) $total;
    }

    /**
     * Removes the line item.
     *
     * @param Invoice $invoice
     * @param InvoiceLineItem $lineItem
     */
    public function removeLineItem(Invoice $invoice, InvoiceLineItem $lineItem): void
    {
        $invoice->removeLineItem($lineItem);

        $order = $invoice->getOrder();

        if ($order) {
            $this->recalculateInvoiceTotalsFromOrder($invoice, $order);
        } else {
            $this->recalculateInvoiceTotals($invoice);
        }
    }

    /**
     * Removes the line items by ids.
     *
     * @param array $ids
     * @param Invoice $invoice
     */
    public function removeLineItemsByIds(Invoice $invoice, array $ids): void
    {
        foreach ($invoice->getLineItems() as $lineItem) {
            if (in_array((string) $lineItem->getId(), $ids, true)) {
                $invoice->removeLineItem($lineItem);
            }
        }

        $order = $invoice->getOrder();

        if ($order) {
            $this->recalculateInvoiceTotalsFromOrder($invoice, $order);
        } else {
            $this->recalculateInvoiceTotals($invoice);
        }
    }

    /**
     * Recalculates the invoice totals from order.
     *
     * @param Invoice $invoice
     * @param Order $order
     */
    public function recalculateInvoiceTotalsFromOrder(
        Invoice $invoice,
        Order $order
    ): void {
        $taxResult = $this->taxProviderRegistry
            ->getEnabledProvider()
            ->loadTax($order);

        $orderLevelDiscount = $this->getOrderLevelDiscount($order);

        $invoice->setShippingAmount(
            (float) $taxResult->getShipping()->getExcludingTax()
        );

        $this->recalculateInvoiceTotals(
            $invoice,
            $taxResult->getShipping(),
            $orderLevelDiscount
        );
    }

    /**
     * Creates the from order line item.
     *
     * @param OrderLineItem $orderLineItem
     * @param float $taxAmount
     * @param float $discountAmount
     * @return InvoiceLineItem
     */
    private function createFromOrderLineItem(
        OrderLineItem $orderLineItem,
        float $taxAmount,
        float $discountAmount
    ): InvoiceLineItem {
        $lineItem = new InvoiceLineItem();

        $quantity = (float) $orderLineItem->getQuantity();
        $unitPrice = (float) $orderLineItem->getValue();

        $lineItem
            ->setProduct($orderLineItem->getProduct())
            ->setProductSku($orderLineItem->getProductSku())
            ->setProductName($orderLineItem->getProductName())
            ->setQuantity($quantity)
            ->setProductUnit($orderLineItem->getProductUnit())
            ->setProductUnitCode($orderLineItem->getProductUnitCode())
            ->setCurrency($orderLineItem->getCurrency())
            ->setValue($unitPrice);

        $subtotal = BigDecimal::of((string) $unitPrice)
            ->multipliedBy((string) $quantity)
            ->toFloat();

        $total = BigDecimal::of((string) $subtotal)
            ->minus((string) $discountAmount)
            ->plus((string) $taxAmount)
            ->toFloat();

        $lineItem
            ->setSubtotal($subtotal)
            ->setDiscountAmount($discountAmount)
            ->setTaxAmount($taxAmount)
            ->setTotal($total);

        return $lineItem;
    }

    /**
     * Recalculates the invoice totals.
     *
     * @param Invoice $invoice
     * @param ResultElement|null $shippingTax
     * @param float $orderLevelDiscount
     */
    public function recalculateInvoiceTotals(
        Invoice $invoice,
        ?ResultElement $shippingTax = null,
        float $orderLevelDiscount = 0.0
    ): void {
        $subtotal = BigDecimal::of(0);
        $taxAmount = BigDecimal::of(0);
        $discountAmount = BigDecimal::of(0);

        foreach ($invoice->getLineItems() as $lineItem) {
            $subtotal = $subtotal->plus((string) $lineItem->getSubtotal());
            $taxAmount = $taxAmount->plus((string) $lineItem->getTaxAmount());
            $discountAmount = $discountAmount->plus((string) $lineItem->getDiscountAmount());
        }

        if ($shippingTax) {
            $taxAmount = $taxAmount->plus((string) $shippingTax->getTaxAmount());
        }

        $discountAmount = $discountAmount->plus((string) $orderLevelDiscount);

        $shippingAmount = BigDecimal::of((string) $invoice->getShippingAmount());

        $grandTotal = $subtotal->plus($taxAmount)->minus($discountAmount)->plus($shippingAmount);

        $invoice
            ->setSubtotal($subtotal->toFloat())
            ->setTaxAmount($taxAmount->toFloat())
            ->setDiscountAmount($discountAmount->toFloat())
            ->setGrandTotal($grandTotal->toFloat())
            ->setAmount($grandTotal->toFloat());
    }
}
