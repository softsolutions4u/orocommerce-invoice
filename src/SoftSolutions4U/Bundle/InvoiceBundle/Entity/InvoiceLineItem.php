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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Entity;

use SoftSolutions4U\Bundle\InvoiceBundle\Model\ExtendInvoiceLineItem;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\ProductBundle\Entity\Product;
use Oro\Bundle\ProductBundle\Entity\ProductUnit;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\ConfigField;

/**
 * @ORM\Entity()
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Table(
 *      name="softsolutions4u_invoice_line_item",
 * )
 */
#[Config(
    defaultValues: [
        'entity' => [
            'icon' => 'fa-check-square-o',
        ],
        'security' => [
            'type' => 'ACL',
            'group_name' => 'commerce',
            'category' => 'invoice',
            'permissions' => 'VIEW;DELETE',
        ],
        'dataaudit' => [
            'auditable' => true,
        ],
    ]
)]
class InvoiceLineItem extends ExtendInvoiceLineItem
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    #[ConfigField(defaultValues: ['importexport' => ['identity' => false]])]
    protected ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice",inversedBy="lineItems")
     * @ORM\JoinColumn(name="invoice_id", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?Invoice $invoice = null;

    /**
     * @ORM\ManyToOne(targetEntity="Oro\Bundle\ProductBundle\Entity\Product")
     * @ORM\JoinColumn(name="product_id", referencedColumnName="id", onDelete="SET NULL", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?Product $product = null;

    /**
     * @ORM\Column(name="product_sku", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?string $productSku = null;

    /**
     * @ORM\Column(name="product_name", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?string $productName = null;

    /**
     * @ORM\Column(name="quantity", type="float", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?float $quantity = null;

    /**
     * @ORM\ManyToOne(targetEntity="Oro\Bundle\ProductBundle\Entity\ProductUnit")
     * @ORM\JoinColumn(name="product_unit_id", referencedColumnName="code", onDelete="SET NULL", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?ProductUnit $productUnit = null;

    /**
     * @ORM\Column(name="product_unit_code", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?string $productUnitCode = null;

    /**
     * @ORM\Column(name="value", type="money", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?float $value = null;

    /**
     * @ORM\Column(name="currency", type="string", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?string $currency = null;

    protected ?Price $price = null;

    /**
     * @ORM\Column(name="summary", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?string $summary = null;

    /**
     * @ORM\Column(name="subtotal", type="money")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected float $subtotal = 0;

    /**
     * @ORM\Column(name="discount_amount", type="money")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected float $discountAmount = 0;

    /**
     * @ORM\Column(name="tax_amount", type="money")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected float $taxAmount = 0;

    /**
     * @ORM\Column(name="total", type="money")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected float $total = 0;

    /**
     * @ORM\Column(name="sort_order", type="integer")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected int $sortOrder = 0;

    /**
     * Returns the id.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the invoice.
     *
     * @return Invoice|null
     */
    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    /**
     * Sets the invoice.
     *
     * @param Invoice $invoice
     * @return self
     */
    public function setInvoice(Invoice $invoice): self
    {
        $this->invoice = $invoice;
        return $this;
    }

    /**
     * Returns the product.
     *
     * @return Product|null
     */
    public function getProduct(): ?Product
    {
        return $this->product;
    }

    /**
     * Sets the product.
     *
     * @param Product|null $product
     * @return self
     */
    public function setProduct(?Product $product): self
    {
        $this->product = $product;
        return $this;
    }

    /**
     * Returns the product sku.
     *
     * @return string|null
     */
    public function getProductSku(): ?string
    {
        return $this->productSku;
    }

    /**
     * Sets the product sku.
     *
     * @param string|null $productSku
     * @return self
     */
    public function setProductSku(?string $productSku): self
    {
        $this->productSku = $productSku;
        return $this;
    }

    /**
     * Returns the product name.
     *
     * @return string|null
     */
    public function getProductName(): ?string
    {
        return $this->productName;
    }

    /**
     * Sets the product name.
     *
     * @param string|null $productName
     * @return self
     */
    public function setProductName(?string $productName): self
    {
        $this->productName = $productName;
        return $this;
    }

    /**
     * Returns the quantity.
     *
     * @return float|null
     */
    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    /**
     * Sets the quantity.
     *
     * @param float|null $quantity
     * @return self
     */
    public function setQuantity(?float $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    /**
     * Returns the product unit.
     *
     * @return ProductUnit|null
     */
    public function getProductUnit(): ?ProductUnit
    {
        return $this->productUnit;
    }

    /**
     * Sets the product unit.
     *
     * @param ProductUnit|null $productUnit
     * @return self
     */
    public function setProductUnit(?ProductUnit $productUnit): self
    {
        $this->productUnit = $productUnit;
        return $this;
    }

    /**
     * Returns the product unit code.
     *
     * @return string|null
     */
    public function getProductUnitCode(): ?string
    {
        return $this->productUnitCode;
    }

    /**
     * Sets the product unit code.
     *
     * @param string|null $productUnitCode
     * @return self
     */
    public function setProductUnitCode(?string $productUnitCode): self
    {
        $this->productUnitCode = $productUnitCode;
        return $this;
    }

    /**
     * Returns the value.
     *
     * @return float|null
     */
    public function getValue(): ?float
    {
        return $this->value;
    }

    /**
     * Sets the value.
     *
     * @param float|null $value
     * @return self
     */
    public function setValue(?float $value): self
    {
        $this->value = $value;
        $this->createPrice();
        return $this;
    }

    /**
     * Returns the currency.
     *
     * @return string|null
     */
    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    /**
     * Sets the currency.
     *
     * @param string $currency
     * @return self
     */
    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        $this->createPrice();
        return $this;
    }

    /**
     * Returns the summary.
     *
     * @return string|null
     */
    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * Sets the summary.
     *
     * @param string|null $summary
     * @return self
     */
    public function setSummary(?string $summary): self
    {
        $this->summary = $summary;
        return $this;
    }

    /**
     * Returns the subtotal.
     *
     * @return float
     */
    public function getSubtotal(): float
    {
        return $this->subtotal;
    }

    /**
     * Sets the subtotal.
     *
     * @param float $subtotal
     * @return self
     */
    public function setSubtotal(float $subtotal): self
    {
        $this->subtotal = $subtotal;
        return $this;
    }

    /**
     * Returns the discount amount.
     *
     * @return float
     */
    public function getDiscountAmount(): float
    {
        return $this->discountAmount;
    }

    /**
     * Sets the discount amount.
     *
     * @param float|null $discountAmount
     * @return self
     */
    public function setDiscountAmount(?float $discountAmount): self
    {
        $this->discountAmount = $discountAmount ?? 0.0;
        return $this;
    }
    /**
     * Returns the tax amount.
     *
     * @return float
     */
    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }
    /**
     * Sets the tax amount.
     *
     * @param float|null $taxAmount
     * @return self
     */
    public function setTaxAmount(?float $taxAmount): self
    {
        $this->taxAmount = $taxAmount ?? 0.0;
        return $this;
    }

    /**
     * Returns the total.
     *
     * @return float
     */
    public function getTotal(): float
    {
        return $this->total;
    }

    /**
     * Sets the total.
     *
     * @param float $total
     * @return self
     */
    public function setTotal(float $total): self
    {
        $this->total = $total;
        return $this;
    }

    /**
     * Returns the sort order.
     *
     * @return int
     */
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    /**
     * Sets the sort order.
     *
     * @param int $sortOrder
     * @return self
     */
    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    /**
     * Sets the price.
     *
     * @param Price|null $price
     * @return self
     */
    public function setPrice(?Price $price): self
    {
        $this->price = $price;
        $this->updatePrice();
        return $this;
    }

    /**
     * Returns the price.
     *
     * @return Price|null
     */
    public function getPrice(): ?Price
    {
        return $this->price;
    }

    /**
     * @ORM\PostLoad
     */
    public function createPrice(): void
    {
        if (null !== $this->currency) {
            $this->price = Price::create((string)($this->value ?? 0), $this->currency);
        }
    }

    /**
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function preSave(): void
    {
        $this->updatePrice();

        // Ensure currency is populated before persisting to satisfy DB not-null constraint.
        if (null === $this->currency) {
            $invoiceCurrency = $this->invoice?->getCurrency();
            if (null !== $invoiceCurrency) {
                $this->currency = $invoiceCurrency;
                $this->createPrice();
            }
        }

        // As a last resort, set a sensible default to avoid DB constraint failures.
        if (null === $this->currency) {
            $this->currency = 'USD';
            $this->createPrice();
        }
    }

    /**
     * Handles update price.
     */
    public function updatePrice(): void
    {
        $this->value = (float) ($this->price?->getValue() ?? $this->value ?? 0);

        $priceCurrency = $this->price?->getCurrency();
        if (null !== $priceCurrency) {
            $this->currency = $priceCurrency;
        } elseif (null === $this->currency && null !== $this->invoice) {
            // Preserve or inherit invoice currency when price doesn't provide one.
            $this->currency = $this->invoice->getCurrency();
        }
    }
}
