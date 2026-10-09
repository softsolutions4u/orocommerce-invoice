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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Storefront grid counter label test.
 */
class StorefrontGridCounterLabelTest extends TestCase
{
    private const INVOICE_COUNTER_KEY = 'softsolutions4u.invoice.frontend.grid.pagination.totalRecordsShortPlural';

    private const INVOICE_HISTORY_GRIDS = [
        'frontend-softsolutions4u-invoices-grid',
        'frontend-softsolutions4u-invoices-outstanding-grid',
        'frontend-softsolutions4u-invoices-paid-grid',
        'frontend-softsolutions4u-invoices-cancelled-grid',
    ];

    /**
     * Tests that no translation file overrides Oro's shared datagrid labels.
     */
    public function testOroGridLabelsAreNotOverridden(): void
    {
        foreach ($this->translationFiles() as $file) {
            $messages = $this->flatten((array) Yaml::parseFile($file));

            foreach (array_keys($messages) as $key) {
                self::assertStringStartsNotWith(
                    'oro.datagrid.',
                    $key,
                    sprintf(
                        '%s overrides Oro\'s shared grid label "%s", which affects every grid',
                        basename($file),
                        $key
                    )
                );
            }
        }
    }

    /**
     * Tests that each invoice history grid counts "invoices".
     */
    public function testInvoiceHistoryGridsUseTheInvoiceCounterLabel(): void
    {
        $grids = $this->storefrontGrids();

        foreach (self::INVOICE_HISTORY_GRIDS as $gridName) {
            self::assertArrayHasKey($gridName, $grids);
            self::assertSame(
                self::INVOICE_COUNTER_KEY,
                $grids[$gridName]['options']['toolbarOptions']['itemsCounter']['transTemplate'] ?? null,
                sprintf('%s must use the invoice counter label', $gridName)
            );
        }
    }

    /**
     * Tests that grids listing products do not count "invoices".
     */
    public function testProductGridsDoNotUseTheInvoiceCounterLabel(): void
    {
        foreach ($this->storefrontGrids() as $gridName => $grid) {
            if (in_array($gridName, self::INVOICE_HISTORY_GRIDS, true)) {
                continue;
            }

            self::assertNotSame(
                self::INVOICE_COUNTER_KEY,
                $grid['options']['toolbarOptions']['itemsCounter']['transTemplate'] ?? null,
                sprintf('%s does not list invoices and must not count them as invoices', $gridName)
            );
        }
    }

    /**
     * Tests that the invoice counter label is translated, with singular and plural forms, in every locale.
     */
    public function testInvoiceCounterLabelIsTranslated(): void
    {
        $files = $this->translationFiles();
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $messages = $this->flatten((array) Yaml::parseFile($file));

            self::assertArrayHasKey(self::INVOICE_COUNTER_KEY, $messages, basename($file));
            self::assertStringContainsString('{1}', (string) $messages[self::INVOICE_COUNTER_KEY], basename($file));
            self::assertStringContainsString(']1,Inf[', (string) $messages[self::INVOICE_COUNTER_KEY], basename($file));
        }
    }

    /**
     * Returns the storefront grids.
     *
     * @return array<string, array<string, mixed>>
     */
    private function storefrontGrids(): array
    {
        $config = Yaml::parseFile($this->bundleDir() . '/Resources/views/layouts/default/config/datagrids.yml');

        return (array) ($config['datagrids'] ?? []);
    }

    /**
     * Returns the translation files.
     *
     * @return array<int, string>
     */
    private function translationFiles(): array
    {
        return glob($this->bundleDir() . '/Resources/translations/jsmessages.*.yml') ?: [];
    }

    /**
     * Returns the bundle dir.
     *
     * @return string
     */
    private function bundleDir(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Flattens the given data.
     *
     * @param array<mixed> $messages
     * @param string $prefix
     * @return array<string, mixed>
     */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];

        foreach ($messages as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
