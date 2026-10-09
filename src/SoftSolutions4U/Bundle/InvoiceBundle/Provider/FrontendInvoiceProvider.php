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
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\FormBundle\Model\UpdateFactory;
use Oro\Bundle\FormBundle\Model\UpdateInterface;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Symfony\Component\Form\FormInterface;

/**
 * Frontend invoice provider.
 */
class FrontendInvoiceProvider
{
    /** @var UpdateFactory $updateFactory */
    protected UpdateFactory $updateFactory;

    /** @var ManagerRegistry $registry */
    protected ManagerRegistry $registry;

    /** @var AclHelper $aclHelper */
    protected AclHelper $aclHelper;

    /**
     * Creates a new FrontendInvoiceProvider instance.
     *
     * @param ManagerRegistry $registry
     * @param AclHelper $aclHelper
     * @param UpdateFactory $updateFactory
     */
    public function __construct(
        ManagerRegistry $registry,
        AclHelper $aclHelper,
        UpdateFactory $updateFactory,
    ) {
        $this->registry = $registry;
        $this->aclHelper = $aclHelper;
        $this->updateFactory = $updateFactory;
    }

    /**
     * Return a list of all the currently logged-in Customer's unpaid invoices.
     *
     * @param array<int> $invoiceIds
     * @return array<int, Invoice>
     */
    public function getCurrentCustomerUnpaidInvoices(array $invoiceIds = []): array
    {
        /** @var InvoiceRepository $repository */
        $repository = $this->getInvoiceRepository();

        return $repository->getCurrentCustomerInvoices(
            $this->aclHelper,
            $invoiceIds,
            Invoice::UNPAID_STATUSES
        );
    }

    /**
     * Return a list of all the currently logged-in customer's invoices.
     *
     * @param array<int> $invoiceIds
     * @return array<int, Invoice>
     */
    public function getCurrentCustomerInvoices(array $invoiceIds = []): array
    {
        /** @var InvoiceRepository $repository */
        $repository = $this->getInvoiceRepository();

        return $repository->getCurrentCustomerInvoices($this->aclHelper, $invoiceIds);
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

    /**
     * Creates the invoice payment form update.
     *
     * @param InvoicePayment $invoicePayment
     * @param FormInterface $form
     * @return UpdateInterface
     */
    public function createInvoicePaymentFormUpdate(InvoicePayment $invoicePayment, FormInterface $form): UpdateInterface
    {
        return $this->updateFactory->createUpdate($invoicePayment, $form, null, null);
    }
}
