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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Form\Type;

use SoftSolutions4U\Bundle\InvoiceBundle\Form\Type\PaymentMethodMultiSelectFormType;
use Oro\Bundle\PaymentBundle\Method\PaymentMethodInterface;
use Oro\Bundle\PaymentBundle\Method\Provider\PaymentMethodProviderInterface;
use Oro\Bundle\PaymentBundle\Method\View\PaymentMethodViewInterface;
use Oro\Bundle\PaymentBundle\Method\View\PaymentMethodViewProviderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * Unit tests for the "payment methods allowed for invoices" setting field.
 */
class PaymentMethodMultiSelectFormTypeTest extends TypeTestCase
{
    /** @var array<string, string> identifier => admin label */
    private array $methods = [];

    /**
     * Returns the extensions.
     *
     * @return array
     */
    protected function getExtensions(): array
    {
        $methodProvider = $this->createMock(PaymentMethodProviderInterface::class);
        $methodProvider->method('getPaymentMethods')->willReturnCallback(function (): array {
            $methods = [];
            foreach (array_keys($this->methods) as $identifier) {
                $method = $this->createMock(PaymentMethodInterface::class);
                $method->method('getIdentifier')->willReturn($identifier);
                $methods[] = $method;
            }

            return $methods;
        });

        $viewProvider = $this->createMock(PaymentMethodViewProviderInterface::class);
        $viewProvider->method('getPaymentMethodView')->willReturnCallback(function (string $identifier) {
            $view = $this->createMock(PaymentMethodViewInterface::class);
            $view->method('getAdminLabel')->willReturn($this->methods[$identifier]);

            return $view;
        });

        return [new PreloadedExtension([new PaymentMethodMultiSelectFormType($methodProvider, $viewProvider)], [])];
    }

    /**
     * Tests that every available method is offered by its admin label, multi-select and optional.
     */
    public function testOffersAvailableMethods(): void
    {
        $this->methods = ['credit_card_3' => 'Credit Card', 'payment_term_1' => 'Payment Term'];

        $config = $this->factory->create(PaymentMethodMultiSelectFormType::class)->getConfig();

        self::assertSame(
            ['Credit Card' => 'credit_card_3', 'Payment Term' => 'payment_term_1'],
            $config->getOption('choices')
        );
        self::assertTrue($config->getOption('multiple'));
        self::assertFalse($config->getOption('required'));
    }

    /**
     * Tests the fix: methods sharing an admin label all remain selectable.
     */
    public function testMethodsWithTheSameLabelRemainSelectable(): void
    {
        $this->methods = ['credit_card_3' => 'Credit Card', 'credit_card_4' => 'Credit Card'];

        $choices = $this->factory->create(PaymentMethodMultiSelectFormType::class)->getConfig()->getOption('choices');

        self::assertCount(2, $choices);
        self::assertSame(['credit_card_3', 'credit_card_4'], array_values($choices));
        self::assertArrayHasKey('Credit Card (credit_card_4)', $choices);
    }

    /**
     * Tests that submitted selections are kept.
     */
    public function testSubmitsSelectedMethods(): void
    {
        $this->methods = ['credit_card_3' => 'Credit Card', 'payment_term_1' => 'Payment Term'];

        $form = $this->factory->create(PaymentMethodMultiSelectFormType::class);
        $form->submit(['payment_term_1']);

        self::assertTrue($form->isSynchronized());
        self::assertSame(['payment_term_1'], $form->getData());
    }

    /**
     * Tests the parent type and block prefix.
     */
    public function testParentAndName(): void
    {
        $type = new PaymentMethodMultiSelectFormType(
            $this->createMock(PaymentMethodProviderInterface::class),
            $this->createMock(PaymentMethodViewProviderInterface::class)
        );

        self::assertSame(ChoiceType::class, $type->getParent());
        self::assertSame('softsolutions4u_invoice_payment_methods', $type->getBlockPrefix());
        self::assertSame('softsolutions4u_invoice_payment_methods', $type->getName());
    }
}
