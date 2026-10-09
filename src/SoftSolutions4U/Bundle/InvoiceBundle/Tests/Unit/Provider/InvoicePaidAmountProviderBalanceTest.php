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
use SoftSolutions4U\Bundle\InvoiceBundle\Model\InvoicePaymentStatus;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the balance and payment-status half of InvoicePaidAmountProvider.
 *
 * The lifecycle status rules are covered by InvoicePaidAmountProviderStatusTest.
 * The ledger query itself is exercised against a database in functional tests;
 * here it is replaced so the arithmetic around it can be checked precisely.
 */
class InvoicePaidAmountProviderBalanceTest extends TestCase
{
    /**
     * Provides the data sets for the test.
     *
     * @param float $ledgerPaid
     * @return InvoicePaidAmountProvider
     */
    private function provider(float $ledgerPaid): InvoicePaidAmountProvider
    {
        $provider = $this->getMockBuilder(InvoicePaidAmountProvider::class)
            ->setConstructorArgs([$this->createMock(ManagerRegistry::class)])
            ->onlyMethods(['getPaidAmount'])
            ->getMock();
        $provider->method('getPaidAmount')->willReturn($ledgerPaid);

        return $provider;
    }

    /**
     * Tests that an unsaved invoice has nothing paid, without touching the database.
     */
    public function testUnsavedInvoiceHasNothingPaid(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManagerForClass');

        self::assertSame(0.0, (new InvoicePaidAmountProvider($registry))->getPaidAmount(new Invoice()));
    }

    /**
     * Tests the remaining balance, never below zero and rounded to cents.
     */
    public function testRemainingBalance(): void
    {
        $invoice = (new Invoice())->setAmount(100.0);

        self::assertSame(60.0, $this->provider(40.0)->getRemainingBalance($invoice));
        self::assertSame(0.0, $this->provider(100.0)->getRemainingBalance($invoice));
        self::assertSame(
            0.0,
            $this->provider(120.0)->getRemainingBalance($invoice),
            'Overpayment is not a negative balance'
        );
        self::assertSame(0.01, $this->provider(99.99)->getRemainingBalance($invoice));
    }

    /**
     * Tests that applyTo writes the ledger total and a matching payment status.
     */
    public function testApplyToWritesPaidAmountAndPaymentStatus(): void
    {
        $invoice = (new Invoice())->setAmount(100.0);
        $invoice->setStatus(Invoice::STATUS_DRAFT);

        self::assertSame(40.0, $this->provider(40.0)->applyTo($invoice));
        self::assertSame(40.0, $invoice->getAmountPaid());
        self::assertSame(InvoicePaymentStatus::PARTIALLY, $invoice->getPaymentStatus());

        $this->provider(100.0)->applyTo($invoice);
        self::assertSame(InvoicePaymentStatus::FULL, $invoice->getPaymentStatus());
    }
}
