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

use Oro\Bundle\EntityExtendBundle\Test\EntityExtendTestInitializer;

/**
 * Initialises Oro's extended-entity field processor for unit tests.
 *
 * Oro entities that use ExtendEntityTrait route their magic accessors through
 * static state on ExtendedEntityFieldsProcessor, which the container normally
 * wires up. Without a container that state is uninitialised, so touching a
 * magic accessor fails with "must not be accessed before initialization".
 *
 * Oro ships EntityExtendTestInitializer for exactly this purpose (its own
 * WebTestCase calls it before any kernel is booted), so this delegates to it.
 *
 * The previous implementation reflected over every uninitialised static on the
 * processor and stuffed it with a PHPUnit mock or a scalar placeholder. That was
 * fragile: it depended on private internals, could not satisfy final classes or
 * non-nullable `object` types, and left mocks owned by one test alive in global
 * state for every later test. It made every test that used it error.
 */
trait ExtendEntityInitializerTrait
{
    /** @var bool $extendedEntityFieldsInitialized */
    private static bool $extendedEntityFieldsInitialized = false;

    /**
     * Initialises the processor once per test class; safe to call repeatedly.
     */
    protected function initializeExtendedEntityFields(): void
    {
        if (self::$extendedEntityFieldsInitialized) {
            return;
        }

        if (class_exists(EntityExtendTestInitializer::class)) {
            EntityExtendTestInitializer::initialize();
        }

        self::$extendedEntityFieldsInitialized = true;
    }
}
