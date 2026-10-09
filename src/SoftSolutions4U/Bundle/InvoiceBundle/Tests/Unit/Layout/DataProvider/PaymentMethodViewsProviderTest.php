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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Layout\DataProvider;

use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\Configuration;
use SoftSolutions4U\Bundle\InvoiceBundle\Layout\DataProvider\PaymentMethodViewsProvider;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\PaymentBundle\Context\PaymentContextInterface;
use Oro\Bundle\PaymentBundle\Method\PaymentMethodInterface;
use Oro\Bundle\PaymentBundle\Method\Provider\PaymentMethodProviderInterface;
use Oro\Bundle\PaymentBundle\Method\View\PaymentMethodViewInterface;
use Oro\Bundle\PaymentBundle\Method\View\PaymentMethodViewProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for which payment methods the storefront invoice payment form offers.
 */
class PaymentMethodViewsProviderTest extends TestCase
{
    private PaymentMethodViewProviderInterface&MockObject $viewProvider;
    private PaymentMethodProviderInterface&MockObject $methodProvider;

    /** @var mixed $configuredMethods */
    private mixed $configuredMethods = [];

    /** @var PaymentMethodViewsProvider $provider */
    private PaymentMethodViewsProvider $provider;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->viewProvider = $this->createMock(PaymentMethodViewProviderInterface::class);
        $this->methodProvider = $this->createMock(PaymentMethodProviderInterface::class);

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')
            ->with(Configuration::getConfigKeyByName(Configuration::INVOICE_PAYMENT_METHODS))
            ->willReturnCallback(fn () => $this->configuredMethods);

        $this->provider = new PaymentMethodViewsProvider($this->viewProvider, $this->methodProvider, $configManager);
    }

    /**
     * Tests that with nothing configured, every available method is offered.
     */
    public function testNoRestrictionOffersAllMethods(): void
    {
        $this->methodProvider->method('getPaymentMethods')
            ->willReturn([$this->method('credit_card_3'), $this->method('payment_term_1')]);

        $this->viewProvider->expects(self::once())
            ->method('getPaymentMethodViews')
            ->with(['credit_card_3', 'payment_term_1'])
            ->willReturn([]);

        $this->provider->getViews($this->createMock(PaymentContextInterface::class));
    }

    /**
     * Tests that views are keyed by method identifier with label, block and options.
     */
    public function testBuildsViews(): void
    {
        $context = $this->createMock(PaymentContextInterface::class);
        $this->methodProvider->method('getPaymentMethods')->willReturn([$this->method('credit_card_3')]);
        $this->viewProvider->method('getPaymentMethodViews')->willReturn([
            $this->view('credit_card_3', 'Credit Card', '_credit_card_widget', $context, ['vault' => true]),
        ]);

        self::assertSame(
            [
                'credit_card_3' => [
                    'label' => 'Credit Card',
                    'block' => '_credit_card_widget',
                    'options' => ['vault' => true],
                ],
            ],
            $this->provider->getViews($context)
        );
    }

    /**
     * Tests that a configured list restricts the methods, matching full ids or type prefixes.
     */
    public function testConfiguredMethodsRestrictTheList(): void
    {
        $this->configuredMethods = ['credit_card_3', 'payment_term'];
        $this->methodProvider->method('getPaymentMethods')->willReturn([
            $this->method('credit_card_3'),
            $this->method('credit_card_4'),
            $this->method('payment_term_1'),
            $this->method('money_order_2'),
        ]);

        $this->viewProvider->expects(self::once())
            ->method('getPaymentMethodViews')
            ->with(['credit_card_3', 'payment_term_1'])
            ->willReturn([]);

        $this->provider->getViews($this->createMock(PaymentContextInterface::class));
    }

    /**
     * Tests that a configured list matching nothing offers nothing, without asking for views.
     */
    public function testConfiguredMethodsMatchingNothingOfferNothing(): void
    {
        $this->configuredMethods = ['wire_transfer'];
        $this->methodProvider->method('getPaymentMethods')->willReturn([$this->method('credit_card_3')]);
        $this->viewProvider->expects(self::never())->method('getPaymentMethodViews');

        self::assertSame([], $this->provider->getViews($this->createMock(PaymentContextInterface::class)));
    }

    /**
     * Tests that an empty entry in the configured list does not match every method.
     */
    public function testEmptyConfiguredEntryDoesNotMatchEverything(): void
    {
        $this->configuredMethods = [''];
        $this->methodProvider->method('getPaymentMethods')->willReturn([$this->method('credit_card_3')]);
        $this->viewProvider->expects(self::never())->method('getPaymentMethodViews');

        self::assertSame([], $this->provider->getViews($this->createMock(PaymentContextInterface::class)));
    }

    /**
     * Tests that no available methods means no views.
     */
    public function testNoAvailableMethods(): void
    {
        $this->methodProvider->method('getPaymentMethods')->willReturn([]);
        $this->viewProvider->expects(self::never())->method('getPaymentMethodViews');

        self::assertSame([], $this->provider->getViews($this->createMock(PaymentContextInterface::class)));
    }

    /**
     * Returns the method.
     *
     * @param string $identifier
     * @return PaymentMethodInterface
     */
    private function method(string $identifier): PaymentMethodInterface
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getIdentifier')->willReturn($identifier);

        return $method;
    }

    /**
     * Returns the view.
     *
     * @param array<string, mixed> $options
     * @param string $identifier
     * @param string $label
     * @param string $block
     * @param PaymentContextInterface $context
     * @return PaymentMethodViewInterface
     */
    private function view(
        string $identifier,
        string $label,
        string $block,
        PaymentContextInterface $context,
        array $options
    ): PaymentMethodViewInterface {
        $view = $this->createMock(PaymentMethodViewInterface::class);
        $view->method('getPaymentMethodIdentifier')->willReturn($identifier);
        $view->method('getLabel')->willReturn($label);
        $view->method('getBlock')->willReturn($block);
        $view->method('getOptions')->with($context)->willReturn($options);

        return $view;
    }
}
