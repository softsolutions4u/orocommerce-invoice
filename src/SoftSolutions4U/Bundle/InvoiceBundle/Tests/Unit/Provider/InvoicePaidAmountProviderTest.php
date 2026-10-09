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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;

class InvoicePaidAmountProviderTest extends TestCase
{
    /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $em;

    private function provider(): InvoicePaidAmountProvider
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        return new InvoicePaidAmountProvider($registry);
    }

    private function mockPaidSum(float $sum): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($sum);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('createQueryBuilder')->willReturn($qb);
    }

    public function testUnsavedInvoiceHasNothingPaid(): void
    {
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        // No id → getPaidAmount short-circuits to 0.0

        $registry = $this->createMock(ManagerRegistry::class);
        $provider = new InvoicePaidAmountProvider($registry);

        self::assertSame(0.0, $provider->getPaidAmount($invoice));
    }

    public function testGetPaidAmountRoundsToTwoDecimals(): void
    {
        $this->mockPaidSum(99.999);
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 1);

        self::assertSame(100.00, $this->provider()->getPaidAmount($invoice));
    }

    public function testRemainingBalance(): void
    {
        $this->mockPaidSum(40.00);
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 1);

        self::assertSame(60.00, $this->provider()->getRemainingBalance($invoice));
    }

    public function testRemainingBalanceNeverGoesNegative(): void
    {
        $this->mockPaidSum(150.00);
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 1);

        self::assertSame(0.0, $this->provider()->getRemainingBalance($invoice));
    }

    public function testApplyToWritesPaidAmountAndPaymentStatus(): void
    {
        $this->mockPaidSum(50.00);
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        $invoice->setStatus(Invoice::STATUS_POSTED);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 1);

        $provider = $this->provider();
        $paid = $provider->applyTo($invoice);

        self::assertSame(50.00, $paid);
        self::assertSame(50.00, $invoice->getAmountPaid());
        self::assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->getStatus());
    }

    public function testApplyToMarksInvoicePaidInFull(): void
    {
        $this->mockPaidSum(100.00);
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        $invoice->setStatus(Invoice::STATUS_POSTED);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 1);

        $this->provider()->applyTo($invoice);

        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
    }

    public function testApplyToMarksOverdueWhenPastDueWithBalance(): void
    {
        $this->mockPaidSum(0.00);
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        $invoice->setStatus(Invoice::STATUS_POSTED);
        $invoice->setDueDate(new \DateTime('-5 days'));
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 1);

        $this->provider()->applyTo($invoice);

        self::assertSame(Invoice::STATUS_OVERDUE, $invoice->getStatus());
    }

    public function testApplyToLeavesDraftAsDraft(): void
    {
        $this->mockPaidSum(100.00);
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        $invoice->setStatus(Invoice::STATUS_DRAFT);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 1);

        $this->provider()->applyTo($invoice);

        self::assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus());
    }

    public function testApplyToLeavesCancelledAsCancelled(): void
    {
        $this->mockPaidSum(100.00);
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        $invoice->setStatus(Invoice::STATUS_CANCELLED);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 1);

        $this->provider()->applyTo($invoice);

        self::assertSame(Invoice::STATUS_CANCELLED, $invoice->getStatus());
    }

    public function testApplyToReturnsPreviouslyPaidInvoiceToPostedWhenNothingPaid(): void
    {
        $this->mockPaidSum(0.00);
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        $invoice->setStatus(Invoice::STATUS_PAID);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 1);

        $this->provider()->applyTo($invoice);

        self::assertSame(Invoice::STATUS_POSTED, $invoice->getStatus());
    }

    public function testApplyToReturnsPreviouslyPartiallyPaidInvoiceToPostedWhenNothingPaid(): void
    {
        $this->mockPaidSum(0.00);
        $invoice = new Invoice();
        $invoice->setAmount(100.00);
        $invoice->setStatus(Invoice::STATUS_PARTIALLY_PAID);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 1);

        $this->provider()->applyTo($invoice);

        self::assertSame(Invoice::STATUS_POSTED, $invoice->getStatus());
    }
}
