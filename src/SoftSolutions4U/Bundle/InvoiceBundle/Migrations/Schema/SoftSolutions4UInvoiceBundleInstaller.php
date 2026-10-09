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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Installation;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_0\CreateInvoiceTables;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_1\AddInvoicePaymentConfirmation;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_2\AddInvoiceSequence;

/**
 * Installs the complete, current schema of the invoice bundle on a fresh installation.
 *
 * When a new migration version is added, apply the same change here and bump getMigrationVersion().
 */
class SoftSolutions4UInvoiceBundleInstaller implements Installation
{
    public const INVOICE_TABLE_NAME = CreateInvoiceTables::INVOICE_TABLE;
    public const INVOICE_LINE_ITEM_TABLE_NAME = CreateInvoiceTables::INVOICE_LINE_ITEM_TABLE;

    /**
     * Returns the schema version this installer produces.
     *
     * @return string
     */
    public function getMigrationVersion(): string
    {
        return 'v1_2';
    }

    /**
     * Creates every table, index and foreign key owned by this bundle.
     *
     * @param Schema $schema
     * @param QueryBag $queries
     * @return void
     */
    public function up(Schema $schema, QueryBag $queries): void
    {
        CreateInvoiceTables::createSchema($schema);
        AddInvoicePaymentConfirmation::updateSchema($schema);
        AddInvoiceSequence::updateSchema($schema);
    }
}
