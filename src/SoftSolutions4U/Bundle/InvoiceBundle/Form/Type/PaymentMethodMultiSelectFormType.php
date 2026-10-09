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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Form\Type;

use Oro\Bundle\PaymentBundle\Method\Provider\PaymentMethodProviderInterface;
use Oro\Bundle\PaymentBundle\Method\View\PaymentMethodViewProviderInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Payment method multi select form type.
 */
class PaymentMethodMultiSelectFormType extends AbstractType
{
    public const NAME = 'softsolutions4u_invoice_payment_methods';

    /** @var PaymentMethodProviderInterface $methodProvider */
    protected PaymentMethodProviderInterface $methodProvider;

    /** @var PaymentMethodViewProviderInterface $methodViewProvider */
    protected PaymentMethodViewProviderInterface $methodViewProvider;

    /**
     * Creates a new PaymentMethodMultiSelectFormType instance.
     *
     * @param PaymentMethodProviderInterface $methodProvider
     * @param PaymentMethodViewProviderInterface $methodViewProvider
     */
    public function __construct(
        PaymentMethodProviderInterface $methodProvider,
        PaymentMethodViewProviderInterface $methodViewProvider,
    ) {
        $this->methodProvider = $methodProvider;
        $this->methodViewProvider = $methodViewProvider;
    }

    /**
     * Configures the form options.
     *
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'multiple' => true,
                'required' => false,
            ]
        );

        $resolver->setNormalizer(
            'choices',
            function (OptionsResolver $options) {
                return $this->getChoices();
            }
        );
    }

    /**
     * Returns the selectable methods as label => identifier.
     *
     * Choice labels are array keys, so two methods sharing an admin label (for
     * example two "Credit Card" rules) would overwrite each other and one could
     * never be selected. A repeated label is therefore suffixed with the method
     * identifier to keep every method selectable.
     *
     * @return array<string,string>
     */
    private function getChoices(): array
    {
        $result = [];
        foreach ($this->methodProvider->getPaymentMethods() as $method) {
            $methodId = $method->getIdentifier();
            $label = (string) $this
                ->methodViewProvider->getPaymentMethodView($methodId)
                ->getAdminLabel();

            if (array_key_exists($label, $result)) {
                $label = sprintf('%s (%s)', $label, $methodId);
            }

            $result[$label] = $methodId;
        }

        return $result;
    }

    /**
     * Returns the parent form type.
     *
     * @return string
     */
    public function getParent(): string
    {
        return ChoiceType::class;
    }

    /**
     * Returns the name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->getBlockPrefix();
    }

    /**
     * Returns the form block prefix.
     *
     * @return string
     */
    public function getBlockPrefix(): string
    {
        return self::NAME;
    }
}
