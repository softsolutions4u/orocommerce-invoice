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
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PostSetDataEvent;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Invoice payment type.
 */
class InvoicePaymentType extends AbstractType
{
    public const NAME = 'softsolutions4u_invoice_payment';

    /**
     * Builds the form.
     *
     * @param array $options
     * @param FormBuilderInterface $builder
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', HiddenType::class, [
                'mapped' => false,
            ])
            ->add('payment_amount', NumberType::class, [
                'mapped' => false,
                'required' => true,
                'scale' => 2,
                'html5' => true,
                'attr' => [
                    'min' => '0.01',
                    'step' => '0.01',
                    'inputmode' => 'decimal',
                ],
            ])
            ->add('payment_method', HiddenType::class, [
                'mapped' => true,
                'property_path' => 'paymentMethod',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('additional_data', HiddenType::class, [
                'mapped' => false,
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (PostSetDataEvent $event): void {
            $payment = $event->getData();
            if (!$payment instanceof InvoicePayment) {
                return;
            }

            $form = $event->getForm();
            $form->get('id')->setData($payment->getId());

            $lineItem = $payment->getLineItems()->first();
            if ($lineItem) {
                $form->get('payment_amount')->setData($lineItem->getAmount());
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (PostSubmitEvent $event): void {
            $form = $event->getForm();
            $payment = $event->getData();

            if (!$payment instanceof InvoicePayment) {
                return;
            }

            $lineItem = $payment->getLineItems()->first();
            if (!$lineItem || !$lineItem->getInvoice() instanceof Invoice) {
                $form->addError(new FormError('softsolutions4u.invoice.frontend.payment.errors.no_invoice'));
                return;
            }

            if (!$payment->getPaymentMethod()) {
                $form->get('payment_method')->addError(
                    new FormError('softsolutions4u.invoice.frontend.payment.errors.no_payment_method')
                );
                return;
            }

            $invoice = $lineItem->getInvoice();
            $amount = $form->get('payment_amount')->getData();

            if (!is_numeric($amount) || (float) $amount <= 0) {
                $form->get('payment_amount')->addError(
                    new FormError('softsolutions4u.invoice.frontend.payment.errors.amount_not_positive')
                );
                return;
            }

            $amount = round((float) $amount, 2);
            $balance = round($invoice->getBalance(), 2);

            if ($amount > $balance) {
                $form->get('payment_amount')->addError(new FormError(
                    'softsolutions4u.invoice.frontend.payment.errors.amount_exceeds_balance',
                    'softsolutions4u.invoice.frontend.payment.errors.amount_exceeds_balance',
                    [
                        '%currency%' => (string) $invoice->getCurrency(),
                        '%balance%' => number_format($balance, 2),
                    ]
                ));
                return;
            }

            $lineItem->setAmount($amount);
            $payment->recalculateAmount();
        });
    }

    /**
     * Configures the form options.
     *
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvoicePayment::class,
            'allow_extra_fields' => true,
        ]);
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
