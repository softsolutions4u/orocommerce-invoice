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

namespace SoftSolutions4U\Bundle\InvoiceBundle\EventListener;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\InvoicePaymentFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\FrontendInvoiceProvider;
use Oro\Bundle\DataGridBundle\Datagrid\DatagridInterface;
use Oro\Bundle\DataGridBundle\Datasource\ArrayDatasource\ArrayDatasource;
use Oro\Bundle\DataGridBundle\Event\BuildAfter;
use Oro\Bundle\DataGridBundle\Exception\UnexpectedTypeException;

/**
 * Builds the invoice line items shown on the storefront payment grid.
 */
class FrontendInvoicePaymentLineItemsGridListener
{
    /** @var InvoicePaymentFactory $invoicePaymentFactory */
    protected InvoicePaymentFactory $invoicePaymentFactory;

    /** @var FrontendInvoiceProvider $frontendInvoiceProvider */
    protected FrontendInvoiceProvider $frontendInvoiceProvider;

    /**
     * Creates a new FrontendInvoicePaymentLineItemsGridListener instance.
     *
     * @param InvoicePaymentFactory $invoicePaymentFactory
     * @param FrontendInvoiceProvider $frontendInvoiceProvider
     */
    public function __construct(
        InvoicePaymentFactory $invoicePaymentFactory,
        FrontendInvoiceProvider $frontendInvoiceProvider,
    ) {
        $this->invoicePaymentFactory = $invoicePaymentFactory;
        $this->frontendInvoiceProvider = $frontendInvoiceProvider;
    }

    /**
     * Handles the build after event.
     *
     * @param BuildAfter $event
     * @throws \LogicException
     * @throws \Exception
     */
    public function onBuildAfter(BuildAfter $event): void
    {
        $datagrid = $event->getDatagrid();
        $datasource = $datagrid->getDatasource();

        if (!$datasource instanceof ArrayDatasource) {
            throw new UnexpectedTypeException($datasource, ArrayDatasource::class);
        }

        $invoicePayment = $this->getParameterOrNull($datagrid, 'invoicePayment');

        if (!$invoicePayment instanceof InvoicePayment) {
            $datasource->setArraySource([]);
            return;
        }

        $datasource->setArraySource($this->createSourceFromLineItems($invoicePayment));
    }

    /**
     * Creates the source from line items.
     *
     * @param InvoicePayment $invoicePayment
     * @return array<array<string,mixed>>
     */
    protected function createSourceFromLineItems(InvoicePayment $invoicePayment): array
    {
        $source = [];

        $unpaidInvoices = $this
            ->frontendInvoiceProvider
            ->getCurrentCustomerUnpaidInvoices();

        /** Build up a lookup table containing Line Items indexed by Invoice ID */
        $lineItemsByInvoice = [];
        foreach ($invoicePayment->getLineItems() as $lineItem) {
            $lineItemsByInvoice[$lineItem->getInvoice()->getId()] = $lineItem;
        }

        foreach ($unpaidInvoices as $invoice) {
            $lineItem = $lineItemsByInvoice[$invoice->getId()] ?? null;
            /** Amount to Pay is either whatever the LineItem had previously, or the Invoice's remaining Balance */
            $paymentAmount = $lineItem ? $lineItem->getAmount() : $invoice->getBalance();
            $row = [
                'isEnabled' => !is_null($lineItem),
                'id' => $invoice->getId(),
                'invoice' => $invoice->getInvoiceNo(),
                'amount' => $invoice->getAmount(),
                'amountPaid' => $invoice->getAmountPaid(),
                'balance' => $invoice->getBalance(),
                'paymentAmount' => $paymentAmount,
                'issueDate' => $invoice->getIssueDate(),
                'dueDate' => $invoice->getDueDate(),
                'invoiceStatusId' => $invoice->getStatus(),
            ];

            $source[] = $row;
        }

        /** Sort by most overdue first */
        // Sorting two arrays should be safe enough here phpcs:ignore.
        usort($source, function ($a, $b) {
            return $a['dueDate'] <=> $b['dueDate'];
        });

        return $source;
    }

    /**
     * Returns the parameter or null.
     *
     * @param DatagridInterface $datagrid
     * @param string $parameterName
     * @return mixed
     */
    protected function getParameterOrNull(DatagridInterface $datagrid, string $parameterName): mixed
    {
        return $datagrid->getParameters()->get($parameterName);
    }

    /**
     * Returns the parameter.
     *
     * @param DatagridInterface $datagrid
     * @param string $parameterName
     * @return mixed
     * @deprecated use getParameterOrNull() to be compatible with different Oro versions
     */
    protected function getParameter(DatagridInterface $datagrid, string $parameterName): mixed
    {
        $value = $this->getParameterOrNull($datagrid, $parameterName);

        if ($value === null) {
            throw new \LogicException(sprintf('Parameter "%s" must be set', $parameterName));
        }

        return $value;
    }
}
