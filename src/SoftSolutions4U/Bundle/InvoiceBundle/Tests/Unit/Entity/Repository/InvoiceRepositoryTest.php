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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Entity\Repository;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Oro\Bundle\SecurityBundle\Acl\BasicPermission;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for how invoice queries are filtered.
 *
 * Executing the queries needs a database and belongs in functional tests;
 * these check the filters applied to the query builder.
 */
class InvoiceRepositoryTest extends TestCase
{
    /** @var array<int, array{string, mixed}> */
    private array $calls = [];

    /**
     * Returns the repository.
     *
     * @return InvoiceRepository&MockObject
     */
    private function repository(): InvoiceRepository&MockObject
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnCallback(function (string $where) use (&$qb) {
            $this->calls[] = ['andWhere', $where];

            return $qb;
        });
        $qb->method('setParameter')->willReturnCallback(function (string $key, mixed $value) use (&$qb) {
            $this->calls[] = ['setParameter', [$key, $value]];

            return $qb;
        });

        $repository = $this->getMockBuilder(InvoiceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();
        $repository->method('createQueryBuilder')->with('i')->willReturn($qb);

        return $repository;
    }

    /**
     * Tests that no filters means no conditions.
     */
    public function testNoFilters(): void
    {
        $this->repository()->getFilteredInvoicesQueryBuilder();

        self::assertSame([], $this->calls);
    }

    /**
     * Tests filtering by invoice ids and statuses.
     */
    public function testFiltersByIdsAndStatuses(): void
    {
        $this->repository()->getFilteredInvoicesQueryBuilder([3, 4], Invoice::UNPAID_STATUSES);

        self::assertSame([
            ['andWhere', 'i.id IN(:invoiceIds)'],
            ['setParameter', ['invoiceIds', [3, 4]]],
            ['andWhere', 'i.status IN(:statusIds)'],
            ['setParameter', ['statusIds', Invoice::UNPAID_STATUSES]],
        ], $this->calls);
    }

    /**
     * Tests filtering by status alone.
     */
    public function testFiltersByStatusOnly(): void
    {
        $this->repository()->getFilteredInvoicesQueryBuilder([], [Invoice::STATUS_PAID]);

        self::assertSame([
            ['andWhere', 'i.status IN(:statusIds)'],
            ['setParameter', ['statusIds', [Invoice::STATUS_PAID]]],
        ], $this->calls);
    }

    /**
     * Tests that getCurrentCustomerInvoices applies the ACL filter and returns the query result.
     */
    public function testGetCurrentCustomerInvoicesAppliesAclAndReturnsResult(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $expectedInvoices = [new Invoice(), new Invoice()];

        $query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn($expectedInvoices);

        $aclHelper = $this->createMock(AclHelper::class);
        $aclHelper->expects(self::once())
            ->method('apply')
            ->with(
                $qb,
                BasicPermission::VIEW,
                [AclHelper::CHECK_RELATIONS => true]
            )
            ->willReturn($query);

        $repository = $this->getMockBuilder(InvoiceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFilteredInvoicesQueryBuilder'])
            ->getMock();
        $repository->expects(self::once())
            ->method('getFilteredInvoicesQueryBuilder')
            ->with([3, 4], [Invoice::STATUS_OPEN])
            ->willReturn($qb);

        $result = $repository->getCurrentCustomerInvoices($aclHelper, [3, 4], [Invoice::STATUS_OPEN]);

        self::assertSame($expectedInvoices, $result);
    }

    /**
     * Tests that getCurrentCustomerInvoices uses empty filters by default.
     */
    public function testGetCurrentCustomerInvoicesWithNoFilters(): void
    {
        $qb = $this->createMock(QueryBuilder::class);

        $query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn([]);

        $aclHelper = $this->createMock(AclHelper::class);
        $aclHelper->method('apply')->willReturn($query);

        $repository = $this->getMockBuilder(InvoiceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFilteredInvoicesQueryBuilder'])
            ->getMock();
        $repository->expects(self::once())
            ->method('getFilteredInvoicesQueryBuilder')
            ->with([], [])
            ->willReturn($qb);

        self::assertSame([], $repository->getCurrentCustomerInvoices($aclHelper));
    }

    /**
     * Tests that findOneByOrderId builds the expected query and returns the result.
     */
    public function testFindOneByOrderIdBuildsQueryAndReturnsTheInvoice(): void
    {
        $expected = new Invoice();

        $query = $this->createMock(AbstractQuery::class);
        $query->expects(self::once())->method('getOneOrNullResult')->willReturn($expected);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())->method('andWhere')->with('i.order = :orderId')->willReturnSelf();
        $qb->expects(self::once())->method('setParameter')->with('orderId', 42)->willReturnSelf();
        $qb->expects(self::once())->method('setMaxResults')->with(1)->willReturnSelf();
        $qb->expects(self::once())->method('getQuery')->willReturn($query);

        $repository = $this->getMockBuilder(InvoiceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();
        $repository->method('createQueryBuilder')->with('i')->willReturn($qb);

        self::assertSame($expected, $repository->findOneByOrderId(42));
    }

    /**
     * Tests that findOneByOrderId returns null when there is no matching invoice.
     */
    public function testFindOneByOrderIdReturnsNullWhenNotFound(): void
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repository = $this->getMockBuilder(InvoiceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();
        $repository->method('createQueryBuilder')->willReturn($qb);

        self::assertNull($repository->findOneByOrderId(999));
    }

    /**
     * Tests that getOverdueInvoices filters for posted and open invoices and adds the due-date constraint.
     */
    public function testGetOverdueInvoicesFiltersUnpaidAndAddsDueDate(): void
    {
        $dueDate = new \DateTimeImmutable('2026-09-21');
        $expectedInvoices = [new Invoice()];

        $query = $this->createMock(AbstractQuery::class);
        $query->expects(self::once())->method('getResult')->willReturn($expectedInvoices);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())
            ->method('andWhere')
            ->with('i.dueDate < :dueDate')
            ->willReturnSelf();
        $qb->expects(self::once())
            ->method('setParameter')
            ->with('dueDate', $dueDate, Types::DATE_MUTABLE)
            ->willReturnSelf();
        $qb->expects(self::once())->method('getQuery')->willReturn($query);

        $repository = $this->getMockBuilder(InvoiceRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFilteredInvoicesQueryBuilder'])
            ->getMock();
        $repository->expects(self::once())
            ->method('getFilteredInvoicesQueryBuilder')
            ->with([], Invoice::OVERDUE_CANDIDATE_STATUSES)
            ->willReturn($qb);

        self::assertSame($expectedInvoices, $repository->getOverdueInvoices($dueDate));
    }
}
