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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Twig;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\LocaleBundle\Twig\DateTimeExtension as BaseDateTimeExtension;
use Psr\Container\ContainerExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\TwigFilter;

/**
 * Date time extension.
 */
class DateTimeExtension extends BaseDateTimeExtension
{
    public const FUTURE_DATE_KEY = 'softsolutions4u.invoice.datetime.future';
    public const PAST_DATE_KEY = 'softsolutions4u.invoice.datetime.past';

    /** @var \DateTimeZone|null $timezone */
    protected ?\DateTimeZone $timezone = null;

    /**
     * Returns the filters.
     *
     * @return array
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('commerce_datetime_since', [$this, 'sinceDateTime']),
        ];
    }

    /**
     * Returns how long ago the given date was, in words (for example '3 days ago').
     *
     * @param string|\DateTime $date
     * @param array<string,mixed> $options Currently unused
     * @return string|null
     */
    public function sinceDateTime(mixed $date, array $options = []): ?string
    {
        if (!$date) {
            return null;
        }

        $instance = $this->getCarbonInstanceFromDate($date);

        $diffString = $instance->diffForHumans([
            'syntax' => CarbonInterface::DIFF_ABSOLUTE
        ]);

        if ($instance->isFuture()) {
            // Is in future
            return $this->getTranslator()->trans(self::FUTURE_DATE_KEY, ['%time%' => $diffString]);
        }

        // Is in past
        return $this->getTranslator()->trans(self::PAST_DATE_KEY, ['%time%' => $diffString]);
    }

    /**
     * Returns the carbon instance from date.
     *
     * @param \DateTime|string $date
     * @return Carbon
     */
    protected function getCarbonInstanceFromDate(mixed $date): Carbon
    {
        if ($date instanceof \DateTime) {
            return Carbon::instance($date)->shiftTimezone($this->getConfiguredTimeZone());
        }

        // String
        return Carbon::parse($date);
    }

    /**
     * Returns the configured time zone.
     *
     * @return \DateTimeZone|null
     */
    protected function getConfiguredTimeZone(): ?\DateTimeZone
    {
        if ($this->timezone === null) {
            try {
                $configManager = $this->getConfigManager();
                $this->timezone = new \DateTimeZone($configManager->get('oro_locale.timezone'));
            } catch (ContainerExceptionInterface $e) {
            }
        }

        return $this->timezone;
    }

    /**
     * Returns the translator.
     *
     * @return TranslatorInterface
     * @throws ContainerExceptionInterface
     */
    protected function getTranslator(): TranslatorInterface
    {
        return $this->container->get('translator');
    }

    /**
     * Returns the config manager.
     *
     * @return ConfigManager
     * @throws ContainerExceptionInterface
     */
    protected function getConfigManager(): ConfigManager
    {
        return $this->container->get('oro_config.global');
    }

    /**
     * Returns the services this class subscribes to.
     *
     * @return array
     */
    public static function getSubscribedServices(): array
    {
        return array_merge(
            [
                'oro_config.global',
                'translator',
            ],
            parent::getSubscribedServices(),
        );
    }
}
