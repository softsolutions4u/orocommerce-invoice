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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_0;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Creates the invoice, invoice line item, invoice payment and invoice payment line item tables.
 *
 * The static methods are shared with SoftSolutions4UInvoiceBundleInstaller so fresh installs and upgrades match.
 */
class CreateInvoiceTables implements Migration
{
    public const INVOICE_TABLE = 'softsolutions4u_invoice';
    public const INVOICE_LINE_ITEM_TABLE = 'softsolutions4u_invoice_line_item';
    public const INVOICE_PAYMENT_TABLE = 'softsolutions4u_invoice_payment';
    public const INVOICE_PAYMENT_LINE_ITEM_TABLE = 'softsolutions4u_invoice_payment_line_item';

    /** Column options shared by every money column. */
    private const MONEY = ['precision' => 19, 'scale' => 4, 'comment' => '(DC2Type:money)'];

    /**
     * Applies the version 1.0 schema.
     *
     * @param Schema $schema
     * @param QueryBag $queries
     * @return void
     */
    public function up(Schema $schema, QueryBag $queries): void
    {
        self::createSchema($schema);
    }

    /**
     * Creates all tables and foreign keys of version 1.0.
     *
     * @param Schema $schema
     * @return void
     */
    public static function createSchema(Schema $schema): void
    {
        self::createInvoiceTable($schema);
        self::createInvoiceLineItemTable($schema);
        self::createInvoicePaymentTable($schema);
        self::createInvoicePaymentLineItemTable($schema);

        self::addInvoiceForeignKeys($schema);
        self::addInvoiceLineItemForeignKeys($schema);
        self::addInvoicePaymentForeignKeys($schema);
        self::addInvoicePaymentLineItemForeignKeys($schema);
    }

