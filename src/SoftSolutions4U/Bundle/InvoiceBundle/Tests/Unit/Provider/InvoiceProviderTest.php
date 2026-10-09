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

use Carbon\Carbon;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoiceProvider;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\LocaleBundle\Model\LocaleSettings;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for finding overdue invoices.
 */
class InvoiceProviderTest extends TestCase
{
    /**
     * Tears down the test fixture.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    /**
     * Tests that "today" is taken in the configured time zone, not the server's.
     */
    public function testOverdueInvoicesAreDueBeforeTodayInTheConfiguredTimeZone(): void
    {
        // 20:00 UTC on 15 Sep is already 16 Sep in Auckland (UTC+12).
        Carbon::setTestNow(Carbon::parse('2026-09-15 20:00:00', 'UTC'));

        $overdue = [new Invoice()];

        $repository = $this->createMock(InvoiceRepository::class);
        $repository->expects(self::once())
            ->method('getOverdueInvoices')
            ->with(self::callback(static function (\DateTimeInterface $date): bool {
                return '2026-09-16 00:00:00' === $date->format('Y-m-d H:i:s')
                    && 'Pacific/Auckland' === $date->getTimezone()->getName();
            }))
            ->willReturn($overdue);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->with(Invoice::class)->willReturn($repository);

        $localeSettings = $this->createMock(LocaleSettings::class);
        $localeSettings->method('getTimeZone')->willReturn('Pacific/Auckland');

        self::assertSame($overdue, (new InvoiceProvider($registry, $localeSettings))->getOverdueInvoices());
    }

    /**
     * Tests that overdue invoices are switched in batches and each batch is flushed.
     */
    public function testMarkOverdueInvoicesUpdatesInBatches(): void
    {
        $ids = range(1, 150);
        $firstBatch = [new Invoice(), new Invoice()];
        $secondBatch = [new Invoice()];

        $repository = $this->createMock(InvoiceRepository::class);
        $repository->method('getOverdueInvoiceIds')->willReturn($ids);
        $repository->expects(self::exactly(2))
            ->method('findBy')
            ->willReturnOnConsecutiveCalls($firstBatch, $secondBatch);

        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(2))->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repository);
        $registry->method('getManagerForClass')->willReturn($manager);

        $localeSettings = $this->createMock(LocaleSettings::class);
        $localeSettings->method('getTimeZone')->willReturn('UTC');

        self::assertSame(150, (new InvoiceProvider($registry, $localeSettings))->markOverdueInvoices());

        foreach (array_merge($firstBatch, $secondBatch) as $invoice) {
            self::assertSame(Invoice::STATUS_OVERDUE, $invoice->getStatus());
        }
    }

    /**
     * Tests that nothing is loaded or flushed when no invoice is overdue.
     */
    public function testMarkOverdueInvoicesDoesNothingWithoutOverdueInvoices(): void
    {
        $repository = $this->createMock(InvoiceRepository::class);
        $repository->method('getOverdueInvoiceIds')->willReturn([]);
        $repository->expects(self::never())->method('findBy');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repository);
        $registry->expects(self::never())->method('getManagerForClass');

        $localeSettings = $this->createMock(LocaleSettings::class);
        $localeSettings->method('getTimeZone')->willReturn('UTC');

        self::assertSame(0, (new InvoiceProvider($registry, $localeSettings))->markOverdueInvoices());
    }
}
