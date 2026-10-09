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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\Type;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;
use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\SoftSolutions4UInvoiceBundleInstaller;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_0\CreateInvoiceTables;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_1\AddInvoicePaymentConfirmation;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_2\AddInvoiceSequence;

/**
 * Unit tests for the schema migrations and the bundle installer.
 */
class CreateInvoiceTablesTest extends TestCase
{
    /**
     * Registers Oro's "money" DBAL type, which is not available without the kernel.
     */
    public static function setUpBeforeClass(): void
    {
        if (!Type::hasType('money')) {
            Type::addType('money', DecimalType::class);
        }
    }

    /**
     * Tests that the table names use the commerce_ prefix.
     */
    public function testTableNames(): void
    {
        self::assertSame('softsolutions4u_invoice', CreateInvoiceTables::INVOICE_TABLE);
        self::assertSame('softsolutions4u_invoice_line_item', CreateInvoiceTables::INVOICE_LINE_ITEM_TABLE);
        self::assertSame('softsolutions4u_invoice_payment', CreateInvoiceTables::INVOICE_PAYMENT_TABLE);
        self::assertSame(
            'softsolutions4u_invoice_payment_line_item',
            CreateInvoiceTables::INVOICE_PAYMENT_LINE_ITEM_TABLE
        );
    }

    /**
     * Tests that the migration creates all four tables.
     */
    public function testCreatesAllTables(): void
    {
        $schema = $this->runMigration();

        foreach ($this->tableNames() as $name) {
            self::assertTrue($schema->hasTable($name), sprintf('Table "%s" was not created', $name));
        }
    }

    /**
     * Tests that the invoice table has every column the entity maps.
     */
    public function testInvoiceTableHasEveryMappedColumn(): void
    {
        $table = $this->runMigration()->getTable(CreateInvoiceTables::INVOICE_TABLE);

        $expected = [
            'id', 'invoice_no', 'customer_id', 'customer_user_id', 'order_id', 'organization_id',
            'issue_date', 'due_date', 'posted_at', 'paid_notification_sent_at', 'cancelled_notification_sent_at',
            'amount', 'amount_paid', 'subtotal', 'discount_amount', 'tax_amount', 'grand_total',
            'shipping_amount', 'currency', 'status', 'payment_method', 'payment_status',
            'internal_status', 'po_number', 'memo', 'created_at', 'updated_at', 'serialized_data',
        ];

        foreach (['billing', 'shipping'] as $type) {
            foreach (
                ['first_name', 'last_name', 'organization', 'phone', 'street1', 'street2',
                'city', 'state', 'postal_code', 'country'] as $field
            ) {
                $expected[] = $type . '_address_' . $field;
            }
        }

        foreach ($expected as $column) {
            self::assertTrue($table->hasColumn($column), sprintf('Missing column "%s"', $column));
        }
    }

    /**
     * Tests that the invoice number is unique.
     */
    public function testInvoiceNumberIsUnique(): void
    {
        $table = $this->runMigration()->getTable(CreateInvoiceTables::INVOICE_TABLE);

        self::assertTrue($table->hasIndex('softsolutions4u_invoice_no_uidx'));
        self::assertTrue($table->getIndex('softsolutions4u_invoice_no_uidx')->isUnique());
    }

    /**
     * Tests that line items are deleted together with their invoice.
     */
    public function testLineItemsCascadeWithTheInvoice(): void
    {
        $table = $this->runMigration()->getTable(CreateInvoiceTables::INVOICE_LINE_ITEM_TABLE);

        $cascades = array_filter(
            $table->getForeignKeys(),
            static fn ($fk): bool => CreateInvoiceTables::INVOICE_TABLE === $fk->getForeignTableName()
                && 'CASCADE' === $fk->getOption('onDelete')
        );

        self::assertCount(1, $cascades);
    }

    /**
     * Tests that every declared index name is prefixed, unique and fits PostgreSQL's limit.
     */
    public function testIndexNamesAreUniqueAndPrefixed(): void
    {
        $schema = $this->runMigration();
        $seen = [];

        foreach ($this->tableNames() as $tableName) {
            foreach ($schema->getTable($tableName)->getIndexes() as $index) {
                $name = strtolower($index->getName());

                // Skip the primary key and the idx_<hash> indexes Doctrine adds behind foreign keys.
                if ($index->isPrimary() || preg_match('/^idx_[0-9a-f]{8,}$/', $name)) {
                    continue;
                }

                self::assertMatchesRegularExpression('/^(softsolutions4u|commerce_inv)_/', $name);
                self::assertLessThanOrEqual(63, strlen($name), sprintf('Index "%s" is too long', $name));
                self::assertNotContains($name, $seen, sprintf('Index "%s" is declared twice', $name));
                $seen[] = $name;
            }
        }

        self::assertNotEmpty($seen);
    }

