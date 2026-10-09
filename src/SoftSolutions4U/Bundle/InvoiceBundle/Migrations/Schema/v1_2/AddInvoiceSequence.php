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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_2;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds the per-month counter table that hands out invoice numbers atomically.
 */
class AddInvoiceSequence implements Migration
{
    public const SEQUENCE_TABLE = 'softsolutions4u_invoice_sequence';

    /**
     * Applies the version 1.2 schema changes.
     *
     * @param Schema $schema
     * @param QueryBag $queries
     * @return void
     */
    public function up(Schema $schema, QueryBag $queries): void
    {
        self::updateSchema($schema);
    }

    /**
     * Creates the invoice sequence table when it does not exist yet.
     *
     * @param Schema $schema
     * @return void
     */
    public static function updateSchema(Schema $schema): void
    {
        if ($schema->hasTable(self::SEQUENCE_TABLE)) {
            return;
        }

        $table = $schema->createTable(self::SEQUENCE_TABLE);
        $table->addColumn('period', Types::STRING, ['length' => 7]);
        $table->addColumn('last_number', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->setPrimaryKey(['period']);
    }
}
