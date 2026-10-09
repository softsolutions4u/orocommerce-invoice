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

use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\EmailBundle\Entity\EmailTemplate;
use Oro\Bundle\EmailBundle\Entity\EmailTemplateTranslation;
use Oro\Bundle\LocaleBundle\Entity\Localization;
use Oro\Bundle\MigrationBundle\Fixture\VersionedFixtureInterface;

/**
 * Adds localized versions of the invoice email templates.
 *
 * Email bodies are rendered in a sandboxed Twig environment that does not allow
 * the trans filter, so translation has to happen through Oro's own localized
 * template rows - the per-language tabs on the email template edit screen -
 * rather than inside the template markup.
 *
 * Each locale lives in its own directory under data/emails/invoice_translations,
 * e.g. data/emails/invoice_translations/de_DE/<template_name>.html.twig. Adding
 * fr_FR is a matter of creating that directory and adding its subjects below.
 *
 * IMPORTANT: these must stay OUTSIDE data/emails/invoice. That directory is what
 * LoadInvoiceEmailTemplates::getEmailsDir() hands to Oro's AbstractEmailFixture,
 * which walks it recursively and requires every .twig file it finds to carry a
 * {# @name = ... #} metadata header. Localized bodies have no such header - they
 * are translations of a template, not templates - so keeping them in there made
 * oro:migration:data:load abort with "Email template name is expected to be non
 * empty in file softsolutions4u_invoice_cancelled_notification".
 */
class LoadInvoiceEmailTemplateTranslations implements
    FixtureInterface,
    VersionedFixtureInterface,
    DependentFixtureInterface
{
    /**
     * Subject lines per locale, keyed by template name.
     *
     * Subjects are short enough to keep here rather than parsing them back out
     * of the template headers.
     */
    private const SUBJECTS = [
        'de_DE' => [
            'softsolutions4u_invoice_notification' =>
                'Ihre Rechnung {{ entity.invoiceNo|default(\'\') }} ist bereit',
            'softsolutions4u_invoice_paid_notification' =>
                'Ihre Rechnung {{ entity.invoiceNo|default(\'\') }} wurde vollständig bezahlt',
            'softsolutions4u_invoice_reminder_notification' =>
                'Zahlungserinnerung: Rechnung {{ entity.invoiceNo|default(\'\') }}',
            'softsolutions4u_invoice_cancelled_notification' =>
                'Ihre Rechnung {{ entity.invoiceNo|default(\'\') }} wurde storniert',
        ],
    ];

    /**
     * Returns the fixture version.
     *
     * Bump this after editing any localized template so Oro re-imports it.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return '1.2';
    }

    /**
     * Returns the fixtures this one depends on.
     *
     * @return array<int, string>
     */
    public function getDependencies(): array
    {
        return [LoadInvoiceEmailTemplates::class];
    }

    /**
     * Attaches a localized subject and body to each invoice email template.
     *
     * @param ObjectManager $manager
     */
    public function load(ObjectManager $manager): void
    {
        $baseDir = __DIR__ . '/data/emails/invoice_translations';
        $changed = false;

        foreach (self::SUBJECTS as $localeCode => $subjects) {
            $localization = $this->findLocalization($manager, $localeCode);

            if (null === $localization) {
                continue;
            }

            foreach ($subjects as $templateName => $subject) {
                $file = sprintf('%s/%s/%s.html.twig', $baseDir, $localeCode, $templateName);

                if (!is_file($file)) {
                    continue;
                }

                $template = $manager->getRepository(EmailTemplate::class)
                    ->findOneBy(['name' => $templateName]);

                if (null === $template) {
                    continue;
                }

                $this->applyTranslation(
                    $manager,
                    $template,
                    $localization,
                    $subject,
                    trim((string) file_get_contents($file))
                );
                $changed = true;
            }
        }

        if ($changed) {
            $manager->flush();
        }
    }

    /**
     * Creates or updates the localized row for one template.
     *
     * @param ObjectManager $manager
     * @param EmailTemplate $template
     * @param Localization $localization
     * @param string $subject
     * @param string $content
     */
    private function applyTranslation(
        ObjectManager $manager,
        EmailTemplate $template,
        Localization $localization,
        string $subject,
        string $content
    ): void {
        $translation = null;

        foreach ($template->getTranslations() as $existing) {
            if ($existing->getLocalization() === $localization) {
                $translation = $existing;
                break;
            }
        }

        if (null === $translation) {
            $translation = new EmailTemplateTranslation();
            $translation->setTemplate($template);
            $translation->setLocalization($localization);
            $template->addTranslation($translation);
        }

        $translation->setSubject($subject);
        $translation->setContent($content);

        // false means "do not fall back to the default language" - the same as
        // clearing the fallback checkbox on the template edit screen.
        $translation->setSubjectFallback(false);
        $translation->setContentFallback(false);

        $manager->persist($translation);
    }

    /**
     * Returns the localization for a language code, if one is configured.
     *
     * @param ObjectManager $manager
     * @param string $localeCode
     * @return Localization|null
     */
    private function findLocalization(ObjectManager $manager, string $localeCode): ?Localization
    {
        /** @var array<int, Localization> $localizations */
        $localizations = $manager->getRepository(Localization::class)->findAll();

        foreach ($localizations as $localization) {
            if ($localization->getLanguageCode() === $localeCode) {
                return $localization;
            }
        }

        return null;
    }
}
