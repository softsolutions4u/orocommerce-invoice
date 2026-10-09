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

namespace SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration.
 */
class Configuration implements ConfigurationInterface
{
    public const ROOT_NODE = SoftSolutions4UInvoiceExtension::ALIAS;

    public const INVOICE_ENABLED = 'invoices_enabled';
    public const INVOICE_PAYMENT_METHODS = 'invoices_payment_methods';
    public const COMPANY_NAME = 'company_name';
    public const COMPANY_LOGO = 'company_logo';
    public const COMPANY_VAT_NUMBER = 'company_vat_number';
    public const AUTO_CREATE_FOR_PAYMENT_TERM = 'auto_create_for_payment_term';

    public const BANK_DETAILS_ENABLED = 'bank_details_enabled';

    public const BANK_US_ENABLED = 'bank_us_enabled';
    public const BANK_US_ACCOUNT_HOLDER = 'bank_us_account_holder';
    public const BANK_US_BANK_NAME = 'bank_us_bank_name';
    public const BANK_US_ROUTING_NUMBER = 'bank_us_routing_number';
    public const BANK_US_ACCOUNT_NUMBER = 'bank_us_account_number';
    public const BANK_US_SWIFT = 'bank_us_swift';
    public const BANK_US_INSTRUCTIONS = 'bank_us_instructions';

    public const BANK_DE_ENABLED = 'bank_de_enabled';
    public const BANK_DE_ACCOUNT_HOLDER = 'bank_de_account_holder';
    public const BANK_DE_BANK_NAME = 'bank_de_bank_name';
    public const BANK_DE_IBAN = 'bank_de_iban';
    public const BANK_DE_BIC = 'bank_de_bic';
    public const BANK_DE_INSTRUCTIONS = 'bank_de_instructions';

    /**
     * Generates the configuration tree builder.
     *
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        SettingsBuilder::append(
            $rootNode,
            [
                self::INVOICE_ENABLED => [
                    'value' => true,
                    'type' => 'boolean',
                ],
                self::INVOICE_PAYMENT_METHODS => [
                    'value' => [],
                    'type' => 'array',
                ],
                self::BANK_DETAILS_ENABLED => [
                    'value' => true,
                    'type' => 'boolean',
                ],
                self::BANK_US_ENABLED => [
                    'value' => true,
                    'type' => 'boolean',
                ],
                self::BANK_US_ACCOUNT_HOLDER => [
                    'value' => '',
                    'type' => 'string',
                ],
                self::BANK_US_BANK_NAME => [
                    'value' => '',
                    'type' => 'string',
                ],
                self::BANK_US_ROUTING_NUMBER => [
                    'value' => '',
                    'type' => 'string',
                ],
                self::BANK_US_ACCOUNT_NUMBER => [
                    'value' => '',
                    'type' => 'string',
                ],
                self::BANK_US_SWIFT => [
                    'value' => '',
                    'type' => 'string',
                ],
                self::BANK_US_INSTRUCTIONS => [
                    'value' => 'Payment is due by the due date shown on this invoice.',
                    'type' => 'string',
                ],
                self::BANK_DE_ENABLED => [
                    'value' => true,
                    'type' => 'boolean',
                ],
                self::BANK_DE_ACCOUNT_HOLDER => [
                    'value' => '',
                    'type' => 'string',
                ],
                self::BANK_DE_BANK_NAME => [
                    'value' => '',
                    'type' => 'string',
                ],
                self::BANK_DE_IBAN => [
                    'value' => '',
                    'type' => 'string',
                ],
                self::BANK_DE_BIC => [
                    'value' => '',
                    'type' => 'string',
                ],
                self::BANK_DE_INSTRUCTIONS => [
                    'value' => 'Bitte begleichen Sie den Betrag bis zum angegebenen Fälligkeitsdatum.',
                    'type' => 'string',
                ],
                self::COMPANY_NAME => [
                    'type' => 'string',
                    'value' => '',
                ],
                self::COMPANY_VAT_NUMBER => [
                    'type' => 'string',
                    'value' => '',
                ],
                self::COMPANY_LOGO => [
                    'type' => 'image',
                    'value' => null,
                ],
                self::AUTO_CREATE_FOR_PAYMENT_TERM => [
                    'type' => 'boolean',
                    'value' => false,
                ],
            ]
        );

        return $treeBuilder;
    }

    /**
     * Returns the config key by name.
     *
     * @param string $key
     * @return string
     */
    public static function getConfigKeyByName(string $key): string
    {
        return implode(ConfigManager::SECTION_MODEL_SEPARATOR, [SoftSolutions4UInvoiceExtension::ALIAS, $key]);
    }
}
