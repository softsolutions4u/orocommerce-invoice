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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Twig;

use Carbon\Carbon;
use SoftSolutions4U\Bundle\InvoiceBundle\Twig\DateTimeExtension;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\TwigFilter;

/**
 * Unit tests for the "due in / due ... ago" filters on the storefront invoice page.
 *
 * The clock is frozen at 2026-09-16 12:00. String dates are parsed in PHP's
 * default time zone, so the frozen clock uses it too; the DateTime tests pin
 * everything to UTC explicitly.
 */
class DateTimeExtensionTest extends TestCase
{
    private DateTimeExtension&MockObject $extension;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00'));

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->with('oro_locale.timezone')->willReturn('Asia/Kolkata');

        $this->extension = $this->getMockBuilder(DateTimeExtension::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConfigManager', 'getTranslator'])
            ->getMock();
        $this->extension->method('getConfigManager')->willReturn($configManager);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => match ($id) {
                DateTimeExtension::FUTURE_DATE_KEY => 'In ' . $params['%time%'],
                DateTimeExtension::PAST_DATE_KEY => $params['%time%'] . ' ago',
                default => $id,
            }
        );
        $this->extension->method('getTranslator')->willReturn($translator);
    }

    /**
     * Tears down the test fixture.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    /**
     * Tests that the filter is registered under their template names.
     */
    public function testRegistersFilters(): void
    {
        $names = array_map(
            static fn (TwigFilter $filter): string => $filter->getName(),
            $this->extension->getFilters()
        );

        self::assertSame(['commerce_datetime_since'], $names);
    }

    /**
     * Tests that the configuration service is requested from the container.
     */
    public function testSubscribesToTheConfigService(): void
    {
        self::assertContains('oro_config.global', DateTimeExtension::getSubscribedServices());
        self::assertContains('translator', DateTimeExtension::getSubscribedServices());
    }

    /**
     * Tests the wording for a future date.
     */
    public function testFutureDate(): void
    {
        self::assertSame('In 3 days', $this->extension->sinceDateTime('2026-09-19 12:00:00'));
    }

    /**
     * Tests the wording for a past date.
     */
    public function testPastDate(): void
    {
        self::assertSame('2 weeks ago', $this->extension->sinceDateTime('2026-09-02 12:00:00'));
    }

    /**
     * Tests that an empty value renders nothing instead of "now".
     */
    public function testEmptyValues(): void
    {
        foreach ([null, '', false] as $empty) {
            self::assertNull($this->extension->sinceDateTime($empty));
        }
    }

    /**
     * Tests that a DateTime is read as wall-clock time in the configured time zone.
     *
     * 15:00 on the stored date means 15:00 in Kolkata (UTC+5:30), i.e. 09:30 UTC,
     * which is before "now" (12:00 UTC) - even though 15:00 UTC would be later.
     */
    public function testDateTimeIsReadInTheConfiguredTimeZone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00', 'UTC'));
        $date = new \DateTime('2026-09-16 15:00:00', new \DateTimeZone('UTC'));
        self::assertStringEndsWith(' ago', (string) $this->extension->sinceDateTime($date));
    }

    /**
     * Tests a DateTime later the same day in the configured time zone.
     */
    public function testLaterTodayInTheConfiguredTimeZone(): void
    {
        // 20:00 Kolkata = 14:30 UTC, after "now" (12:00 UTC).
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00', 'UTC'));
        $date = new \DateTime('2026-09-16 20:00:00', new \DateTimeZone('UTC'));
        self::assertStringStartsWith('In ', (string) $this->extension->sinceDateTime($date));
    }
}
