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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Entity;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoiceLineItem;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the InvoiceLineItem entity's price and currency handling.
 */
class InvoiceLineItemTest extends TestCase
{
    /**
     * Tests that a Price is built once both value and currency are known.
     */
    public function testPriceIsCreatedFromValueAndCurrency(): void
    {
        $lineItem = new InvoiceLineItem();
        $lineItem->setValue(12.50);

        self::assertNull($lineItem->getPrice(), 'No currency yet, so no price');

        $lineItem->setCurrency('NZD');

        self::assertInstanceOf(Price::class, $lineItem->getPrice());
        self::assertEquals(12.50, $lineItem->getPrice()->getValue());
        self::assertSame('NZD', $lineItem->getPrice()->getCurrency());
    }

    /**
     * Tests that setting a Price writes value and currency back.
     */
    public function testSetPriceUpdatesValueAndCurrency(): void
    {
        $lineItem = new InvoiceLineItem();
        $lineItem->setPrice(Price::create('7.25', 'EUR'));

        self::assertSame(7.25, $lineItem->getValue());
        self::assertSame('EUR', $lineItem->getCurrency());
    }

    /**
     * Tests that clearing the price keeps the existing value and currency.
     */
    public function testClearingThePriceKeepsTheValue(): void
    {
        $lineItem = new InvoiceLineItem();
        $lineItem->setValue(10.00);
        $lineItem->setCurrency('USD');

        $lineItem->setPrice(null);

        self::assertSame(10.00, $lineItem->getValue());
        self::assertSame('USD', $lineItem->getCurrency());
    }

    /**
     * Tests that a line item without a currency inherits the invoice's on save.
     */
    public function testPreSaveInheritsTheInvoiceCurrency(): void
    {
        $invoice = new Invoice();
        $invoice->setCurrency('AUD');

        $lineItem = new InvoiceLineItem();
        $lineItem->setInvoice($invoice);

        $lineItem->preSave();

        self::assertSame('AUD', $lineItem->getCurrency());
    }

    /**
     * Tests that a line item with no currency anywhere falls back to USD on save.
     */
    public function testPreSaveFallsBackToUsd(): void
    {
        $lineItem = new InvoiceLineItem();

        $lineItem->preSave();

        self::assertSame('USD', $lineItem->getCurrency());
        self::assertSame(0.0, $lineItem->getValue());
        self::assertInstanceOf(Price::class, $lineItem->getPrice());
    }

    /**
     * Tests that an explicit currency is never overwritten by the invoice's.
     */
    public function testPreSaveKeepsAnExplicitCurrency(): void
    {
        $invoice = new Invoice();
        $invoice->setCurrency('AUD');

        $lineItem = new InvoiceLineItem();
        $lineItem->setInvoice($invoice);
        $lineItem->setValue(5.00);
        $lineItem->setCurrency('GBP');

        $lineItem->preSave();

        self::assertSame('GBP', $lineItem->getCurrency());
        self::assertSame(5.00, $lineItem->getValue());
    }

    /**
     * Tests that null tax and discount amounts are stored as zero.
     */
    public function testNullTaxAndDiscountBecomeZero(): void
    {
        $lineItem = new InvoiceLineItem();
        $lineItem->setTaxAmount(3.00)->setDiscountAmount(1.00);

        $lineItem->setTaxAmount(null)->setDiscountAmount(null);

        self::assertSame(0.0, $lineItem->getTaxAmount());
        self::assertSame(0.0, $lineItem->getDiscountAmount());
    }
}
