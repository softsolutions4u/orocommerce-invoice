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
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\MigrationBundle\Fixture\VersionedFixtureInterface;
use Oro\Bundle\TranslationBundle\Entity\Language;
use Oro\Bundle\TranslationBundle\Entity\Translation;
use Oro\Component\DependencyInjection\ContainerAwareInterface;
use Oro\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports this bundle's translation files into Oro's database catalogue.
 *
 * Oro serves translations from the database, and its file loader does not pick
 * up this bundle's non-English catalogues in every install - oro:translation:load
 * reports zero files for the locale. Importing them here makes the values
 * available regardless, and keeps a redeploy from silently losing them.
 *
 * Every locale except English is imported: dropping a messages.fr_FR.yml into
 * Resources/translations is enough, no change needed here. English is skipped
 * because Oro already loads it as the fallback catalogue.
 */
class LoadInvoiceTranslations implements FixtureInterface, VersionedFixtureInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;

    private const SOURCE_LOCALE = 'en';

    /**
     * Returns the fixture version.
     *
     * Bump this when a translation file changes, so Oro re-runs the import.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return '1.2';
    }

    /**
     * Imports every non-English catalogue shipped with this bundle.
     *
     * @param ObjectManager $manager
     */
    public function load(ObjectManager $manager): void
    {
        $directory = \dirname(__DIR__, 3) . '/Resources/translations';

        if (!is_dir($directory)) {
            return;
        }

        $translationManager = $this->container->get('oro_translation.manager.translation');
        $imported = 0;

        foreach (glob($directory . '/*.yml') ?: [] as $file) {
            [$domain, $locale] = $this->parseFilename(basename($file));

            if (null === $locale || self::SOURCE_LOCALE === $locale) {
                continue;
            }

            if (!$this->isLanguageInstalled($manager, $locale)) {
                continue;
            }

            foreach ($this->flatten(Yaml::parseFile($file)) as $key => $value) {
                $translationManager->saveTranslation(
                    $key,
                    $value,
                    $locale,
                    $domain,
                    Translation::SCOPE_SYSTEM
                );
                $imported++;
            }

            $translationManager->invalidateCache($locale);
        }

        if ($imported > 0) {
            $translationManager->flush();
        }
    }

    /**
     * Splits a catalogue filename into its domain and locale.
     *
     * @param string $filename
     * @return array{0:?string, 1:?string}
     */
    private function parseFilename(string $filename): array
    {
        if (!preg_match('/^(?<domain>[\w-]+)\.(?<locale>[A-Za-z_]+)\.yml$/', $filename, $matches)) {
            return [null, null];
        }

        return [$matches['domain'], $matches['locale']];
    }

    /**
     * Returns whether the locale is registered and enabled in Oro.
     *
     * @param ObjectManager $manager
     * @param string $locale
     * @return bool
     */
    private function isLanguageInstalled(ObjectManager $manager, string $locale): bool
    {
        $language = $manager->getRepository(Language::class)->findOneBy(['code' => $locale]);

        return null !== $language && $language->isEnabled();
    }

    /**
     * Flattens a nested catalogue into dot-separated translation keys.
     *
     * @param array<string, mixed> $node
     * @param string $prefix
     * @return array<string, string>
     *
     */
    private function flatten(array $node, string $prefix = ''): array
    {
        $flat = [];

        foreach ($node as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (\is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            if (null !== $value && '' !== $value) {
                $flat[$path] = (string) $value;
            }
        }

        return $flat;
    }
}
