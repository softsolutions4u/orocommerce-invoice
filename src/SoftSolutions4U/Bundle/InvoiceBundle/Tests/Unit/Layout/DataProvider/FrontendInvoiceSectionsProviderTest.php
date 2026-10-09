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

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\Layout\DataProvider\FrontendInvoiceSectionsProvider;

/**
 * Unit tests for storefront invoice section visibility.
 *
 * @covers \SoftSolutions4U\Bundle\InvoiceBundle\Layout\DataProvider\FrontendInvoiceSectionsProvider
 */
class FrontendInvoiceSectionsProviderTest extends TestCase
{
    private ManagerRegistry&MockObject $registry;

    private AclHelper&MockObject $aclHelper;

    private MockObject $entityManager;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->aclHelper = $this->createMock(AclHelper::class);
        $this->entityManager = $this->createMock(
            \Doctrine\ORM\EntityManagerInterface::class
        );

        $this->registry
            ->method('getManagerForClass')
            ->willReturn($this->entityManager);
    }

    public function testAllSectionsAreVisibleWhenInvoicesExist(): void
    {
        $queryBuilder = $this->createQueryBuilder();
        $query = $this->createMock(Query::class);

        $query->method('getArrayResult')->willReturn([['id' => 1]]);

        $this->entityManager
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $this->aclHelper
            ->method('apply')
            ->willReturn($query);

        $provider = $this->createProvider();

        self::assertTrue($provider->hasOutstandingInvoices());
        self::assertTrue($provider->hasPaidInvoices());
        self::assertTrue($provider->hasCancelledInvoices());
        self::assertTrue($provider->hasAnyInvoices());
    }

    public function testAllSectionsAreHiddenWhenNoInvoicesExist(): void
    {
        $queryBuilder = $this->createQueryBuilder();
        $query = $this->createMock(Query::class);

        $query->method('getArrayResult')->willReturn([]);

        $this->entityManager
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $this->aclHelper
            ->method('apply')
            ->willReturn($query);

        $provider = $this->createProvider();

        self::assertFalse($provider->hasOutstandingInvoices());
        self::assertFalse($provider->hasPaidInvoices());
        self::assertFalse($provider->hasCancelledInvoices());
        self::assertFalse($provider->hasAnyInvoices());
    }

    public function testSectionResultIsCached(): void
    {
        $queryBuilder = $this->createQueryBuilder();
        $query = $this->createMock(Query::class);

        $query->method('getArrayResult')->willReturn([['id' => 1]]);

        $this->entityManager
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $this->aclHelper
            ->expects(self::once())
            ->method('apply')
            ->willReturn($query);

        $provider = $this->createProvider();

        self::assertTrue($provider->hasOutstandingInvoices());
        self::assertTrue($provider->hasOutstandingInvoices());
    }

    private function createProvider(): FrontendInvoiceSectionsProvider
    {
        return new FrontendInvoiceSectionsProvider(
            $this->registry,
            $this->aclHelper
        );
    }

    private function createQueryBuilder(): QueryBuilder&MockObject
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);

        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();

        return $queryBuilder;
    }
}
