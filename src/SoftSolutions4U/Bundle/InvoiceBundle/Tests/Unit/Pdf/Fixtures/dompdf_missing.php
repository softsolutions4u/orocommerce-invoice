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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Pdf;

/**
 * Switch for the test-only class_exists() override below.
 *
 * Off by default, so the override behaves exactly like the built-in function
 * unless a test explicitly turns it on.
 */
final class DompdfMissingSwitch
{
    /** @var bool $enabled */
    public static bool $enabled = false;
}

/**
 * Test-only override of class_exists() for the Pdf namespace.
 *
 * PHP resolves the unqualified class_exists() call in InvoicePdfGenerator to
 * this function first, so Dompdf can be reported as missing even though the
 * package is installed. It only does so while DompdfMissingSwitch is enabled.
 *
 * @param string $class
 * @param bool $autoload
 * @return bool
 */
function class_exists(string $class, bool $autoload = true): bool
{
    if (DompdfMissingSwitch::$enabled && $class === \Dompdf\Dompdf::class) {
        return false;
    }

    return \class_exists($class, $autoload);
}
