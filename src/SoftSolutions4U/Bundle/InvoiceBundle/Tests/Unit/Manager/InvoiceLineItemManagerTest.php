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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Manager;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoiceLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceLineItemManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderLineItem;
use Oro\Bundle\PromotionBundle\Entity\AppliedDiscount;
use Oro\Bundle\PromotionBundle\Entity\AppliedPromotion;
use Oro\Bundle\TaxBundle\Entity\TaxValue;
use Oro\Bundle\TaxBundle\Model\Result;
use Oro\Bundle\TaxBundle\Model\ResultElement;
use Oro\Bundle\TaxBundle\Provider\TaxProviderInterface;
use Oro\Bundle\TaxBundle\Provider\TaxProviderRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for invoice line item creation and total recalculation.
 *
 * Tax provider and the ORM layer are mocked; the arithmetic is exercised
 * against real Invoice / InvoiceLineItem value objects so the assertions
 * check what the invoice ends up looking like.
 */
class InvoiceLineItemManagerTest extends TestCase
{
    private TaxProviderRegistry&MockObject $taxRegistry;
    private TaxProviderInterface&MockObject $taxProvider;
    private EntityManagerInterface&MockObject $entityManager;
    private EntityRepository&MockObject $taxValueRepository;
    private EntityRepository&MockObject $appliedPromotionRepository;

