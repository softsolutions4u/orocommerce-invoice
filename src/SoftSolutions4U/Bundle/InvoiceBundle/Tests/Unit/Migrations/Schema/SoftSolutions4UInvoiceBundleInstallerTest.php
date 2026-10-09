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

use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\SoftSolutions4UInvoiceBundleInstaller;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Schema\v1_0\CreateInvoiceTables;

/**
 * Unit tests for the installer constants and version format.
 */
class SoftSolutions4UInvoiceBundleInstallerTest extends TestCase
{
    /**
     * Tests the installer version and that its table names match the schema.
     */
    public function testInstaller(): void
    {
        $installer = new SoftSolutions4UInvoiceBundleInstaller();

        self::assertMatchesRegularExpression('/^v\d+_\d+$/', $installer->getMigrationVersion());
        self::assertSame(CreateInvoiceTables::INVOICE_TABLE, SoftSolutions4UInvoiceBundleInstaller::INVOICE_TABLE_NAME);
        self::assertSame(
            CreateInvoiceTables::INVOICE_LINE_ITEM_TABLE,
            SoftSolutions4UInvoiceBundleInstaller::INVOICE_LINE_ITEM_TABLE_NAME
        );
    }
}
