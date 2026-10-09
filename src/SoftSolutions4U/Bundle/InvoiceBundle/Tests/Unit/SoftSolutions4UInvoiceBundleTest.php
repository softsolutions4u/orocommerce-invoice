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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\SoftSolutions4UInvoiceExtension;
use SoftSolutions4U\Bundle\InvoiceBundle\SoftSolutions4UInvoiceBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class SoftSolutions4UInvoiceBundleTest extends TestCase
{
    public function testBundleCanBeInstantiated(): void
    {
        $bundle = new SoftSolutions4UInvoiceBundle();
        self::assertInstanceOf(SoftSolutions4UInvoiceBundle::class, $bundle);
    }

    public function testGetContainerExtensionReturnsTheBundleExtension(): void
    {
        $bundle = new SoftSolutions4UInvoiceBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(SoftSolutions4UInvoiceExtension::class, $extension);
        self::assertSame('softsolutions4u_invoice', $extension->getAlias());
    }

    public function testBuildRegistersCompilerPassesWithoutError(): void
    {
        $bundle = new SoftSolutions4UInvoiceBundle();
        $container = new ContainerBuilder();
        $bundle->build($container);
        self::assertTrue(true);
    }
}
