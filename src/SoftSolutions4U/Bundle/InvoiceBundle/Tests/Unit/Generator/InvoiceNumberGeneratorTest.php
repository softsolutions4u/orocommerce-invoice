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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Generator;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\Generator\InvoiceNumberGenerator;

class InvoiceNumberGeneratorTest extends TestCase
{
    /**
     * @dataProvider nextNumberDataProvider
     */
    #[DataProvider('nextNumberDataProvider')]
    public function testNextNumber(?string $lastInvoiceNo, string $expected): void
    {
        $generator = new InvoiceNumberGenerator($this->createMock(EntityManagerInterface::class));

        self::assertSame($expected, $generator->nextNumber($lastInvoiceNo, new \DateTimeImmutable('2026-09-16')));
    }

    /** @return \Generator<string, array{lastInvoiceNo: ?string, expected: string}> */
    public static function nextNumberDataProvider(): \Generator
    {
        yield 'first invoice of the month' => ['lastInvoiceNo' => null, 'expected' => 'INV-2026-09-00001'];
        yield 'continues the sequence' => ['lastInvoiceNo' => 'INV-2026-09-00018', 'expected' => 'INV-2026-09-00019'];
        yield 'carries across digits' => ['lastInvoiceNo' => 'INV-2026-09-00099', 'expected' => 'INV-2026-09-00100'];
        yield 'grows past five digits' => ['lastInvoiceNo' => 'INV-2026-09-99999', 'expected' => 'INV-2026-09-100000'];
        yield 'unrecognised format restarts' => ['lastInvoiceNo' => 'LEGACY-42', 'expected' => 'INV-2026-09-00001'];
        yield 'empty string restarts' => ['lastInvoiceNo' => '', 'expected' => 'INV-2026-09-00001'];
    }

    public function testGenerateUsesTheCurrentMonthAndAllocatesTheNextNumber(): void
    {
        $connection = $this->createMock(Connection::class);

        // Row exists for the period.
        $connection->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $params = []) {
                if (str_contains($sql, 'SELECT 1 FROM')) {
                    return 1;
                }
                if (str_contains($sql, 'SELECT last_number')) {
                    return 5;
                }
                return false;
            }
        );
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('transactional')->willReturnCallback(
            static fn (callable $cb) => $cb($connection)
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $repository = $this->createMock(EntityRepository::class);
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $repository->method('createQueryBuilder')->willReturn($qb);
        $em->method('getRepository')->willReturn($repository);

        $generator = new InvoiceNumberGenerator($em);

        $number = $generator->generate();

        self::assertStringStartsWith('INV-', $number);
        self::assertStringEndsWith('-00005', $number);
    }

    public function testGenerateSeedsANewPeriodWhenTheCounterRowIsCreated(): void
    {
        $connection = $this->createMock(Connection::class);

        // First fetch returns null (row doesn't exist) → INSERT
        $connection->method('fetchOne')->willReturnCallback(
            static function (string $sql) {
                if (str_contains($sql, 'SELECT 1 FROM')) {
                    return false;
                }
                if (str_contains($sql, 'SELECT last_number')) {
                    return 1;
                }
                return false;
            }
        );
        $connection->expects(self::atLeastOnce())->method('executeStatement');
        $connection->method('transactional')->willReturnCallback(
            static fn (callable $cb) => $cb($connection)
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $repository = $this->createMock(EntityRepository::class);
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $repository->method('createQueryBuilder')->willReturn($qb);
        $em->method('getRepository')->willReturn($repository);

        $generator = new InvoiceNumberGenerator($em);
        $number = $generator->generate();

        self::assertStringStartsWith('INV-', $number);
    }

    /**
     * Tests that PostgreSQL uses ON CONFLICT, so a concurrent insert cannot abort the transaction.
     */
    public function testPostgreSqlCreatesTheCounterRowWithoutAConflictError(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'SELECT last_number') ? 1 : false
        );
        $connection->method('transactional')->willReturnCallback(
            static fn (callable $callback) => $callback($connection)
        );

        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 1;
            }
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $generator = $this->getMockBuilder(InvoiceNumberGenerator::class)
            ->setConstructorArgs([$entityManager])
            ->onlyMethods(['findLastInvoiceNo'])
            ->getMock();
        $generator->method('findLastInvoiceNo')->willReturn(null);

        $generator->generate();

        self::assertStringContainsString('ON CONFLICT (period) DO NOTHING', $statements[0]);
        self::assertStringContainsString(InvoiceNumberGenerator::SEQUENCE_TABLE, $statements[0]);
    }
}
