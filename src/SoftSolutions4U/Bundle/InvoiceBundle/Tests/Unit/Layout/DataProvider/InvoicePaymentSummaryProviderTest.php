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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Layout\DataProvider;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\Layout\DataProvider\InvoicePaymentSummaryProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the invoice figures beside the storefront payment form.
 */
class InvoicePaymentSummaryProviderTest extends TestCase
{
    /**
     * Tests that the summary comes from the invoice being paid, rounded to cents.
     */
    public function testSummaryComesFromTheInvoice(): void
    {
        $invoice = new Invoice();
        $invoice->setCurrency('USD');
        $invoice->setAmount(310.284);
        $invoice->setAmountPaid(15.0);

        $lineItem = new InvoicePaymentLineItem();
        $lineItem->setInvoice($invoice);

        $payment = new InvoicePayment();
        $payment->addLineItem($lineItem);

        $data = (new InvoicePaymentSummaryProvider())->getData($payment);

        self::assertSame('USD', $data['currency']);
        self::assertSame(round($invoice->getGrandTotal(), 2), $data['grandTotal']);
        self::assertSame(15.0, $data['amountPaid']);
        self::assertSame(295.28, $data['balance']);
    }

    /**
     * Tests that a payment with no invoice attached shows zeros rather than failing.
     */
    public function testPaymentWithoutAnInvoiceShowsZeros(): void
    {
        $payment = new InvoicePayment();
        $payment->setCurrency('EUR');

        self::assertSame(
            ['currency' => 'EUR', 'grandTotal' => 0.0, 'amountPaid' => 0.0, 'balance' => 0.0],
            (new InvoicePaymentSummaryProvider())->getData($payment)
        );
    }
}
