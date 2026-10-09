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

use Oro\Bundle\CurrencyBundle\Entity\Price;
use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;

class InvoicePaymentLineItemTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $lineItem = new InvoicePaymentLineItem();

        $payment = new InvoicePayment();
        $invoice = new Invoice();

        $lineItem->setInvoicePayment($payment);
        $lineItem->setInvoice($invoice);
        $lineItem->setAmount(25.5);
        $lineItem->setCurrency('USD');

        self::assertSame($payment, $lineItem->getInvoicePayment());
        self::assertSame($invoice, $lineItem->getInvoice());
        self::assertSame(25.5, $lineItem->getAmount());
        self::assertSame('USD', $lineItem->getCurrency());
    }

    public function testCreatePrice(): void
    {
        $lineItem = new InvoicePaymentLineItem();
        $lineItem->setAmount(40.0);
        $lineItem->setCurrency('EUR');

        $lineItem->createPrice();

        self::assertInstanceOf(Price::class, $lineItem->getPrice());
        self::assertSame('40', $lineItem->getPrice()->getValue());
        self::assertSame('EUR', $lineItem->getPrice()->getCurrency());
    }

    public function testCreatePriceDoesNothingWithoutACurrency(): void
    {
        $lineItem = new InvoicePaymentLineItem();
        $lineItem->createPrice();

        self::assertNull($lineItem->getPrice());
    }

    public function testSetPriceUpdatesAmountAndCurrency(): void
    {
        $lineItem = new InvoicePaymentLineItem();
        $lineItem->setPrice(Price::create('99.99', 'GBP'));

        self::assertSame(99.99, $lineItem->getAmount());
        self::assertSame('GBP', $lineItem->getCurrency());
    }

    public function testUpdatePriceWithNullPriceResetsValues(): void
    {
        $lineItem = new InvoicePaymentLineItem();
        $lineItem->setAmount(50.0);
        $lineItem->setCurrency('USD');

        $lineItem->setPrice(null);

        self::assertSame(0.0, $lineItem->getAmount());
        self::assertNull($lineItem->getCurrency());
    }

    public function testPreSaveRefreshesFromPrice(): void
    {
        $lineItem = new InvoicePaymentLineItem();
        $lineItem->setPrice(Price::create('123.45', 'CAD'));

        // Corrupt the amount directly then let preSave fix it
        $reflection = new \ReflectionProperty($lineItem, 'amount');
        $reflection->setValue($lineItem, 0.0);

        $lineItem->preSave();

        self::assertSame(123.45, $lineItem->getAmount());
        self::assertSame('CAD', $lineItem->getCurrency());
    }
}
