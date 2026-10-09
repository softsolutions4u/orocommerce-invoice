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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Migrations\Data\ORM;

use PHPUnit\Framework\TestCase;

/**
 * Guards the layout of the email template directories.
 *
 * Oro's AbstractEmailFixture walks getEmailsDir() recursively and requires every
 * .twig file under it to declare a {# @name = ... #} header. A headerless file
 * anywhere in that tree aborts oro:migration:data:load for the whole
 * application, so the localized bodies live in a sibling directory instead.
 */
class InvoiceEmailTemplateFilesTest extends TestCase
{
    private const TEMPLATES = [
        'softsolutions4u_invoice_notification',
        'softsolutions4u_invoice_paid_notification',
        'softsolutions4u_invoice_reminder_notification',
        'softsolutions4u_invoice_cancelled_notification',
    ];

    private const LOCALES = ['de_DE'];

    /**
     * Returns the emails dir.
     *
     * @return string
     */
    private static function emailsDir(): string
    {
        return dirname(__DIR__, 5) . '/Migrations/Data/ORM/data/emails/invoice';
    }

    /**
     * Returns the translations dir.
     *
     * @return string
     */
    private static function translationsDir(): string
    {
        return dirname(__DIR__, 5) . '/Migrations/Data/ORM/data/emails/invoice_translations';
    }

    /**
     * Tests that the fixture's own directory resolves, so the paths below mean something.
     */
    public function testTheEmailDirectoriesExist(): void
    {
        self::assertDirectoryExists(self::emailsDir());
        self::assertDirectoryExists(self::translationsDir());
    }

    /**
     * The regression: a localized subdirectory inside the scanned tree made the
     * data fixtures fail with "Email template name is expected to be non empty".
     */
    public function testNoLocaleDirectoriesAreNestedInsideTheScannedEmailsDir(): void
    {
        $nested = glob(self::emailsDir() . '/*', GLOB_ONLYDIR) ?: [];

        self::assertSame(
            [],
            $nested,
            'The emails dir is scanned recursively by Oro and every .twig in it needs a @name header. '
            . 'Put localized bodies under data/emails/invoice_translations instead.'
        );
    }

    /**
     * Tests that every template Oro will scan declares the metadata it requires.
     */
    public function testEveryScannedTemplateDeclaresItsMetadata(): void
    {
        foreach (self::TEMPLATES as $name) {
            $file = sprintf('%s/%s.html.twig', self::emailsDir(), $name);

            self::assertFileExists($file);

            $contents = (string) file_get_contents($file);

            // The file name must match the declared template name.
            self::assertStringContainsString('@name = ' . $name, $contents, $name . ' is missing its @name.');
            self::assertStringContainsString('@subject', $contents, $name . ' is missing its @subject.');
            self::assertStringContainsString('@entityName', $contents, $name . ' is missing its @entityName.');
        }
    }

    /**
     * Tests that a locale directory holds only template files.
     *
     * A "mv invoice/de_DE invoice_translations/de_DE" into an already-existing
     * target nests it as invoice_translations/de_DE/de_DE, leaving a second,
     * redundant copy of every body that nothing reads. Harmless at runtime,
     * which is exactly why it goes unnoticed.
     */
    public function testLocaleDirectoriesAreNotNested(): void
    {
        foreach (self::LOCALES as $locale) {
            $nested = glob(self::translationsDir() . '/' . $locale . '/*', GLOB_ONLYDIR) ?: [];

            self::assertSame([], $nested, sprintf('Unexpected subdirectory inside the %s locale.', $locale));
        }
    }

    /**
     * Tests that a locale directory holds exactly the expected bodies and nothing else.
     */
    public function testLocaleDirectoriesHoldOnlyTheExpectedFiles(): void
    {
        foreach (self::LOCALES as $locale) {
            $found = glob(self::translationsDir() . '/' . $locale . '/*.html.twig') ?: [];

            self::assertCount(count(self::TEMPLATES), $found, sprintf('Unexpected files in %s.', $locale));
        }
    }

    /**
     * Tests that every template the migration lists has a localized body for each locale.
     */
    public function testEveryLocalisedBodyIsPresent(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach (self::TEMPLATES as $name) {
                self::assertFileExists(
                    sprintf('%s/%s/%s.html.twig', self::translationsDir(), $locale, $name)
                );
            }
        }
    }
}
