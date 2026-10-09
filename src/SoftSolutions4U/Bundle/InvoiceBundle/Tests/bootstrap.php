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

/*
 * Minimal bootstrap for the unit tests.
 *
 * Loads only Composer's autoloader - no Symfony kernel and no Oro container;
 * the unit tests work with mocks. The autoloader is looked up by walking up
 * the directory tree, so the same bootstrap works when the bundle is:
 *  - checked out on its own (vendor/ at the repository root),
 *  - installed into an Oro application via Composer (vendor/softsolutions4u/...),
 *  - or copied into an application's src/ directory.
 */

$directory = __DIR__;
$composerAutoloader = null;

while (true) {
    $candidate = $directory . '/vendor/autoload.php';
    if (is_file($candidate)) {
        $composerAutoloader = $candidate;
        break;
    }

    // Installed as a dependency: <app>/vendor/softsolutions4u/orocommerce-invoice/...
    if ('vendor' === basename($directory) && is_file($directory . '/autoload.php')) {
        $composerAutoloader = $directory . '/autoload.php';
        break;
    }

    $parent = dirname($directory);
    if ($parent === $directory) {
        break;
    }
    $directory = $parent;
}

if (null === $composerAutoloader) {
    fwrite(STDERR, "Cannot find Composer's vendor/autoload.php above " . __DIR__ . PHP_EOL);
    exit(1);
}

require_once $composerAutoloader;
