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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;

/**
 * Supplies the invoice figures shown beside the storefront payment form.
 *
 * The form pays a single invoice, so the summary is taken from that invoice
 * rather than from the payment, which has no totals of its own until submitted.
 */
class InvoicePaymentSummaryProvider
{
    /**
     * Returns the invoice figures backing the payment summary.
     *
     * @param InvoicePayment $invoicePayment
     * @return array<string, float|string|null>
     */
    public function getData(InvoicePayment $invoicePayment): array
    {
        $invoice = $this->resolveInvoice($invoicePayment);

        if (null === $invoice) {
            return [
                'currency' => $invoicePayment->getCurrency(),
                'grandTotal' => 0.0,
                'amountPaid' => 0.0,
                'balance' => 0.0,
            ];
        }

        return [
            'currency' => $invoice->getCurrency(),
            'grandTotal' => round((float) $invoice->getGrandTotal(), 2),
            'amountPaid' => round((float) $invoice->getAmountPaid(), 2),
            'balance' => round((float) $invoice->getBalance(), 2),
        ];
    }

    /**
     * Returns the invoice this payment applies to.
     *
     * @param InvoicePayment $invoicePayment
     * @return Invoice|null
     */
    private function resolveInvoice(InvoicePayment $invoicePayment): ?Invoice
    {
        $lineItem = $invoicePayment->getLineItems()->first();

        if (!$lineItem) {
            return null;
        }

        $invoice = $lineItem->getInvoice();

        return $invoice instanceof Invoice ? $invoice : null;
    }
}
