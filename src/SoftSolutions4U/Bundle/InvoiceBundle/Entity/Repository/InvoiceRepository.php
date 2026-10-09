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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Oro\Bundle\SecurityBundle\Acl\BasicPermission;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;

/**
 * Invoice repository.
 */
class InvoiceRepository extends EntityRepository
{
    /**
     * Returns the current customer invoices.
     *
     * @param array<int> $invoiceIds
     * @param array<string> $statusIds
     * @param AclHelper $aclHelper
     * @return array<Invoice>
     */
    public function getCurrentCustomerInvoices(
        AclHelper $aclHelper,
        array $invoiceIds = [],
        array $statusIds = [],
    ): array {
        $qb = $this->getFilteredInvoicesQueryBuilder($invoiceIds, $statusIds);

        // Restrict QueryBuilder with ACL
        $query = $aclHelper->apply($qb, BasicPermission::VIEW, [AclHelper::CHECK_RELATIONS => true]);

        return $query->getResult();
    }

    /**
     * Returns the invoice belonging to the given order, if one exists.
     *
     * @param int $orderId
     * @return Invoice|null
     */
    public function findOneByOrderId(int $orderId): ?Invoice
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.order = :orderId')
            ->setParameter('orderId', $orderId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns the overdue invoices.
     *
     * @param DateTimeInterface $dueDate
     * @return array<Invoice>
     */
    public function getOverdueInvoices(DateTimeInterface $dueDate): array
    {
        $qb = $this->getFilteredInvoicesQueryBuilder([], Invoice::OVERDUE_CANDIDATE_STATUSES);

        $qb
            ->andWhere('i.dueDate < :dueDate')
            ->setParameter('dueDate', $dueDate, Types::DATE_MUTABLE);

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the ids of invoices that are past their due date and may be switched to Overdue.
     *
     * @param DateTimeInterface $dueDate
     * @return array<int, int>
     */
    public function getOverdueInvoiceIds(DateTimeInterface $dueDate): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.status IN (:statuses)')
            ->andWhere('i.dueDate < :dueDate')
            ->setParameter('statuses', Invoice::OVERDUE_CANDIDATE_STATUSES)
            ->setParameter('dueDate', $dueDate, Types::DATE_MUTABLE)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Returns the filtered invoices query builder.
     *
     * @param array<int> $invoiceIds
     * @param array<string> $statusIds
     * @return QueryBuilder
     */
    public function getFilteredInvoicesQueryBuilder(
        array $invoiceIds = [],
        array $statusIds = [],
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('i');

        if (!empty($invoiceIds)) {
            /** Restrict query to only provided Invoice IDs */
            $qb
                ->andWhere('i.id IN(:invoiceIds)')
                ->setParameter('invoiceIds', $invoiceIds);
        }

        if (!empty($statusIds)) {
            /** Restrict query to only provided Invoice Status (enum) IDs */
            $qb
                ->andWhere('i.status IN(:statusIds)')
                ->setParameter('statusIds', $statusIds);
        }

        return $qb;
    }
}
