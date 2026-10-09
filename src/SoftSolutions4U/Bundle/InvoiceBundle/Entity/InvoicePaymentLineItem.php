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

use SoftSolutions4U\Bundle\InvoiceBundle\Model\ExtendInvoicePaymentLineItem;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\ConfigField;

/**
 * @ORM\Entity()
 * @ORM\Table(
 *      name="softsolutions4u_invoice_payment_line_item",
 *      uniqueConstraints={
 *          @ORM\UniqueConstraint(name="commerce_inv_payment_line_item_uidx", columns={
 *              "invoice_payment_id",
 *              "invoice_id"
 *          })
 *      }
 * )
 */
#[Config(
    defaultValues: [
        'entity' => [
            'icon' => 'fa-file-text-o',
        ],
        'security' => [
            'type' => 'ACL',
            'group_name' => 'commerce',
            'category' => 'invoice',
        ],
        'dataaudit' => [
            'auditable' => true,
        ],
    ]
)]
class InvoicePaymentLineItem extends ExtendInvoicePaymentLineItem
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    #[ConfigField(defaultValues: ['importexport' => ['identity' => true]])]
    protected ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment",inversedBy="lineItems")
     * @ORM\JoinColumn(name="invoice_payment_id", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    protected ?InvoicePayment $invoicePayment = null;

    /**
     * @ORM\ManyToOne(targetEntity="SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice")
     * @ORM\JoinColumn(name="invoice_id", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    protected ?Invoice $invoice = null;

    /**
     * @ORM\Column(name="amount", type="money", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected float $amount = 0;

    /**
     * @ORM\Column(name="currency", type="string", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?string $currency = null;

    protected ?Price $price = null;

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
     * Returns the invoice payment.
     *
     * @return InvoicePayment|null
     */
    public function getInvoicePayment(): ?InvoicePayment
    {
        return $this->invoicePayment;
    }

    /**
     * Sets the invoice payment.
     *
     * @param InvoicePayment $invoicePayment
     * @return self
     */
    public function setInvoicePayment(InvoicePayment $invoicePayment): self
    {
        $this->invoicePayment = $invoicePayment;
        return $this;
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
     * Returns the amount.
     *
     * @return float
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * Sets the amount.
     *
     * @param float $amount
     * @return self
     */
    public function setAmount(float $amount): self
    {
        $this->amount = $amount;
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
            $this->price = Price::create((string)$this->amount, $this->currency);
        }
    }

    /**
     * Handles update price.
     */
    public function updatePrice(): void
    {
        $this->amount = (float) ($this->price?->getValue() ?? 0);
        $this->currency = $this->price?->getCurrency();
    }

    /**
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function preSave(): void
    {
        $this->updatePrice();
    }
}