    /**
     * Creates the "softsolutions4u_invoice" table.
     *
     * @param Schema $schema
     * @return void
     */
    public static function createInvoiceTable(Schema $schema): void
    {
        $table = $schema->createTable(self::INVOICE_TABLE);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('invoice_no', 'string', ['length' => 50]);
        $table->addColumn('customer_id', 'integer', ['notnull' => false]);
        $table->addColumn('customer_user_id', 'integer', ['notnull' => false]);
        $table->addColumn('order_id', 'integer', ['notnull' => false]);
        $table->addColumn('organization_id', 'integer', ['notnull' => false]);
        $table->addColumn('issue_date', 'date', ['notnull' => false]);
        $table->addColumn('due_date', 'date', ['notnull' => false]);
        $table->addColumn('posted_at', 'datetime', ['notnull' => false]);
        $table->addColumn('paid_notification_sent_at', 'datetime', ['notnull' => false]);
        $table->addColumn('cancelled_notification_sent_at', 'datetime', ['notnull' => false]);
        $table->addColumn('amount', 'money', self::MONEY);
        $table->addColumn('amount_paid', 'money', self::MONEY);
        $table->addColumn('subtotal', 'money', self::MONEY + ['notnull' => true, 'default' => 0]);
        $table->addColumn('discount_amount', 'money', self::MONEY + ['notnull' => true, 'default' => 0]);
        $table->addColumn('tax_amount', 'money', self::MONEY + ['notnull' => true, 'default' => 0]);
        $table->addColumn('grand_total', 'money', self::MONEY + ['notnull' => true, 'default' => 0]);
        $table->addColumn('shipping_amount', 'money', self::MONEY + ['notnull' => false, 'default' => 0]);
        $table->addColumn('currency', 'string', ['length' => 255]);
        $table->addColumn('status', 'string', ['length' => 32, 'notnull' => true, 'default' => 'draft']);
        $table->addColumn('payment_method', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('payment_status', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('internal_status', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('po_number', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('memo', 'text', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime', []);
        $table->addColumn('updated_at', 'datetime', []);
        $table->addColumn('serialized_data', 'json', ['notnull' => false]);

        self::addAddressColumns($table, 'billing');
        self::addAddressColumns($table, 'shipping');

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['invoice_no'], 'softsolutions4u_invoice_no_uidx');
        $table->addIndex(['customer_id'], 'softsolutions4u_invoice_customer_idx');
        $table->addIndex(['issue_date'], 'softsolutions4u_invoice_issue_date_idx');
        $table->addIndex(['due_date'], 'softsolutions4u_invoice_due_date_idx');
        $table->addIndex(['order_id'], 'softsolutions4u_invoice_order_idx');
        $table->addIndex(['organization_id'], 'softsolutions4u_invoice_organization_idx');
    }

    /**
     * Creates the "softsolutions4u_invoice_line_item" table.
     *
     * @param Schema $schema
     * @return void
     */
    public static function createInvoiceLineItemTable(Schema $schema): void
    {
        $table = $schema->createTable(self::INVOICE_LINE_ITEM_TABLE);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('invoice_id', 'integer', []);
        $table->addColumn('product_id', 'integer', ['notnull' => false]);
        $table->addColumn('product_sku', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('product_name', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('quantity', 'float', ['notnull' => false]);
        $table->addColumn('product_unit_id', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('product_unit_code', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('value', 'money', self::MONEY + ['notnull' => false]);
        $table->addColumn('subtotal', 'money', self::MONEY + ['notnull' => true, 'default' => 0]);
        $table->addColumn('discount_amount', 'money', self::MONEY + ['notnull' => true, 'default' => 0]);
        $table->addColumn('tax_amount', 'money', self::MONEY + ['notnull' => true, 'default' => 0]);
        $table->addColumn('total', 'money', self::MONEY + ['notnull' => true, 'default' => 0]);
        $table->addColumn('summary', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('currency', 'string', ['length' => 255]);
        $table->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
        $table->addColumn('serialized_data', 'json', ['notnull' => false]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['invoice_id'], 'commerce_inv_line_item_invoice_idx');
        $table->addIndex(['product_id'], 'commerce_inv_line_item_product_idx');
        $table->addIndex(['product_unit_id'], 'commerce_inv_line_item_product_unit_idx');
    }

    /**
     * Creates the "softsolutions4u_invoice_payment" table.
     *
     * @param Schema $schema
     * @return void
     */
    public static function createInvoicePaymentTable(Schema $schema): void
    {
        $table = $schema->createTable(self::INVOICE_PAYMENT_TABLE);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('invoice_id', 'integer', ['notnull' => false]);
        $table->addColumn('organization_id', 'integer', ['notnull' => false]);
        $table->addColumn('customer_id', 'integer', ['notnull' => false]);
        $table->addColumn('customer_user_id', 'integer', ['notnull' => false]);
        $table->addColumn('active', 'boolean', ['default' => false]);
        $table->addColumn('payment_method', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('amount', 'money', self::MONEY);
        $table->addColumn('total', 'money', self::MONEY);
        $table->addColumn('currency', 'string', ['length' => 255]);
        $table->addColumn('payment_transaction_id', 'integer', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime', ['notnull' => false]);
        $table->addColumn('updated_at', 'datetime', ['notnull' => false]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['customer_id'], 'commerce_inv_payment_customer_idx');
        $table->addIndex(['customer_user_id'], 'commerce_inv_payment_customer_user_idx');
        $table->addIndex(['organization_id'], 'commerce_inv_payment_organization_idx');
        $table->addUniqueIndex(['payment_transaction_id'], 'commerce_inv_payment_transaction_uidx');
    }

    /**
     * Creates the "softsolutions4u_invoice_payment_line_item" table.
     *
     * @param Schema $schema
     * @return void
     */
    public static function createInvoicePaymentLineItemTable(Schema $schema): void
    {
        $table = $schema->createTable(self::INVOICE_PAYMENT_LINE_ITEM_TABLE);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('invoice_payment_id', 'integer', []);
        $table->addColumn('invoice_id', 'integer', []);
        $table->addColumn('amount', 'money', self::MONEY);
        $table->addColumn('currency', 'string', ['length' => 255]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['invoice_payment_id', 'invoice_id'], 'commerce_inv_payment_line_item_uidx');
        $table->addIndex(['invoice_payment_id'], 'commerce_inv_payment_line_item_payment_idx');
        $table->addIndex(['invoice_id'], 'commerce_inv_payment_line_item_invoice_idx');
    }

    /**
     * Adds the foreign keys of the "softsolutions4u_invoice" table.
     *
     * @param Schema $schema
     * @return void
     */
    public static function addInvoiceForeignKeys(Schema $schema): void
    {
        $table = $schema->getTable(self::INVOICE_TABLE);
        $table->addForeignKeyConstraint('oro_customer', ['customer_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addForeignKeyConstraint('oro_customer_user', ['customer_user_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addForeignKeyConstraint('oro_order', ['order_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addForeignKeyConstraint('oro_organization', ['organization_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    /**
     * Adds the foreign keys of the "softsolutions4u_invoice_line_item" table.
     *
     * @param Schema $schema
     * @return void
     */
    public static function addInvoiceLineItemForeignKeys(Schema $schema): void
    {
        $table = $schema->getTable(self::INVOICE_LINE_ITEM_TABLE);
        $table->addForeignKeyConstraint(self::INVOICE_TABLE, ['invoice_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('oro_product', ['product_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addForeignKeyConstraint(
            'oro_product_unit',
            ['product_unit_id'],
            ['code'],
            ['onDelete' => 'SET NULL']
        );
    }

    /**
     * Adds the foreign keys of the "softsolutions4u_invoice_payment" table.
     *
     * @param Schema $schema
     * @return void
     */
    public static function addInvoicePaymentForeignKeys(Schema $schema): void
    {
        $table = $schema->getTable(self::INVOICE_PAYMENT_TABLE);
        $table->addForeignKeyConstraint(self::INVOICE_TABLE, ['invoice_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addForeignKeyConstraint('oro_customer', ['customer_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addForeignKeyConstraint('oro_customer_user', ['customer_user_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addForeignKeyConstraint('oro_organization', ['organization_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    /**
     * Adds the foreign keys of the "softsolutions4u_invoice_payment_line_item" table.
     *
     * @param Schema $schema
     * @return void
     */
    public static function addInvoicePaymentLineItemForeignKeys(Schema $schema): void
    {
        $table = $schema->getTable(self::INVOICE_PAYMENT_LINE_ITEM_TABLE);
        $table->addForeignKeyConstraint(
            self::INVOICE_PAYMENT_TABLE,
            ['invoice_payment_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
        $table->addForeignKeyConstraint(self::INVOICE_TABLE, ['invoice_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    /**
     * Adds the flat address columns for one address type.
     *
     * @param Table $table
     * @param string $type Either "billing" or "shipping".
     * @return void
     */
    private static function addAddressColumns(Table $table, string $type): void
    {
        $prefix = $type . '_address_';

        $table->addColumn($prefix . 'first_name', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn($prefix . 'last_name', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn($prefix . 'organization', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn($prefix . 'phone', 'string', ['length' => 100, 'notnull' => false]);
        $table->addColumn($prefix . 'street1', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn($prefix . 'street2', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn($prefix . 'city', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn($prefix . 'state', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn($prefix . 'postal_code', 'string', ['length' => 50, 'notnull' => false]);
        $table->addColumn($prefix . 'country', 'string', ['length' => 2, 'notnull' => false]);
    }
}
