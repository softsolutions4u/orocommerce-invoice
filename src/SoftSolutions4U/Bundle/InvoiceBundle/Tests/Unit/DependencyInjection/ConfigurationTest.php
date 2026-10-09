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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\DependencyInjection;

use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\SoftSolutions4UInvoiceExtension;
use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Unit tests for the bundle's system configuration defaults.
 */
class ConfigurationTest extends TestCase
{
    /**
     * Returns the settings.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        return $config['settings'];
    }

    /**
     * Tests the defaults of the settings that change behaviour.
     */
    public function testBehaviouralDefaults(): void
    {
        $settings = $this->settings();

        self::assertTrue($settings[Configuration::INVOICE_ENABLED]['value']);
        self::assertTrue($settings[Configuration::BANK_DETAILS_ENABLED]['value']);
        self::assertFalse(
            $settings[Configuration::AUTO_CREATE_FOR_PAYMENT_TERM]['value'],
            'Invoices must not be created automatically unless an administrator opts in'
        );
        self::assertSame([], $settings[Configuration::INVOICE_PAYMENT_METHODS]['value']);
    }

    /**
     * Tests that company details start empty, so nothing wrong is printed on a PDF by default.
     */
    public function testCompanyDetailsStartEmpty(): void
    {
        $settings = $this->settings();

        self::assertSame('', $settings[Configuration::COMPANY_NAME]['value']);
        self::assertSame('', $settings[Configuration::COMPANY_VAT_NUMBER]['value']);
        self::assertNull($settings[Configuration::COMPANY_LOGO]['value']);
    }

    /**
     * Tests that the default instructions do not tell customers to quote the invoice number.
     */
    public function testDefaultInstructionsDoNotContradictTheBankTransferReference(): void
    {
        $settings = $this->settings();

        foreach ([Configuration::BANK_US_INSTRUCTIONS, Configuration::BANK_DE_INSTRUCTIONS] as $key) {
            $text = strtolower((string) $settings[$key]['value']);

            self::assertStringNotContainsString('invoice number', $text, $key);
            self::assertStringNotContainsString('rechnungsnummer', $text, $key);
        }
    }

    /**
     * Tests that every declared setting has a default.
     */
    public function testEveryDeclaredSettingIsDefined(): void
    {
        $settings = $this->settings();
        $constants = (new \ReflectionClass(Configuration::class))->getConstants();

        foreach ($constants as $name => $value) {
            if ('ROOT_NODE' === $name || !is_string($value)) {
                continue;
            }

            self::assertArrayHasKey($value, $settings, sprintf('Configuration::%s has no default', $name));
        }
    }

    /**
     * Tests the full config key used with ConfigManager.
     */
    public function testConfigKeyIsScopedToTheBundle(): void
    {
        self::assertSame(
            SoftSolutions4UInvoiceExtension::ALIAS . '.company_vat_number',
            Configuration::getConfigKeyByName(Configuration::COMPANY_VAT_NUMBER)
        );
        self::assertSame(
            'softsolutions4u_invoice.company_vat_number',
            Configuration::getConfigKeyByName('company_vat_number')
        );
    }
}
