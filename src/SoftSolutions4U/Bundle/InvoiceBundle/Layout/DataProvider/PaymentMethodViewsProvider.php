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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Layout\DataProvider;

use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\PaymentBundle\Context\PaymentContextInterface;
use Oro\Bundle\PaymentBundle\Method\PaymentMethodInterface;
use Oro\Bundle\PaymentBundle\Method\Provider\PaymentMethodProviderInterface;
use Oro\Bundle\PaymentBundle\Method\View\PaymentMethodViewProviderInterface;

/**
 * Payment method views provider.
 */
class PaymentMethodViewsProvider
{
    /** @var PaymentMethodViewProviderInterface $paymentMethodViewProvider */
    protected PaymentMethodViewProviderInterface $paymentMethodViewProvider;

    /** @var PaymentMethodProviderInterface $paymentMethodProvider */
    protected PaymentMethodProviderInterface $paymentMethodProvider;

    /** @var ConfigManager $configManager */
    protected ConfigManager $configManager;

    /**
     * Creates a new PaymentMethodViewsProvider instance.
     *
     * @param PaymentMethodViewProviderInterface $paymentMethodViewProvider
     * @param PaymentMethodProviderInterface $paymentMethodProvider
     * @param ConfigManager $configManager
     */
    public function __construct(
        PaymentMethodViewProviderInterface $paymentMethodViewProvider,
        PaymentMethodProviderInterface $paymentMethodProvider,
        ConfigManager $configManager
    ) {
        $this->paymentMethodViewProvider = $paymentMethodViewProvider;
        $this->paymentMethodProvider = $paymentMethodProvider;
        $this->configManager = $configManager;
    }

    /**
     * Returns the views.
     *
     * @param PaymentContextInterface $context
     * @return array<string,array<string,mixed>>
     */
    public function getViews(PaymentContextInterface $context): array
    {
        $paymentMethodViews = [];

        // Get all payment methods
        $methods = $this->paymentMethodProvider->getPaymentMethods();

        if (count($methods) !== 0) {
            // Builds a list of payment method identifiers
            $methodIdentifiers = array_map(function (PaymentMethodInterface $method) {
                return $method->getIdentifier();
            }, $methods);

            $methodIdentifiers = $this->filterByConfiguredMethods($methodIdentifiers);

            if (!$methodIdentifiers) {
                return [];
            }

            $views = $this->paymentMethodViewProvider->getPaymentMethodViews($methodIdentifiers);
            foreach ($views as $view) {
                $paymentMethodViews[$view->getPaymentMethodIdentifier()] = [
                    'label' => $view->getLabel(),
                    'block' => $view->getBlock(),
                    'options' => $view->getOptions($context),
                ];
            }
        }

        return $paymentMethodViews;
    }

    /**
     * Restricts the identifiers to those selected in Invoice Management.
     *
     * An empty setting means no restriction, so clearing the field does not
     * leave the storefront payment form with nothing to offer. Matching is done
     * on both the full identifier and its type prefix, because the config field
     * may store either depending on how the method was registered.
     *
     * @param array<int, string> $methodIdentifiers
     * @return array<int, string>
     *
     */
    private function filterByConfiguredMethods(array $methodIdentifiers): array
    {
        $configured = $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::INVOICE_PAYMENT_METHODS)
        );

        if (!is_array($configured) || !$configured) {
            return $methodIdentifiers;
        }

        return array_values(array_filter(
            $methodIdentifiers,
            static function (string $identifier) use ($configured): bool {
                if (in_array($identifier, $configured, true)) {
                    return true;
                }

                // e.g. config holds "payment_term" while the identifier is "payment_term_1"
                foreach ($configured as $allowed) {
                    if ('' !== $allowed && str_starts_with($identifier, $allowed)) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }
}
