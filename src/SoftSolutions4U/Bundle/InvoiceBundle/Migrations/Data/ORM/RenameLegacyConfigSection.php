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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Data\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\MigrationBundle\Fixture\VersionedFixtureInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\SoftSolutions4UInvoiceExtension;

/**
 * Moves system configuration values saved under the old "commerce_invoice" section to the current section.
 */
class RenameLegacyConfigSection implements FixtureInterface, VersionedFixtureInterface
{
    private const LEGACY_SECTION = 'commerce_invoice';

    /**
     * Returns the fixture version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return '1.0';
    }

    /**
     * Renames the configuration section, skipping keys that already exist under the new section.
     *
     * @param ObjectManager $manager
     * @return void
     */
    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            return;
        }

        $manager->getConnection()->executeStatement(
            'UPDATE oro_config_value SET section = :newSection WHERE section = :legacySection'
            . ' AND NOT EXISTS (SELECT 1 FROM (SELECT config_id, name FROM oro_config_value'
            . ' WHERE section = :newSection) n WHERE n.config_id = oro_config_value.config_id'
            . ' AND n.name = oro_config_value.name)',
            [
                'newSection' => SoftSolutions4UInvoiceExtension::ALIAS,
                'legacySection' => self::LEGACY_SECTION,
            ]
        );
    }
}
