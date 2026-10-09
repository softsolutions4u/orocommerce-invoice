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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Generator;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Invoice number generator: INV-{year}-{month}-{sequence}, e.g. INV-2026-09-00019.
 *
 * The sequence restarts at 00001 each month. The numbering rule (nextNumber)
 * is kept apart from the database lookup (findLastInvoiceNo) and the clock
 * (now) so each can be tested on its own.
 */
class InvoiceNumberGenerator implements InvoiceNumberGeneratorInterface
{
    /** Per-month counter table, created by the v1_2 migration. */
    public const SEQUENCE_TABLE = 'softsolutions4u_invoice_sequence';

    private const PREFIX = 'INV-';
    private const NUMBER_FORMAT = '%05d';

    /** @var EntityManagerInterface $em */
    private EntityManagerInterface $em;

    /**
     * Creates a new InvoiceNumberGenerator instance.
     *
     * @param EntityManagerInterface $em
     */
    public function __construct(
        EntityManagerInterface $em
    ) {
        $this->em = $em;
    }

    /**
     * Returns the next sequential invoice number.
     *
     * @return string
     */
    public function generate(): string
    {
        $now = $this->now();
        $period = $now->format('Y-m');

        // The last stored number only seeds a period counter that does not exist yet (e.g. after an upgrade).
        $seed = $this->sequenceOf($this->findLastInvoiceNo($period));

        return $this->format($period, $this->allocateNumber($period, $seed));
    }

    /**
     * Atomically reserves the next sequence number of a month.
     *
     * The counter row is incremented under a row lock, so concurrent requests can never receive the same number.
     * A number is skipped if the invoice is never saved.
     *
     * @param string $period Month in Y-m format.
     * @param int $seed Highest number already used in the month, used when the counter row is created.
     * @return int
     */
    protected function allocateNumber(string $period, int $seed): int
    {
        $connection = $this->em->getConnection();
        $table = self::SEQUENCE_TABLE;

        $exists = $connection->fetchOne(sprintf('SELECT 1 FROM %s WHERE period = ?', $table), [$period]);
        if (false === $exists) {
            $this->createCounterRow($connection, $period, $seed);
        }

        return (int) $connection->transactional(
            static function ($connection) use ($table, $period): int {
                $connection->executeStatement(
                    sprintf('UPDATE %s SET last_number = last_number + 1 WHERE period = ?', $table),
                    [$period]
                );

                return (int) $connection->fetchOne(
                    sprintf('SELECT last_number FROM %s WHERE period = ?', $table),
                    [$period]
                );
            }
        );
    }

    /**
     * Creates the counter row of a month, ignoring a row another request created at the same moment.
     *
     * PostgreSQL and MySQL skip the duplicate in SQL, so no error aborts an open transaction.
     *
     * @param Connection $connection
     * @param string $period
     * @param int $seed
     * @return void
     */
    private function createCounterRow(Connection $connection, string $period, int $seed): void
    {
        $platform = $connection->getDatabasePlatform();
        $columns = sprintf('%s (period, last_number) VALUES (?, ?)', self::SEQUENCE_TABLE);

        if ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement(
                sprintf('INSERT INTO %s ON CONFLICT (period) DO NOTHING', $columns),
                [$period, $seed]
            );

            return;
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement(sprintf('INSERT IGNORE INTO %s', $columns), [$period, $seed]);

            return;
        }

        try {
            $connection->executeStatement(sprintf('INSERT INTO %s', $columns), [$period, $seed]);
        } catch (UniqueConstraintViolationException) {
            // Another request created the row first; it is incremented by the caller.
        }
    }

    /**
     * Extracts the numeric sequence from an invoice number.
     *
     * @param string|null $invoiceNo
     * @return int
     */
    private function sequenceOf(?string $invoiceNo): int
    {
        if (null !== $invoiceNo && preg_match('/^INV-\d{4}-\d{2}-(\d+)$/', $invoiceNo, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Formats an invoice number.
     *
     * @param string $period
     * @param int $number
     * @return string
     */
    private function format(string $period, int $number): string
    {
        return sprintf('%s%s-%s', self::PREFIX, $period, sprintf(self::NUMBER_FORMAT, $number));
    }

    /**
     * Returns the number that follows $lastInvoiceNo in the month of $now.
     *
     * Starts at 00001 when there is no previous number, or when it is not in
     * the expected format. The sequence grows past five digits rather than
     * wrapping, so numbers stay unique.
     *
     * @param string|null $lastInvoiceNo
     * @param \DateTimeInterface $now
     * @return string
     */
    public function nextNumber(?string $lastInvoiceNo, \DateTimeInterface $now): string
    {
        $nextNumber = 1;

        if (null !== $lastInvoiceNo && preg_match('/^INV-\d{4}-\d{2}-(\d+)$/', $lastInvoiceNo, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }

        return sprintf('%s%s-%s', self::PREFIX, $now->format('Y-m'), sprintf(self::NUMBER_FORMAT, $nextNumber));
    }

    /**
     * Returns the most recent invoice number issued in the given Y-m period, if any.
     *
     * @param string $datePeriod
     * @return string|null
     */
    protected function findLastInvoiceNo(string $datePeriod): ?string
    {
        $lastInvoice = $this->em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->where('i.invoiceNo LIKE :prefix')
            ->setParameter('prefix', self::PREFIX . $datePeriod . '-%')
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $lastInvoice instanceof Invoice ? $lastInvoice->getInvoiceNo() : null;
    }

    /**
     * Returns the now.
     *
     * @return \DateTimeInterface
     */
    protected function now(): \DateTimeInterface
    {
        return new \DateTime('now');
    }
}
