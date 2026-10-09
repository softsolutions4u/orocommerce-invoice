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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Provider;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\InvoicePaymentFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Form\Type\InvoicePaymentType;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\FrontendPaymentFormProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit tests for the storefront payment form provider.
 */
class FrontendPaymentFormProviderTest extends TestCase
{
    private FormFactoryInterface&MockObject $formFactory;
    private UrlGeneratorInterface&MockObject $router;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->formFactory = $this->createMock(FormFactoryInterface::class);
        $this->router = $this->createMock(UrlGeneratorInterface::class);
    }

    /**
     * Tests get payment form view delegates to the type.
     */
    public function testGetPaymentFormViewDelegatesToTheType(): void
    {
        $invoicePayment = new InvoicePayment();
        $formView = $this->createMock(FormView::class);

        $form = $this->createMock(FormInterface::class);
        $form->method('createView')->willReturn($formView);

        $this->formFactory
            ->expects(self::once())
            ->method('create')
            ->with(InvoicePaymentType::class, $invoicePayment)
            ->willReturn($form);

        $provider = new FrontendPaymentFormProvider($this->formFactory, $this->router);

        self::assertSame($formView, $provider->getPaymentFormView($invoicePayment));
    }

    /**
     * Tests get payment form delegates to the type.
     */
    public function testGetPaymentFormDelegatesToTheType(): void
    {
        $invoicePayment = new InvoicePayment();
        $form = $this->createMock(FormInterface::class);

        $this->formFactory
            ->expects(self::once())
            ->method('create')
            ->with(InvoicePaymentType::class, $invoicePayment)
            ->willReturn($form);

        $provider = new FrontendPaymentFormProvider($this->formFactory, $this->router);

        self::assertSame($form, $provider->getPaymentForm($invoicePayment));
    }

    /**
     * Tests set invoice payment factory stores the factory.
     */
    public function testSetInvoicePaymentFactoryStoresTheFactory(): void
    {
        $provider = new FrontendPaymentFormProvider($this->formFactory, $this->router);

        $factory = $this->createMock(InvoicePaymentFactory::class);
        $provider->setInvoicePaymentFactory($factory);

        $reflection = new \ReflectionClass($provider);
        $property = $reflection->getProperty('invoicePaymentFactory');
        $property->setAccessible(true);

        self::assertSame($factory, $property->getValue($provider));
    }
}
