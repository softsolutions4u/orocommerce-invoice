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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Provider;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the invoice lifecycle status transitions.
 *
 * Draft -> Posted -> Partially Paid -> Paid, with Overdue applying whenever the
 * due date has passed and a balance remains.
 */
class InvoicePaidAmountProviderStatusTest extends TestCase
{
    /** @var InvoicePaidAmountProvider $provider */
    private InvoicePaidAmountProvider $provider;

    /**
     * Sets up the provider with a mocked registry.
     */
    protected function setUp(): void
    {
        $this->provider = new InvoicePaidAmountProvider($this->createMock(ManagerRegistry::class));
    }

    /**
     * Runs the status rules against an invoice without touching the ledger.
     *
     * @param Invoice $invoice
     * @param float $paid
     */
    private function applyStatus(Invoice $invoice, float $paid): void
    {
        $method = new \ReflectionMethod($this->provider, 'applyLifecycleStatus');
        $method->invoke($this->provider, $invoice, $paid);
    }

    /**
     * Builds an invoice in the given state.
     *
     * @param string $status
     * @param float $amount
     * @param string|null $dueDate
     * @return Invoice
     */
    private function invoice(string $status, float $amount, ?string $dueDate = null): Invoice
    {
        $invoice = new Invoice();
        $invoice->setStatus($status);
        $invoice->setAmount($amount);

        if (null !== $dueDate) {
            $invoice->setDueDate(new \DateTime($dueDate));
        }

        return $invoice;
    }

    /**
     * Tests that a draft is never moved by the payment ledger.
     */
    public function testDraftIsNeverChanged(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_DRAFT, 100.00);

        $this->applyStatus($invoice, 100.00);

        self::assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus());
    }

    /**
     * Tests that a cancelled invoice stays cancelled.
     */
    public function testCancelledIsNeverChanged(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_CANCELLED, 100.00);

        $this->applyStatus($invoice, 100.00);

        self::assertSame(Invoice::STATUS_CANCELLED, $invoice->getStatus());
    }

    /**
     * Tests that a posted invoice with no payment stays posted.
     */
    public function testPostedWithNoPaymentStaysPosted(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 100.00, '+10 days');

        $this->applyStatus($invoice, 0.00);

        self::assertSame(Invoice::STATUS_POSTED, $invoice->getStatus());
    }

    /**
     * Tests that a part payment moves the invoice to partially paid.
     */
    public function testPartPaymentBecomesPartiallyPaid(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 100.00, '+10 days');

        $this->applyStatus($invoice, 40.00);

        self::assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->getStatus());
    }

    /**
     * Tests that settling the balance moves the invoice to paid.
     */
    public function testFullPaymentBecomesPaid(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_PARTIALLY_PAID, 100.00, '+10 days');

        $this->applyStatus($invoice, 100.00);

        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
    }

    /**
     * Tests that a sub-cent shortfall, which rounds to the total, counts as paid.
     */
    public function testSubCentShortfallCountsAsPaid(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 100.00, '+10 days');

        $this->applyStatus($invoice, 99.995);

        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
    }

    /**
     * Tests that an overpayment counts as paid rather than partially paid.
     */
    public function testOverpaymentCountsAsPaid(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 100.00, '+10 days');

        $this->applyStatus($invoice, 120.00);

        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
    }

    /**
     * Tests that an unpaid invoice past its due date becomes overdue.
     */
    public function testUnpaidPastDueBecomesOverdue(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 100.00, '-1 day');

        $this->applyStatus($invoice, 0.00);

        self::assertSame(Invoice::STATUS_OVERDUE, $invoice->getStatus());
    }

    /**
     * Tests that a part-paid invoice past its due date keeps Partially Paid.
     */
    public function testPartiallyPaidPastDueStaysPartiallyPaid(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_PARTIALLY_PAID, 100.00, '-1 day');

        $this->applyStatus($invoice, 40.00);

        self::assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->getStatus());
    }

    /**
     * Tests that a settled invoice is never reported overdue.
     */
    public function testPaidInvoicePastDueIsNotOverdue(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_OVERDUE, 100.00, '-30 days');

        $this->applyStatus($invoice, 100.00);

        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
    }

    /**
     * Tests that an invoice with no due date is never overdue.
     */
    public function testInvoiceWithoutDueDateIsNeverOverdue(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 100.00);

        $this->applyStatus($invoice, 0.00);

        self::assertSame(Invoice::STATUS_POSTED, $invoice->getStatus());
    }

    /**
     * Tests that reversing a payment returns the invoice to posted.
     */
    public function testRemovingAllPaymentsReturnsToPosted(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_PAID, 100.00, '+10 days');

        $this->applyStatus($invoice, 0.00);

        self::assertSame(Invoice::STATUS_POSTED, $invoice->getStatus());
    }

    /**
     * Tests that a payment a full cent short of the total is only partially paid.
     */
    public function testOneCentShortIsPartiallyPaid(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED, 100.00, '+10 days');

        $this->applyStatus($invoice, 99.99);

        self::assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->getStatus());
    }
}
