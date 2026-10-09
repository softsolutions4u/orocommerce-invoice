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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_1;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;
use Oro\Bundle\MigrationBundle\Migration\SqlMigrationQuery;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_0\CreateInvoiceTables;

/**
 * Adds the "pending_confirmation" flag and the payment transaction foreign key to invoice payments.
 */
class AddInvoicePaymentConfirmation implements Migration
{
    /**
     * Applies the version 1.1 schema changes.
     *
     * @param Schema $schema
     * @param QueryBag $queries
     * @return void
     */
    public function up(Schema $schema, QueryBag $queries): void
    {
        $queries->addPreQuery(new SqlMigrationQuery(sprintf(
            'UPDATE %1$s SET payment_transaction_id = NULL WHERE payment_transaction_id IS NOT NULL'
            . ' AND payment_transaction_id NOT IN (SELECT id FROM oro_payment_transaction)',
            CreateInvoiceTables::INVOICE_PAYMENT_TABLE
        )));

        self::updateSchema($schema);
    }

    /**
     * Adds the confirmation flag column and the payment transaction foreign key.
     *
     * @param Schema $schema
     * @return void
     */
    public static function updateSchema(Schema $schema): void
    {
        $table = $schema->getTable(CreateInvoiceTables::INVOICE_PAYMENT_TABLE);

        if (!$table->hasColumn('pending_confirmation')) {
            $table->addColumn('pending_confirmation', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        }

        $table->addForeignKeyConstraint(
            'oro_payment_transaction',
            ['payment_transaction_id'],
            ['id'],
            ['onDelete' => 'SET NULL']
        );
    }
}
