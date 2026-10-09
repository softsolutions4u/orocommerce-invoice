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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Provider;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository;
use Carbon\Carbon;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\LocaleBundle\Model\LocaleSettings;

/**
 * Finds overdue invoices and switches them to the Overdue status.
 */
class InvoiceProvider
{
    /** Invoices updated per flush by markOverdueInvoices(). */
    private const BATCH_SIZE = 100;

    /** @var ManagerRegistry $registry */
    private ManagerRegistry $registry;

    /** @var LocaleSettings $localeSettings */
    private LocaleSettings $localeSettings;

    /**
     * Creates a new InvoiceProvider instance.
     *
     * @param ManagerRegistry $registry
     * @param LocaleSettings $localeSettings
     */
    public function __construct(ManagerRegistry $registry, LocaleSettings $localeSettings)
    {
        $this->registry = $registry;
        $this->localeSettings = $localeSettings;
    }

    /**
     * Returns the overdue invoices.
     *
     * @return array<Invoice>
     */
    public function getOverdueInvoices(): array
    {
        /** @var InvoiceRepository $repo */
        $repo = $this->getInvoiceRepository();

        return $repo->getOverdueInvoices($this->today());
    }

    /**
     * Switches every posted or open invoice past its due date to Overdue, in batches.
     *
     * @return int Number of invoices switched.
     */
    public function markOverdueInvoices(): int
    {
        /** @var InvoiceRepository $repo */
        $repo = $this->getInvoiceRepository();
        $ids = $repo->getOverdueInvoiceIds($this->today());

        if (!$ids) {
            return 0;
        }

        $manager = $this->registry->getManagerForClass(Invoice::class);
        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            foreach ($repo->findBy(['id' => $batch]) as $invoice) {
                $invoice->setStatus(Invoice::STATUS_OVERDUE);
            }

            $manager->flush();
        }

        return count($ids);
    }

    /**
     * Returns the start of today in the configured time zone.
     *
     * @return Carbon
     */
    private function today(): Carbon
    {
        return Carbon::today((new \DateTimeZone($this->localeSettings->getTimeZone()))->getName());
    }

    /**
     * Returns the invoice repository.
     *
     * @return ObjectRepository<Invoice>
     */
    protected function getInvoiceRepository(): ObjectRepository
    {
        return $this->registry->getRepository(Invoice::class);
    }
}
