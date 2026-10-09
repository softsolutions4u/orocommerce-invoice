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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoiceLineItem;
use Oro\Bundle\ProductBundle\Entity\Product;
use Oro\Bundle\ProductBundle\Entity\ProductUnit;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Invoice line item type.
 */
class InvoiceLineItemType extends AbstractType
{
    /**
     * Builds the form.
     *
     * @param array $options
     * @param FormBuilderInterface $builder
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('product', EntityType::class, [
                'class' => Product::class,
                'choice_label' => static function (Product $product): string {
                    $name = $product->getDefaultName() ? (string) $product->getDefaultName() : '';
                    return trim($product->getSku() . ($name ? ' — ' . $name : ''));
                },
                'choice_attr' => static function (Product $product): array {
                    $name = $product->getDefaultName() ? (string) $product->getDefaultName() : '';
                    return [
                        'data-sku' => $product->getSku(),
                        'data-name' => $name,
                    ];
                },
                'required' => true,
                'placeholder' => 'softsolutions4u.invoice.invoicelineitem.product.placeholder',
                'attr' => ['class' => 'invoice-line-item-product'],
            ])
            ->add('quantity', NumberType::class, [
                'required' => true,
                'scale' => 2,
                'attr' => ['class' => 'invoice-line-item-qty'],
            ])
            ->add('productUnit', EntityType::class, [
                'class' => ProductUnit::class,
                'choice_label' => 'code',
                'required' => true,
                'attr' => ['class' => 'invoice-line-item-unit'],
            ])
            ->add('id', HiddenType::class, [
                'required' => false,
                'mapped' => false,
            ])
            ->add('discountAmount', NumberType::class, [
                'required' => false,
                'label' => false,
                'attr' => ['class' => 'invoice-line-item-discount'],
            ])
            ->add('taxAmount', NumberType::class, [
                'required' => false,
                'label' => false,
                'attr' => ['class' => 'invoice-line-item-tax'],
            ]);
    }

    /**
     * Configures the form options.
     *
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvoiceLineItem::class,
            'block_prefix' => 'softsolutions4u_invoice_line_item',
        ]);
    }
}