    /**
     * Tests that the installer is at v1_2 and matches the v1_0, v1_1 and v1_2 migrations applied in order.
     */
    public function testInstallerMatchesTheMigrations(): void
    {
        $installer = new SoftSolutions4UInvoiceBundleInstaller();

        self::assertSame('v1_2', $installer->getMigrationVersion());

        $installed = $this->createSchemaWithOroTables();
        $installer->up($installed, new QueryBag());

        $migrated = $this->runMigration();
        (new AddInvoicePaymentConfirmation())->up($migrated, new QueryBag());
        (new AddInvoiceSequence())->up($migrated, new QueryBag());

        self::assertSame($this->snapshot($migrated), $this->snapshot($installed));
    }

    /**
     * Tests that v1_1 adds the confirmation flag and the payment transaction foreign key.
     */
    public function testV11AddsPaymentConfirmation(): void
    {
        $schema = $this->runMigration();
        $queries = new QueryBag();
        (new AddInvoicePaymentConfirmation())->up($schema, $queries);

        $table = $schema->getTable(CreateInvoiceTables::INVOICE_PAYMENT_TABLE);
        $foreignTables = array_map(static fn ($fk): string => $fk->getForeignTableName(), $table->getForeignKeys());

        self::assertTrue($table->hasColumn('pending_confirmation'));
        self::assertContains('oro_payment_transaction', $foreignTables);
        self::assertCount(1, $queries->getPreQueries());
    }

    /**
     * Returns a schema containing the Oro tables the bundle references, with the v1_0 migration applied.
     *
     * @return Schema
     */
    private function runMigration(): Schema
    {
        $schema = $this->createSchemaWithOroTables();

        (new CreateInvoiceTables())->up($schema, new QueryBag());

        return $schema;
    }

    /**
     * Returns a schema with minimal versions of the Oro tables the bundle points foreign keys at.
     *
     * @return Schema
     */
    private function createSchemaWithOroTables(): Schema
    {
        $schema = new Schema();

        $oroTables = [
            'oro_customer',
            'oro_customer_user',
            'oro_order',
            'oro_organization',
            'oro_product',
            'oro_payment_transaction',
        ];

        foreach ($oroTables as $name) {
            $table = $schema->createTable($name);
            $table->addColumn('id', 'integer', ['autoincrement' => true]);
            $table->setPrimaryKey(['id']);
        }

        $productUnit = $schema->createTable('oro_product_unit');
        $productUnit->addColumn('code', 'string', ['length' => 255]);
        $productUnit->setPrimaryKey(['code']);

        return $schema;
    }

    /**
     * Returns the names of the tables owned by the bundle.
     *
     * @return array<int, string>
     */
    private function tableNames(): array
    {
        return [
            CreateInvoiceTables::INVOICE_TABLE,
            CreateInvoiceTables::INVOICE_LINE_ITEM_TABLE,
            CreateInvoiceTables::INVOICE_PAYMENT_TABLE,
            CreateInvoiceTables::INVOICE_PAYMENT_LINE_ITEM_TABLE,
        ];
    }

    /**
     * Describes the bundle's tables as plain arrays so two schemas can be compared.
     *
     * @param Schema $schema
     * @return array<string, array<string, array<int, string>>>
     */
    private function snapshot(Schema $schema): array
    {
        $snapshot = [];

        foreach ($this->tableNames() as $name) {
            $table = $schema->getTable($name);

            $columns = array_map(
                static fn ($column): string => $column->getName() . ':' . json_encode($column->toArray()['notnull']),
                array_values($table->getColumns())
            );
            $indexes = array_map(
                static fn ($index): string => $index->getName() . ':' . implode(',', $index->getColumns()),
                array_values($table->getIndexes())
            );
            $foreignKeys = array_map(
                static fn ($fk): string => implode(',', $fk->getLocalColumns()) . '->' . $fk->getForeignTableName(),
                array_values($table->getForeignKeys())
            );
            sort($columns);
            sort($indexes);
            sort($foreignKeys);

            $snapshot[$name] = ['columns' => $columns, 'indexes' => $indexes, 'foreignKeys' => $foreignKeys];
        }

        return $snapshot;
    }

    /**
     * Tests that the sequence migration creates the per-month counter table once.
     */
    public function testInvoiceSequenceTableIsCreatedOnce(): void
    {
        $schema = new Schema();

        AddInvoiceSequence::updateSchema($schema);
        AddInvoiceSequence::updateSchema($schema);

        self::assertTrue($schema->hasTable(AddInvoiceSequence::SEQUENCE_TABLE));
        $table = $schema->getTable(AddInvoiceSequence::SEQUENCE_TABLE);
        self::assertTrue($table->hasColumn('period'));
        self::assertTrue($table->hasColumn('last_number'));
        self::assertSame(['period'], $table->getPrimaryKey()->getColumns());
    }
}
