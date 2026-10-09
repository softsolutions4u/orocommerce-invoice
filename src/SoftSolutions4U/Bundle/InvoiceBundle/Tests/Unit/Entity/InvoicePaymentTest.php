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
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Component\Testing\Unit\EntityTestCaseTrait;
use Oro\Component\Testing\Unit\EntityTrait;

/**
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class InvoicePaymentTest extends \PHPUnit\Framework\TestCase
{
    use EntityTestCaseTrait;
    use EntityTrait;

    /**
     * Tests that every property round-trips through its accessors.
     */
    public function testProperties(): void
    {
        $now = new \DateTime('now');
        $properties = [
            ['id', 123],
            ['createdAt', $now, false],
            ['updatedAt', $now, false],
            ['customer', new Customer()],
            ['paymentMethod', 'check', 'paypal_17'],
            ['active', false, true],
            ['amount', 123.45, 10, 99999999.99],
            ['currency', 'NZD', 'USD', 'AUD'],
        ];

        $this->assertPropertyAccessors(new InvoicePayment(), $properties);
        $this->assertPropertyCollection(new InvoicePayment(), 'lineItems', new InvoicePaymentLineItem());

        $properties = [
            ['invoice', new Invoice()],
            ['invoicePayment', new InvoicePayment()],
            ['amount', 123.45, 10, 999999.99],
            ['currency', 'NZD', 'AUD', 'USD'],
        ];

        $this->assertPropertyAccessors(new InvoicePaymentLineItem(), $properties);
    }

    /**
     * Tests that invoice payment prices.
     */
    public function testInvoicePaymentPrices(): void
    {
        $this->assertPropertyGetterReturnsDefaultValue(new InvoicePayment(), 'price');

        /** @var InvoicePayment $invoicePayment */
        $invoicePayment = $this->getEntity(InvoicePayment::class, [
            'total' => 123.45,
            'currency' => 'NZD',
        ]);

        $this->assertInstanceOf(Price::class, $invoicePayment->getPrice());
        $this->assertEquals(123.45, $invoicePayment->getPrice()->getValue());
        $this->assertEquals('NZD', $invoicePayment->getPrice()->getCurrency());

        // Change the Amount
        $invoicePayment->setTotal(99.95);
        $this->assertEquals(99.95, $invoicePayment->getPrice()->getValue());
        $this->assertEquals('NZD', $invoicePayment->getPrice()->getCurrency());
    }

    /**
     * Tests that invoice payment line items.
     */
    public function testInvoicePaymentLineItems(): void
    {
        /** @var InvoicePayment $invoicePayment */
        $invoicePayment = $this->getEntity(InvoicePayment::class, [
            'id' => 45,
            'amount' => 25,
            'currency' => 'NZD',
        ]);

        /** @var Invoice $invoice1 */
        $invoice1 = $this->getEntity(Invoice::class, [
            'id' => 123,
            'invoiceNo' => 'INV-001',
            'amount' => 50,
            'currency' => 'NZD',
        ]);

        /** @var Invoice $invoice2 */
        $invoice2 = $this->getEntity(Invoice::class, [
            'id' => 111,
            'invoiceNo' => 'INV-002',
            'amount' => 500,
            'currency' => 'NZD',
        ]);

        /** @var InvoicePaymentLineItem $paymentLineItem1 */
        $paymentLineItem1 = $this->getEntity(InvoicePaymentLineItem::class);
        $paymentLineItem1
            ->setInvoice($invoice1)
            ->setInvoicePayment($invoicePayment)
            ->setAmount(10)
            ->setCurrency('NZD');

        /** @var InvoicePaymentLineItem $paymentLineItem2 */
        $paymentLineItem2 = $this->getEntity(InvoicePaymentLineItem::class);
        $paymentLineItem2
            ->setInvoice($invoice2)
            ->setInvoicePayment($invoicePayment)
            ->setAmount(15)
            ->setCurrency('NZD');

        // No Invoices/LineItems yet
        $this->assertEmpty($invoicePayment->getLineItems());
        $this->assertEmpty($invoicePayment->getInvoices());

        // Does not have either Invoice yet
        $this->assertFalse($invoicePayment->hasInvoice($invoice1));
        $this->assertFalse($invoicePayment->hasInvoice($invoice2));

        // Add first Line Item to InvoicePayment
        $invoicePayment->addLineItem($paymentLineItem1);

        // We should have the first Invoice but not the second
        $this->assertTrue($invoicePayment->hasInvoice($invoice1));
        $this->assertFalse($invoicePayment->hasInvoice($invoice2));

        // Add second Line Item
        $invoicePayment->addLineItem($paymentLineItem2);

        // We should have both Invoices now
        $this->assertTrue($invoicePayment->hasInvoice($invoice1));
        $this->assertTrue($invoicePayment->hasInvoice($invoice2));

        // There should be two Invoices
        $this->assertNotEmpty($invoicePayment->getLineItems());
        $this->assertCount(2, $invoicePayment->getLineItems());

        $this->assertContainsOnlyInstancesOf(
            InvoicePaymentLineItem::class,
            $invoicePayment->getLineItems()
        );

        $this->assertEquals(123, $invoicePayment->getLineItems()->first()->getInvoice()->getId());
        $this->assertEquals(10, $invoicePayment->getLineItems()->first()->getAmount());

        $this->assertCount(2, $invoicePayment->getInvoices());
        $this->assertEquals('INV-001', $invoicePayment->getInvoices()->first()->getInvoiceNo());

        // Try removing Line Items by Invoice
        $this->assertFalse($invoicePayment->removeLineItemByInvoice(new Invoice()));
        $this->assertTrue($invoicePayment->removeLineItemByInvoice($invoice1));

        // We should only have Invoice2 left now
        $this->assertCount(1, $invoicePayment->getLineItems());
        $this->assertCount(1, $invoicePayment->getInvoices());
        $this->assertFalse($invoicePayment->hasInvoice($invoice1));
        $this->assertTrue($invoicePayment->hasInvoice($invoice2));

        // Test Total Recalculation feature
        $invoicePayment->setAmount(999.99);
        $this->assertEquals(999.99, $invoicePayment->getAmount());
        $paymentAmount = $invoicePayment->recalculateAmount();

        $this->assertEquals(15.00, $paymentAmount);
        $this->assertEquals(15.00, $invoicePayment->getAmount());
    }
}