    /** @var InvoiceLineItemManager $manager */
    private InvoiceLineItemManager $manager;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->taxProvider = $this->createMock(TaxProviderInterface::class);
        $this->taxRegistry = $this->createMock(TaxProviderRegistry::class);
        $this->taxRegistry->method('getEnabledProvider')->willReturn($this->taxProvider);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->taxValueRepository = $this->createMock(EntityRepository::class);
        $this->appliedPromotionRepository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function (string $class) {
                return match ($class) {
                    TaxValue::class => $this->taxValueRepository,
                    AppliedPromotion::class => $this->appliedPromotionRepository,
                    default => $this->createMock(EntityRepository::class),
                };
            });

        $this->manager = new InvoiceLineItemManager(
            $this->taxRegistry,
            $this->entityManager
        );
    }

    // -------------------------------------------------- recalculateInvoiceTotals()

    /**
     * Tests recalculates totals from line items.
     */
    public function testRecalculatesTotalsFromLineItems(): void
    {
        $invoice = new Invoice();
        $invoice->setShippingAmount(0.0);

        $invoice->addLineItem($this->lineItem(subtotal: 100.0, tax: 10.0, discount: 5.0));
        $invoice->addLineItem($this->lineItem(subtotal: 50.0, tax: 5.0, discount: 2.5));

        $this->manager->recalculateInvoiceTotals($invoice);

        self::assertSame(150.0, $invoice->getSubtotal());
        self::assertSame(15.0, $invoice->getTaxAmount());
        self::assertSame(7.5, $invoice->getDiscountAmount());
        self::assertSame(157.5, $invoice->getGrandTotal()); // 150 + 15 - 7.5 + 0
        self::assertSame(157.5, $invoice->getAmount());
    }

    /**
     * Tests adds shipping tax to tax total.
     */
    public function testAddsShippingTaxToTaxTotal(): void
    {
        $invoice = new Invoice();
        $invoice->setShippingAmount(0.0);
        $invoice->addLineItem($this->lineItem(subtotal: 100.0, tax: 10.0, discount: 0.0));

        $shippingTax = ResultElement::create('18.00', '16.36', '1.64');

        $this->manager->recalculateInvoiceTotals($invoice, $shippingTax);

        self::assertSame(11.64, $invoice->getTaxAmount()); // 10 + 1.64
    }

    /**
     * Tests adds order level discount.
     */
    public function testAddsOrderLevelDiscount(): void
    {
        $invoice = new Invoice();
        $invoice->setShippingAmount(0.0);
        $invoice->addLineItem($this->lineItem(subtotal: 100.0, tax: 0.0, discount: 0.0));

        $this->manager->recalculateInvoiceTotals($invoice, null, 5.0);

        self::assertSame(5.0, $invoice->getDiscountAmount());
        self::assertSame(95.0, $invoice->getGrandTotal()); // 100 - 5
    }

    /**
     * Tests adds shipping amount without changing subtotal.
     */
    public function testAddsShippingAmountWithoutChangingSubtotal(): void
    {
        $invoice = new Invoice();
        $invoice->setShippingAmount(18.0);
        $invoice->addLineItem($this->lineItem(subtotal: 100.0, tax: 0.0, discount: 0.0));

        $this->manager->recalculateInvoiceTotals($invoice);

        self::assertSame(100.0, $invoice->getSubtotal(), 'Shipping does not count as a subtotal');
        self::assertSame(118.0, $invoice->getGrandTotal()); // 100 + 18
    }

    /**
     * Tests recalculates totals for empty invoice.
     */
    public function testRecalculatesTotalsForEmptyInvoice(): void
    {
        $invoice = new Invoice();
        $invoice->setShippingAmount(0.0);

        $this->manager->recalculateInvoiceTotals($invoice);

        self::assertSame(0.0, $invoice->getSubtotal());
        self::assertSame(0.0, $invoice->getTaxAmount());
        self::assertSame(0.0, $invoice->getDiscountAmount());
        self::assertSame(0.0, $invoice->getGrandTotal());
        self::assertSame(0.0, $invoice->getAmount());
    }

    /**
     * Tests recalculation is idempotent.
     */
    public function testRecalculationIsIdempotent(): void
    {
        $invoice = new Invoice();
        $invoice->setShippingAmount(18.0);
        $invoice->addLineItem($this->lineItem(subtotal: 100.0, tax: 10.0, discount: 5.0));

        $this->manager->recalculateInvoiceTotals($invoice);
        $firstGrandTotal = $invoice->getGrandTotal();

        $this->manager->recalculateInvoiceTotals($invoice);
        $secondGrandTotal = $invoice->getGrandTotal();

        self::assertSame($firstGrandTotal, $secondGrandTotal);
    }

    /**
     * Tests handles fractional cents without drift.
     */
    public function testHandlesFractionalCentsWithoutDrift(): void
    {
        $invoice = new Invoice();
        $invoice->setShippingAmount(0.0);
        $invoice->addLineItem($this->lineItem(subtotal: 0.1, tax: 0.0, discount: 0.0));
        $invoice->addLineItem($this->lineItem(subtotal: 0.2, tax: 0.0, discount: 0.0));

        $this->manager->recalculateInvoiceTotals($invoice);

        // 0.1 + 0.2 = 0.3 (not 0.30000000000000004)
        self::assertSame(0.3, $invoice->getSubtotal());
    }

    // --------------------------------------- getOrderLevelDiscount()

    /**
     * Tests order level discount is zero when no promotions.
     */
    public function testOrderLevelDiscountIsZeroWhenNoPromotions(): void
    {
        $this->appliedPromotionRepository->method('findBy')->willReturn([]);
        $this->mockDiscountQueryBuilder(0.0);

        $order = new Order();

        self::assertSame(0.0, $this->manager->getOrderLevelDiscount($order));
    }

    /**
     * Tests order level discount sums discounts not tied to line items.
     */
    public function testOrderLevelDiscountSumsDiscountsNotTiedToLineItems(): void
    {
        $orderLevelDiscount1 = $this->appliedDiscount(5.0, null);
        $orderLevelDiscount2 = $this->appliedDiscount(2.5, null);
        $lineItemDiscount = $this->appliedDiscount(3.0, $this->orderLineItem(1, 1.0, 0.0));

        $this->appliedPromotionRepository
            ->method('findBy')
            ->willReturn([
                $this->appliedPromotion([$orderLevelDiscount1, $lineItemDiscount]),
                $this->appliedPromotion([$orderLevelDiscount2]),
            ]);
        $this->mockDiscountQueryBuilder(0.0);

        $discount = $this->manager->getOrderLevelDiscount(new Order());

        // Only the two non-line-item discounts count: 5 + 2.5 = 7.5
        self::assertSame(7.5, $discount);
    }

    /**
     * Tests order level discount adds special discount table amount.
     */
    public function testOrderLevelDiscountAddsSpecialDiscountTableAmount(): void
    {
        $this->appliedPromotionRepository->method('findBy')->willReturn([]);
        $this->mockDiscountQueryBuilder(12.75);

        $discount = $this->manager->getOrderLevelDiscount(new Order());

        self::assertSame(12.75, $discount);
    }

    /**
     * Tests order level discount combines promotions and table.
     */
    public function testOrderLevelDiscountCombinesPromotionsAndTable(): void
    {
        $orderLevel = $this->appliedDiscount(5.0, null);
        $this->appliedPromotionRepository
            ->method('findBy')
            ->willReturn([$this->appliedPromotion([$orderLevel])]);
        $this->mockDiscountQueryBuilder(7.5);

        $discount = $this->manager->getOrderLevelDiscount(new Order());

        self::assertSame(12.5, $discount); // 5 + 7.5
    }

    /**
     * Tests order level discount ignores removed promotions.
     */
    public function testOrderLevelDiscountIgnoresRemovedPromotions(): void
    {
        $order = new Order();

        $this->appliedPromotionRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(self::callback(function (array $criteria) use ($order): bool {
                return $criteria['order'] === $order
                    && $criteria['active'] === true
                    && $criteria['removed'] === false;
            }))
            ->willReturn([]);
        $this->mockDiscountQueryBuilder(0.0);

        $this->manager->getOrderLevelDiscount($order);
    }

    // --------------------------------------- copyFromOrder()

    /**
     * Tests copies line items from order.
     */
    public function testCopiesLineItemsFromOrder(): void
    {
        $order = $this->orderWithLineItems([
            $this->orderLineItem(id: 1, quantity: 2.0, value: 10.0),
            $this->orderLineItem(id: 2, quantity: 1.0, value: 50.0),
        ]);

        $this->taxValueRepository->method('createQueryBuilder')->willReturn(
            $this->queryBuilderReturning([])
        );
        $this->appliedPromotionRepository->method('findBy')->willReturn([]);
        $this->mockDiscountQueryBuilder(0.0);
        $this->taxProvider->method('loadTax')->willReturn($this->emptyTaxResult());

        $invoice = new Invoice();
        $this->manager->copyFromOrder($invoice, $order);

        self::assertCount(2, $invoice->getLineItems());
        self::assertSame(70.0, $invoice->getSubtotal()); // 2*10 + 1*50
    }

    /**
     * Tests copy from order applies stored line item tax amounts.
     */
    public function testCopyFromOrderAppliesStoredLineItemTaxAmounts(): void
    {
        $order = $this->orderWithLineItems([
            $this->orderLineItem(id: 42, quantity: 1.0, value: 100.0),
        ]);

        $this->taxValueRepository->method('createQueryBuilder')->willReturn(
            $this->queryBuilderReturning([
                $this->taxValue(entityId: 42, taxAmount: 8.5),
            ])
        );
        $this->appliedPromotionRepository->method('findBy')->willReturn([]);
        $this->mockDiscountQueryBuilder(0.0);
        $this->taxProvider->method('loadTax')->willReturn($this->emptyTaxResult());

        $invoice = new Invoice();
        $this->manager->copyFromOrder($invoice, $order);

        $lineItem = $invoice->getLineItems()->first();
        self::assertSame(8.5, $lineItem->getTaxAmount());
    }

    /**
     * Tests copy from order applies line item discounts.
     */
    public function testCopyFromOrderAppliesLineItemDiscounts(): void
    {
        $orderLineItem = $this->orderLineItem(id: 7, quantity: 1.0, value: 100.0);
        $order = $this->orderWithLineItems([$orderLineItem]);

        $this->taxValueRepository->method('createQueryBuilder')->willReturn(
            $this->queryBuilderReturning([])
        );
        $lineItemDiscount = $this->appliedDiscount(15.0, $orderLineItem);
        $this->appliedPromotionRepository
            ->method('findBy')
            ->willReturn([$this->appliedPromotion([$lineItemDiscount])]);
        $this->mockDiscountQueryBuilder(0.0);
        $this->taxProvider->method('loadTax')->willReturn($this->emptyTaxResult());

        $invoice = new Invoice();
        $this->manager->copyFromOrder($invoice, $order);

        $lineItem = $invoice->getLineItems()->first();
        self::assertSame(15.0, $lineItem->getDiscountAmount());
    }

    /**
     * Tests copy from order applies order level discount to invoice.
     */
    public function testCopyFromOrderAppliesOrderLevelDiscountToInvoice(): void
    {
        $order = $this->orderWithLineItems([
            $this->orderLineItem(id: 1, quantity: 1.0, value: 100.0),
        ]);

        $this->taxValueRepository->method('createQueryBuilder')->willReturn(
            $this->queryBuilderReturning([])
        );
        $this->appliedPromotionRepository->method('findBy')->willReturn([]);
        $this->mockDiscountQueryBuilder(20.0);
        $this->taxProvider->method('loadTax')->willReturn($this->emptyTaxResult());

        $invoice = new Invoice();
        $this->manager->copyFromOrder($invoice, $order);

        self::assertSame(20.0, $invoice->getDiscountAmount());
        self::assertSame(80.0, $invoice->getGrandTotal()); // 100 - 20
    }

    /**
     * Tests copy from order applies shipping tax.
     */
    public function testCopyFromOrderAppliesShippingTax(): void
    {
        $order = $this->orderWithLineItems([
            $this->orderLineItem(id: 1, quantity: 1.0, value: 100.0),
        ]);

        $this->taxValueRepository->method('createQueryBuilder')->willReturn(
            $this->queryBuilderReturning([])
        );
        $this->appliedPromotionRepository->method('findBy')->willReturn([]);
        $this->mockDiscountQueryBuilder(0.0);

        $taxResult = new Result();
        $shipping = ResultElement::create('18.00', '16.36', '1.64');
        $taxResult->offsetSet(Result::SHIPPING, $shipping);
        $this->taxProvider->method('loadTax')->willReturn($taxResult);

        $invoice = new Invoice();
        $this->manager->copyFromOrder($invoice, $order);

        self::assertSame(1.64, $invoice->getTaxAmount());
    }

    // --------------------------------------- createFromOrderLineItem()

    /**
     * Tests copies product details from order line item.
     */
    public function testCopiesProductDetailsFromOrderLineItem(): void
    {
        $orderLineItem = $this->orderLineItem(id: 1, quantity: 2.0, value: 25.0);
        $orderLineItem->setProductSku('SKU-123');
        $orderLineItem->setProductName('Widget');
        $orderLineItem->setCurrency('USD');

        $order = $this->orderWithLineItems([$orderLineItem]);

        $this->taxValueRepository->method('createQueryBuilder')->willReturn(
            $this->queryBuilderReturning([])
        );
        $this->appliedPromotionRepository->method('findBy')->willReturn([]);
        $this->mockDiscountQueryBuilder(0.0);
        $this->taxProvider->method('loadTax')->willReturn($this->emptyTaxResult());

        $invoice = new Invoice();
        $this->manager->copyFromOrder($invoice, $order);

        $lineItem = $invoice->getLineItems()->first();
        self::assertSame('SKU-123', $lineItem->getProductSku());
        self::assertSame('Widget', $lineItem->getProductName());
        self::assertSame(2.0, $lineItem->getQuantity());
        self::assertSame(25.0, $lineItem->getValue());
        self::assertSame('USD', $lineItem->getCurrency());
    }

    /**
     * Tests total formula is subtotal minus discount plus tax.
     */
    public function testTotalFormulaIsSubtotalMinusDiscountPlusTax(): void
    {
        $orderLineItem = $this->orderLineItem(id: 5, quantity: 3.0, value: 20.0);
        $order = $this->orderWithLineItems([$orderLineItem]);

        $this->taxValueRepository->method('createQueryBuilder')->willReturn(
            $this->queryBuilderReturning([
                $this->taxValue(entityId: 5, taxAmount: 3.0),
            ])
        );
        $this->appliedPromotionRepository
            ->method('findBy')
            ->willReturn([$this->appliedPromotion([
                $this->appliedDiscount(2.0, $orderLineItem),
            ])]);
        $this->mockDiscountQueryBuilder(0.0);
        $this->taxProvider->method('loadTax')->willReturn($this->emptyTaxResult());

        $invoice = new Invoice();
        $this->manager->copyFromOrder($invoice, $order);

        $lineItem = $invoice->getLineItems()->first();
        // 3 * 20 = 60 subtotal; 60 - 2 discount + 3 tax = 61 total
        self::assertSame(60.0, $lineItem->getSubtotal());
        self::assertSame(2.0, $lineItem->getDiscountAmount());
        self::assertSame(3.0, $lineItem->getTaxAmount());
        self::assertSame(61.0, $lineItem->getTotal());
    }

    // --------------------------------------- removeLineItem()

    /**
     * Tests remove line item recalculates from order when present.
     */
    public function testRemoveLineItemRecalculatesFromOrderWhenPresent(): void
    {
        $invoice = new Invoice();
        $invoice->setOrder(new Order());
        $lineItem = $this->lineItem(subtotal: 100.0, tax: 0.0, discount: 0.0);
        $invoice->addLineItem($lineItem);

        $this->appliedPromotionRepository->method('findBy')->willReturn([]);
        $this->mockDiscountQueryBuilder(0.0);
        $this->taxProvider->method('loadTax')->willReturn($this->emptyTaxResult());

        $this->manager->removeLineItem($invoice, $lineItem);

        self::assertCount(0, $invoice->getLineItems());
        self::assertSame(0.0, $invoice->getSubtotal());
    }

    /**
     * Tests remove line item recalculates from line items without order.
     */
    public function testRemoveLineItemRecalculatesFromLineItemsWithoutOrder(): void
    {
        $invoice = new Invoice();
        $keep = $this->lineItem(subtotal: 50.0, tax: 0.0, discount: 0.0);
        $remove = $this->lineItem(subtotal: 30.0, tax: 0.0, discount: 0.0);
        $invoice->addLineItem($keep);
        $invoice->addLineItem($remove);

        $this->manager->removeLineItem($invoice, $remove);

        self::assertCount(1, $invoice->getLineItems());
        self::assertSame(50.0, $invoice->getSubtotal());
    }

    // --------------------------------------- removeLineItemsByIds()

    /**
     * Tests remove line items by ids removes matching ids.
     */
    public function testRemoveLineItemsByIdsRemovesMatchingIds(): void
    {
        $invoice = new Invoice();
        $item1 = $this->lineItem(subtotal: 10.0, tax: 0.0, discount: 0.0);
        $item2 = $this->lineItem(subtotal: 20.0, tax: 0.0, discount: 0.0);
        $item3 = $this->lineItem(subtotal: 30.0, tax: 0.0, discount: 0.0);
        self::setId($item1, 1);
        self::setId($item2, 2);
        self::setId($item3, 3);
        $invoice->addLineItem($item1);
        $invoice->addLineItem($item2);
        $invoice->addLineItem($item3);

        $this->manager->removeLineItemsByIds($invoice, ['1', '3']);

        self::assertCount(1, $invoice->getLineItems());
        self::assertSame(20.0, $invoice->getSubtotal());
    }

    /**
     * Tests remove line items by ids with no matches does nothing.
     */
    public function testRemoveLineItemsByIdsWithNoMatchesDoesNothing(): void
    {
        $invoice = new Invoice();
        $item = $this->lineItem(subtotal: 10.0, tax: 0.0, discount: 0.0);
        self::setId($item, 1);
        $invoice->addLineItem($item);

        $this->manager->removeLineItemsByIds($invoice, ['999']);

        self::assertCount(1, $invoice->getLineItems());
    }

    // --------------------------------------- helpers

    /**
     * Returns a line item with the given amounts.
     *
     * @param float $subtotal
     * @param float $tax
     * @param float $discount
     * @return InvoiceLineItem
     */
    private function lineItem(
        float $subtotal,
        float $tax,
        float $discount
    ): InvoiceLineItem {
        $lineItem = new InvoiceLineItem();
        $lineItem->setSubtotal($subtotal);
        $lineItem->setTaxAmount($tax);
        $lineItem->setDiscountAmount($discount);
        $lineItem->setValue($subtotal);
        $lineItem->setQuantity(1.0);

        return $lineItem;
    }

    /**
     * Returns an order line item with the given id, quantity and value.
     *
     * @param int|null $id
     * @param float $quantity
     * @param float $value
     * @return OrderLineItem
     */
    private function orderLineItem(?int $id, float $quantity, float $value): OrderLineItem
    {
        $lineItem = new OrderLineItem();
        $lineItem->setQuantity($quantity);
        $lineItem->setValue($value);
        $lineItem->setCurrency('USD');

        if ($id !== null) {
            self::setId($lineItem, $id);
        }

        return $lineItem;
    }

    /**
     * Returns an order containing the given line items.
     *
     * @param array $lineItems
     * @return Order
     */
    private function orderWithLineItems(array $lineItems): Order
    {
        $order = new Order();
        foreach ($lineItems as $lineItem) {
            $order->addLineItem($lineItem);
        }

        return $order;
    }

    /**
     * Returns a tax value with the given entity id and row tax amount.
     *
     * @param int $entityId
     * @param float $taxAmount
     * @return TaxValue
     */
    private function taxValue(int $entityId, float $taxAmount): TaxValue
    {
        $result = new Result();
        $row = ResultElement::create((string) $taxAmount, '0', (string) $taxAmount);
        $result->offsetSet(Result::ROW, $row);

        $taxValue = new TaxValue();
        $taxValue->setEntityId($entityId);
        $taxValue->setResult($result);

        return $taxValue;
    }

    /**
     * Returns an applied promotion wrapping the given discounts.
     *
     * @param array $discounts
     * @return AppliedPromotion
     */
    private function appliedPromotion(array $discounts): AppliedPromotion
    {
        $promotion = new AppliedPromotion();
        foreach ($discounts as $discount) {
            $promotion->addAppliedDiscount($discount);
        }

        return $promotion;
    }

    /**
     * Returns an applied discount with the given amount and optional line item.
     *
     * @param float $amount
     * @param OrderLineItem|null $lineItem
     * @return AppliedDiscount
     */
    private function appliedDiscount(float $amount, ?OrderLineItem $lineItem): AppliedDiscount
    {
        $discount = new AppliedDiscount();
        $discount->setAmount($amount);
        $discount->setLineItem($lineItem);

        return $discount;
    }

    /**
     * Configures the entity manager to return a query builder that yields $amount.
     *
     * @param float $amount
     */
    private function mockDiscountQueryBuilder(float $amount): void
    {
        $this->entityManager
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilderReturning([$amount]));
    }

    /**
     * Returns the query builder returning.
     *
     * @param array<int, mixed> $result
     * @return QueryBuilder&MockObject
     */
    private function queryBuilderReturning(array $result): QueryBuilder&MockObject
    {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn($result);
        $query->method('getSingleScalarResult')->willReturn($result[0] ?? 0);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        return $qb;
    }

    /**
     * Returns an empty tax result with zero shipping.
     *
     * @return Result
     */
    private function emptyTaxResult(): Result
    {
        $result = new Result();
        $result->offsetSet(Result::SHIPPING, ResultElement::create('0', '0', '0'));

        return $result;
    }

    /**
     * Sets a Doctrine-generated id, which has no public setter.
     *
     * @param object $entity
     * @param int $id
     */
    private static function setId(object $entity, int $id): void
    {
        $class = new \ReflectionClass($entity);

        while ($class && !$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }

        if ($class) {
            $class->getProperty('id')->setValue($entity, $id);
        }
    }
}
