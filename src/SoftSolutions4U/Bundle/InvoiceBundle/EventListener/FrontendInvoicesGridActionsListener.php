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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use Oro\Bundle\DataGridBundle\Datasource\ResultRecordInterface;

/**
 * Configures the row actions available on the storefront invoices grid.
 */
class FrontendInvoicesGridActionsListener
{
    /**
     * Returns the action configuration.
     *
     * @param ResultRecordInterface $record
     * @return array<string, bool>
     */
    public function getActionConfiguration(ResultRecordInterface $record): array
    {
        $status = (string) $record->getValue('invoiceStatusId');
        $balance = (float) $record->getValue('balance');

        return [
            'pay' => $balance > 0 && in_array($status, Invoice::UNPAID_STATUSES, true),
        ];
    }
}
