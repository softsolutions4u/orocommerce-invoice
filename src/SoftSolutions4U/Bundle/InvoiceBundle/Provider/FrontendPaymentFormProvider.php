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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Provider;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\InvoicePaymentFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Form\Type\InvoicePaymentType;
use Oro\Bundle\LayoutBundle\Layout\DataProvider\AbstractFormProvider;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Frontend payment form provider.
 */
class FrontendPaymentFormProvider extends AbstractFormProvider
{
    /** @var InvoicePaymentFactory|null $invoicePaymentFactory */
    private ?InvoicePaymentFactory $invoicePaymentFactory = null;

    /**
     * Creates a new FrontendPaymentFormProvider instance.
     *
     * @param FormFactoryInterface $formFactory
     * @param UrlGeneratorInterface $router
     */
    public function __construct(FormFactoryInterface $formFactory, UrlGeneratorInterface $router)
    {
        parent::__construct($formFactory, $router);
    }

    /**
     * Returns the payment form view.
     *
     * @param InvoicePayment $invoicePayment
     * @return FormView
     */
    public function getPaymentFormView(InvoicePayment $invoicePayment): FormView
    {
        return $this->getFormView(InvoicePaymentType::class, $invoicePayment);
    }

    /**
     * Returns the payment form.
     *
     * @param InvoicePayment $invoicePayment
     * @return FormInterface<mixed>
     * @throws \Exception
     */
    public function getPaymentForm(InvoicePayment $invoicePayment): FormInterface
    {
        return $this->getForm(InvoicePaymentType::class, $invoicePayment);
    }

    /**
     * Sets the invoice payment factory.
     *
     * @param InvoicePaymentFactory $invoicePaymentFactory
     */
    public function setInvoicePaymentFactory(InvoicePaymentFactory $invoicePaymentFactory): void
    {
        $this->invoicePaymentFactory = $invoicePaymentFactory;
    }
}
