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
use SoftSolutions4U\Bundle\InvoiceBundle\Generator\BankTransferReferenceGenerator;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\OrderPaymentLedgerSynchronizer;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaymentDetailsProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\OrderCancellationChecker;

/**
 * Unit tests for the bundle's service container setup.
 *
 * testConstructorArgumentsMatchServiceDefinitions guards against the error
 * "Too few arguments to function InvoiceManager::__construct(), 4 passed ...
 * and exactly 5 expected": a constructor gaining a parameter without
 * services.yml being updated.
 */
class SoftSolutions4UInvoiceExtensionTest extends TestCase
{
    /**
     * Loads the fixture data.
     *
     * @param string $environment
     * @return ContainerBuilder
     */
    private function load(string $environment = 'prod'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', $environment);

        (new SoftSolutions4UInvoiceExtension())->load([], $container);

        return $container;
    }

    /**
     * Tests the extension alias used for configuration keys.
     */
    public function testAlias(): void
    {
        self::assertSame('softsolutions4u_invoice', (new SoftSolutions4UInvoiceExtension())->getAlias());
    }

    /**
     * Tests that the core services are registered.
     */
    public function testRegistersCoreServices(): void
    {
        $container = $this->load();

        foreach (
            [
            InvoiceManager::class,
            OrderPaymentLedgerSynchronizer::class,
            BankTransferReferenceGenerator::class,
            InvoicePaymentDetailsProvider::class,
            ] as $id
        ) {
            self::assertTrue($container->hasDefinition($id), $id . ' is not registered');
        }
    }

    /**
     * Tests that InvoiceManager receives the ledger synchronizer, the cancellation checker and the logger.
     */
    public function testInvoiceManagerGetsTheLedgerSynchronizer(): void
    {
        $arguments = $this->load()->getDefinition(InvoiceManager::class)->getArguments();

        self::assertCount(7, $arguments);
        self::assertSame(OrderPaymentLedgerSynchronizer::class, (string) $arguments[4]);
        self::assertSame(OrderCancellationChecker::class, (string) $arguments[5]);
        self::assertSame('logger', (string) $arguments[6]);
    }

    /**
     * Tests every explicitly wired service against its constructor.
     */
    public function testConstructorArgumentsMatchServiceDefinitions(): void
    {
        $container = $this->load('dev');
        $checked = 0;

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition instanceof ChildDefinition || $definition->isAbstract() || $definition->isAutowired()) {
                continue;
            }

            $class = $definition->getClass() ?? $id;
            if (!str_starts_with($class, 'SoftSolutions4U\\Bundle\\InvoiceBundle\\') || !$this->isLoadable($class)) {
                continue;
            }

            if (null !== $definition->getFactory()) {
                continue;
            }

            $constructor = (new \ReflectionClass($class))->getConstructor();
            $given = $this->countPositionalArguments($definition);

            if (null === $given) {
                continue; // named arguments: resolved by name, not position
            }

            $required = $constructor?->getNumberOfRequiredParameters() ?? 0;
            $total = $constructor?->getNumberOfParameters() ?? 0;

            self::assertGreaterThanOrEqual(
                $required,
                $given,
                sprintf(
                    'Service "%s" passes %d argument(s) but %s::__construct() requires %d',
                    $id,
                    $given,
                    $class,
                    $required
                )
            );

            if (!$constructor?->isVariadic()) {
                self::assertLessThanOrEqual(
                    $total,
                    $given,
                    sprintf(
                        'Service "%s" passes %d argument(s) but %s::__construct() accepts %d',
                        $id,
                        $given,
                        $class,
                        $total
                    )
                );
            }

            ++$checked;
        }

        self::assertGreaterThan(10, $checked, 'Expected to check the bundle\'s explicitly wired services');
    }

    /**
     * Tests that the dev-only services are loaded only in dev.
     */
    public function testDevServicesOnlyInDev(): void
    {
        $dev = $this->load('dev');
        $prod = $this->load('prod');

        self::assertGreaterThanOrEqual(
            count($prod->getDefinitions()),
            count($dev->getDefinitions()),
            'Production must not register more services than development'
        );
    }

    /**
     * Tests that the bundle's form theme is added to Twig.
     */
    public function testPrependsTheFormTheme(): void
    {
        $container = new ContainerBuilder();

        (new SoftSolutions4UInvoiceExtension())->prepend($container);

        self::assertContains(
            '@SoftSolutions4UInvoice/Form/fields.html.twig',
            $container->getExtensionConfig('twig')[0]['form_themes']
        );
    }

    /**
     * Whether the class and everything it extends or implements can be loaded.
     *
     * @param string $class
     * @return bool
     */
    private function isLoadable(string $class): bool
    {
        try {
            return class_exists($class);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns the number of positional arguments, or null if any are named.
     *
     * @param Definition $definition
     * @return int|null
     */
    private function countPositionalArguments(Definition $definition): ?int
    {
        $arguments = $definition->getArguments();

        foreach (array_keys($arguments) as $key) {
            if (!is_int($key)) {
                return null;
            }
        }

        return count($arguments);
    }
}
