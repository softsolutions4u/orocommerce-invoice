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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Layout\DataProvider;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Model\InvoicePaymentStatus;

/**
 * Tells the storefront "Invoices" page which sections have invoices to show.
 *
 * Each check uses the same conditions as the matching storefront grid
 * (frontend-softsolutions4u-invoices-outstanding/paid/cancelled-grid) and the same
 * ACL, so a section is hidden exactly when its grid would be empty.
 */
class FrontendInvoiceSectionsProvider
{
    /** @var ManagerRegistry $registry */
    private ManagerRegistry $registry;

    /** @var AclHelper $aclHelper */
    private AclHelper $aclHelper;

    /** @var array<string, bool> $cache Results per section for the current request. */
    private array $cache = [];

    /**
     * Creates a new FrontendInvoiceSectionsProvider instance.
     *
     * @param ManagerRegistry $registry
     * @param AclHelper $aclHelper
     */
    public function __construct(ManagerRegistry $registry, AclHelper $aclHelper)
    {
        $this->registry = $registry;
        $this->aclHelper = $aclHelper;
    }

    /**
     * Returns whether the customer has outstanding (not fully paid, not cancelled) invoices.
     *
     * @return bool
     */
    public function hasOutstandingInvoices(): bool
    {
        return $this->hasInvoices('outstanding', static function (QueryBuilder $qb): void {
            $qb->andWhere('invoice.paymentStatus != :paidInFull')
                ->andWhere('invoice.status != :cancelled')
                ->setParameter('paidInFull', InvoicePaymentStatus::FULL)
                ->setParameter('cancelled', Invoice::STATUS_CANCELLED);
        });
    }

    /**
     * Returns whether the customer has fully paid invoices.
     *
     * @return bool
     */
    public function hasPaidInvoices(): bool
    {
        return $this->hasInvoices('paid', static function (QueryBuilder $qb): void {
            $qb->andWhere('invoice.paymentStatus = :paidInFull')
                ->andWhere('invoice.status != :cancelled')
                ->setParameter('paidInFull', InvoicePaymentStatus::FULL)
                ->setParameter('cancelled', Invoice::STATUS_CANCELLED);
        });
    }

    /**
     * Returns whether the customer has cancelled invoices.
     *
     * @return bool
     */
    public function hasCancelledInvoices(): bool
    {
        return $this->hasInvoices('cancelled', static function (QueryBuilder $qb): void {
            $qb->andWhere('invoice.status = :cancelled')
                ->setParameter('cancelled', Invoice::STATUS_CANCELLED);
        });
    }

    /**
     * Returns whether the customer has any invoice in any section.
     *
     * @return bool
     */
    public function hasAnyInvoices(): bool
    {
        return $this->hasOutstandingInvoices() || $this->hasPaidInvoices() || $this->hasCancelledInvoices();
    }

    /**
     * Runs an ACL-protected existence query for one section, once per request.
     *
     * @param string $section
     * @param callable(QueryBuilder): void $applyConditions
     * @return bool
     */
    private function hasInvoices(string $section, callable $applyConditions): bool
    {
        if (!array_key_exists($section, $this->cache)) {
            $qb = $this->registry->getManagerForClass(Invoice::class)
                ->createQueryBuilder()
                ->select('invoice.id')
                ->from(Invoice::class, 'invoice')
                ->where('invoice.status != :draft')
                ->setParameter('draft', Invoice::STATUS_DRAFT)
                ->setMaxResults(1);

            $applyConditions($qb);

            $this->cache[$section] = [] !== $this->aclHelper->apply($qb)->getArrayResult();
        }

        return $this->cache[$section];
    }
}
