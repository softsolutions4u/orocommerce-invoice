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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use Oro\Bundle\CustomerBundle\Form\Type\CustomerSelectType;
use Oro\Bundle\CustomerBundle\Form\Type\CustomerUserSelectType;
use Oro\Bundle\PaymentBundle\Formatter\PaymentMethodLabelFormatter;
use Oro\Bundle\PaymentBundle\Formatter\PaymentStatusLabelFormatter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Invoice type.
 */
class InvoiceType extends AbstractType
{
    /** @var PaymentMethodLabelFormatter $paymentMethodLabelFormatter */
    private PaymentMethodLabelFormatter $paymentMethodLabelFormatter;

    /** @var PaymentStatusLabelFormatter $paymentStatusLabelFormatter */
    private PaymentStatusLabelFormatter $paymentStatusLabelFormatter;

    /** @var AuthorizationCheckerInterface $authorizationChecker */
    private AuthorizationCheckerInterface $authorizationChecker;

    /** @var TranslatorInterface $translator */
    private TranslatorInterface $translator;

    /**
     * Creates a new InvoiceType instance.
     *
     * @param PaymentMethodLabelFormatter $paymentMethodLabelFormatter
     * @param PaymentStatusLabelFormatter $paymentStatusLabelFormatter
     * @param AuthorizationCheckerInterface $authorizationChecker
     */
    public function __construct(
        PaymentMethodLabelFormatter $paymentMethodLabelFormatter,
        PaymentStatusLabelFormatter $paymentStatusLabelFormatter,
        AuthorizationCheckerInterface $authorizationChecker,
        TranslatorInterface $translator
    ) {
        $this->paymentMethodLabelFormatter = $paymentMethodLabelFormatter;
        $this->paymentStatusLabelFormatter = $paymentStatusLabelFormatter;
        $this->authorizationChecker = $authorizationChecker;
        $this->translator = $translator;
    }

    /**
     * Builds the form.
     *
     * @param array $options
     * @param FormBuilderInterface $builder
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Invoice|null $invoice */
        $invoice = $options['data'] ?? null;

        $paymentMethodLabel = $this->translator->trans('softsolutions4u.invoice.ui.not_available');
        if ($invoice instanceof Invoice && $invoice->getPaymentMethod()) {
            $paymentMethodLabel = $this->paymentMethodLabelFormatter->formatPaymentMethodLabel(
                $invoice->getPaymentMethod(),
                false
            );
        }

        $paymentStatusLabel = $this->translator->trans('softsolutions4u.invoice.ui.not_available');
        if ($invoice instanceof Invoice && $invoice->getPaymentStatus()) {
            $paymentStatusLabel = $this->paymentStatusLabelFormatter->formatPaymentStatusLabel(
                $invoice->getPaymentStatus()
            );
        }

        $builder
            ->add('customer', CustomerSelectType::class, [
                'label' => 'softsolutions4u.invoice.customer.label',
                'required' => true,
            ])
            ->add('customerUser', CustomerUserSelectType::class, [
                'label' => 'softsolutions4u.invoice.details.customer_user.label',
                'required' => false,
            ])
            ->add('invoiceNo', TextType::class, [
                'label' => 'softsolutions4u.invoice.invoice_no.label',
                'disabled' => $options['data'] && $options['data']->getId() !== null,
            ])
            ->add('poNumber', TextType::class, [
                'label' => 'softsolutions4u.invoice.po_number.label',
                'required' => false,
            ])
            ->add('issueDate', DateType::class, [
                'widget' => 'single_text',
            ])
            ->add('dueDate', DateType::class, [
                'widget' => 'single_text',
            ])
            ->add('status', ChoiceType::class, [
                'choices' => array_flip(Invoice::getStatuses()),
                'disabled' => true,
            ])
            ->add('internalStatus', TextType::class, [
                'label' => 'softsolutions4u.invoice.details.internal_status.label',
                'required' => false,
            ])
            ->add('paymentMethod', TextType::class, [
                'label' => 'softsolutions4u.invoice.details.payment_method.label',
                'required' => false,
                'disabled' => true,
                'mapped' => false,
                'data' => $paymentMethodLabel,
            ])
            ->add('paymentStatus', TextType::class, [
                'label' => 'softsolutions4u.invoice.payment_status.label',
                'required' => false,
                'disabled' => true,
                'mapped' => false,
                'data' => $paymentStatusLabel,
            ])
            ->add('amount', MoneyType::class, [
                'currency' => false,
            ])
            ->add('subtotal', MoneyType::class, [
                'currency' => false,
                'required' => false,
            ])
            ->add('discountAmount', MoneyType::class, [
                'label' => 'softsolutions4u.invoice.discount.label',
                'currency' => false,
                'required' => false,
            ])
            ->add('taxAmount', MoneyType::class, [
                'label' => 'softsolutions4u.invoice.tax.label',
                'currency' => false,
                'required' => false,
            ])
            ->add('grandTotal', MoneyType::class, [
                'label' => 'softsolutions4u.invoice.grand_total.label',
                'currency' => false,
                'required' => false,
            ])
            ->add('billingAddressFirstName', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.first_name.label',
                'required' => false,
            ])
            ->add('billingAddressLastName', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.last_name.label',
                'required' => false,
            ])
            ->add('billingAddressOrganization', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.organization.label',
                'required' => false,
            ])
            ->add('billingAddressPhone', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.phone.label',
                'required' => false,
            ])
            ->add('billingAddressStreet1', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.street1.label',
                'required' => false,
            ])
            ->add('billingAddressStreet2', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.street2.label',
                'required' => false,
            ])
            ->add('billingAddressCity', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.city.label',
                'required' => false,
            ])
            ->add('billingAddressState', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.state.label',
                'required' => false,
            ])
            ->add('billingAddressPostalCode', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.postal_code.label',
                'required' => false,
            ])
            ->add('billingAddressCountry', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.country.label',
                'required' => false,
            ])
            ->add('shippingAddressFirstName', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.first_name.label',
                'required' => false,
            ])
            ->add('shippingAddressLastName', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.last_name.label',
                'required' => false,
            ])
            ->add('shippingAddressOrganization', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.organization.label',
                'required' => false,
            ])
            ->add('shippingAddressPhone', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.phone.label',
                'required' => false,
            ])
            ->add('shippingAddressStreet1', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.street1.label',
                'required' => false,
            ])
            ->add('shippingAddressStreet2', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.street2.label',
                'required' => false,
            ])
            ->add('shippingAddressCity', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.city.label',
                'required' => false,
            ])
            ->add('shippingAddressState', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.state.label',
                'required' => false,
            ])
            ->add('shippingAddressPostalCode', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.postal_code.label',
                'required' => false,
            ])
            ->add('shippingAddressCountry', TextType::class, [
                'label' => 'softsolutions4u.invoice.address.country.label',
                'required' => false,
            ])
            ->add('lineItemIdsToDelete', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ]);

        if ($this->authorizationChecker->isGranted('softsolutions4u_invoice_add_note')) {
            $builder->add('memo', TextareaType::class, [
                'label' => 'softsolutions4u.invoice.actions.add_note.label',
                'required' => false,
            ]);
        }
    }

    /**
     * Configures the form options.
     *
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Invoice::class,
        ]);
    }
}
