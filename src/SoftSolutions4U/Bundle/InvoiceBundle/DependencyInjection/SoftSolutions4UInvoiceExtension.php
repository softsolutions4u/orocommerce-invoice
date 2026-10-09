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

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * Loads the invoice bundle services and configuration.
 */
class SoftSolutions4UInvoiceExtension extends Extension implements PrependExtensionInterface
{
    public const ALIAS = 'softsolutions4u_invoice';

    /**
     * Loads the bundle configuration and service definitions.
     *
     * @param array<int, mixed> $configs
     * @param ContainerBuilder $container
     * @return void
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $container->prependExtensionConfig($this->getAlias(), $config);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');
        $loader->load('controllers.yml');
    }

    /**
     * Registers the bundle's form theme with Twig.
     *
     * @param ContainerBuilder $container
     * @return void
     */
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('twig', [
            'form_themes' => [
                '@SoftSolutions4UInvoice/Form/fields.html.twig',
            ],
        ]);
    }

    /**
     * Returns the extension alias.
     *
     * @return string
     */
    public function getAlias(): string
    {
        return self::ALIAS;
    }
}
