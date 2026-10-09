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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Data\Demo\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\AttachmentBundle\Manager\FileManager;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\MigrationBundle\Fixture\VersionedFixtureInterface;
use Oro\Component\DependencyInjection\ContainerAwareInterface;
use Oro\Component\DependencyInjection\ContainerAwareTrait;
use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\Configuration;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Fills the Invoice Management settings with clearly fictional company and
 * bank details and the demo company logo, so the demo PDFs show a complete seller block.
 *
 * Demo data only: loaded by "oro:migration:data:load --fixtures-type=demo"
 * or "oro:install --sample-data=y", never by a normal installation. A setting
 * that already has a value is left unchanged.
 */
class LoadInvoiceDemoConfiguration implements FixtureInterface, VersionedFixtureInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;

    /** Bundle image uploaded as the demo company logo. */
    public const LOGO_PATH = '@SoftSolutions4UInvoiceBundle/Resources/public/images/softsolutions4u-logo.png';

    /** Fictional demo values, keyed by setting name. */
    private const DEMO_VALUES = [
        Configuration::COMPANY_NAME => 'Example Seller Inc.',
        Configuration::COMPANY_VAT_NUMBER => 'US123456789',

        Configuration::BANK_US_ACCOUNT_HOLDER => 'Example Seller Inc.',
        Configuration::BANK_US_BANK_NAME => 'Example Bank, N.A.',
        Configuration::BANK_US_ROUTING_NUMBER => '123456789',
        Configuration::BANK_US_ACCOUNT_NUMBER => '000123456789',
        Configuration::BANK_US_SWIFT => 'EXAMUS33',

        Configuration::BANK_DE_ACCOUNT_HOLDER => 'Example Seller GmbH',
        Configuration::BANK_DE_BANK_NAME => 'Example Bank AG',
        Configuration::BANK_DE_IBAN => 'DE00123456780000000000',
        Configuration::BANK_DE_BIC => 'EXAMDEFF',
    ];

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
     * Writes the demo values into the global Invoice Management configuration.
     *
     * @param ObjectManager $manager
     */
    public function load(ObjectManager $manager): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->container->get('oro_config.global');

        $changed = false;
        foreach (self::DEMO_VALUES as $name => $value) {
            $key = Configuration::getConfigKeyByName($name);
            $current = $configManager->get($key);

            if (null !== $current && '' !== trim((string) $current)) {
                continue;
            }

            $configManager->set($key, $value);
            $changed = true;
        }

        if ($this->loadLogo($manager, $configManager)) {
            $changed = true;
        }

        if ($changed) {
            $configManager->flush();
        }
    }

    /**
     * Uploads the demo logo as an Oro attachment file and stores its id in the Company Logo setting.
     *
     * The setting is a ConfigFileType field, so it holds the id of an Oro File entity, not a path.
     *
     * @param ObjectManager $manager
     * @param ConfigManager $configManager
     * @return bool Whether the setting was changed.
     */
    private function loadLogo(ObjectManager $manager, ConfigManager $configManager): bool
    {
        $key = Configuration::getConfigKeyByName(Configuration::COMPANY_LOGO);
        if ($configManager->get($key)) {
            return false;
        }

        /** @var KernelInterface $kernel */
        $kernel = $this->container->get('kernel');
        $path = $kernel->locateResource(self::LOGO_PATH);

        /** @var FileManager $fileManager */
        $fileManager = $this->container->get('oro_attachment.file_manager');
        $logo = $fileManager->createFileEntity($path);
        if (null === $logo) {
            return false;
        }

        // Persisting the File entity makes Oro's attachment listener copy the image into file storage.
        $manager->persist($logo);
        $manager->flush();

        $configManager->set($key, $logo->getId());

        return true;
    }
}
