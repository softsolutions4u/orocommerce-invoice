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

use SoftSolutions4U\Bundle\InvoiceBundle\Model\ExtendInvoicePayment;
use Brick\Math\BigDecimal;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\CurrencyBundle\Entity\CurrencyAwareInterface;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\CustomerOwnerAwareInterface;
use Oro\Bundle\CustomerBundle\Entity\Ownership\AuditableFrontendCustomerUserAwareTrait;
use Oro\Bundle\EntityBundle\EntityProperty\DatesAwareInterface;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\ConfigField;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\OrganizationBundle\Entity\OrganizationAwareInterface;
use Oro\Bundle\OrganizationBundle\Entity\OrganizationInterface;

/**
 * @ORM\Entity()
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Table(
 *      name="softsolutions4u_invoice_payment",
 * )
 */
#[Config(
    defaultValues: [
        'entity' => [
            'icon' => 'fa-money',
        ],
        'ownership' => [
            'owner_type' => 'ORGANIZATION',
            'owner_field_name' => 'organization',
            'owner_column_name' => 'organization_id',
            'frontend_owner_type' => 'FRONTEND_USER',
            'frontend_owner_field_name' => 'customerUser',
            'frontend_owner_column_name' => 'customer_user_id',
            'frontend_customer_field_name' => 'customer',
            'frontend_customer_column_name' => 'customer_id',
        ],
        'security' => [
            'type' => 'ACL',
            'group_name' => 'commerce',
            'category' => 'invoice',
            'permissions' => 'VIEW;CREATE',
        ],
        'dataaudit' => [
            'auditable' => true,
        ],
    ]
)]
class InvoicePayment extends ExtendInvoicePayment implements
    DatesAwareInterface,
    CurrencyAwareInterface,
    CustomerOwnerAwareInterface,
    OrganizationAwareInterface
{
    use AuditableFrontendCustomerUserAwareTrait;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    #[ConfigField(defaultValues: ['importexport' => ['identity' => true]])]
    protected ?int $id = null;

    /**
     * @var bool
     * @ORM\Column(name="active", type="boolean")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected bool $active = false;

    /**
     * @ORM\Column(name="payment_method", type="string", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?string $paymentMethod = null;

    /**
     * @ORM\Column(name="amount", type="money", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected float $amount = 0;

    /**
     * @ORM\Column(name="total", type="money", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected float $total = 0;

    /**
     * @ORM\Column(name="currency", type="string", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?string $currency = null;

    protected ?Price $price = null;

    /**
     * @ORM\Column(name="created_at", type="datetime", nullable=true)
     */
    protected ?\DateTime $createdAt = null;

    /**
     * @ORM\Column(name="updated_at", type="datetime", nullable=true)
     */
    protected ?\DateTime $updatedAt = null;

    /**
     * @ORM\OneToMany(targetEntity="InvoicePaymentLineItem",
     *      mappedBy="invoicePayment", cascade={"ALL"}, orphanRemoval=true
     * )
     * @ORM\OrderBy({"invoice" = "ASC"})
     * @var Collection<int,InvoicePaymentLineItem>
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected Collection $lineItems;

    /**
     * @ORM\ManyToOne(targetEntity="Oro\Bundle\OrganizationBundle\Entity\Organization")
     * @ORM\JoinColumn(name="organization_id", referencedColumnName="id", onDelete="SET NULL", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?Organization $organization = null;

    /**
     * @ORM\ManyToOne(targetEntity="SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice", inversedBy="invoicePayments")
     * @ORM\JoinColumn(name="invoice_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?Invoice $invoice = null;

    /**
     * @ORM\Column(name="payment_transaction_id", type="integer", nullable=true, unique=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?int $paymentTransactionId = null;

    /**
     * @ORM\Column(name="pending_confirmation", type="boolean", options={"default"=false})
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected bool $pendingConfirmation = false;

    /**
     * Returns the organization.
     *
     * @return OrganizationInterface|null
     */
    public function getOrganization(): ?OrganizationInterface
    {
        return $this->organization;
    }

    /**
     * Sets the organization.
     *
     * @param OrganizationInterface $organization
     * @return self
     */
    public function setOrganization(OrganizationInterface $organization): self
    {
        $this->organization = $organization;
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
     * @param Invoice|null $invoice
     * @return self
     */
    public function setInvoice(?Invoice $invoice): self
    {
        $this->invoice = $invoice;
        return $this;
    }

    /**
     * Returns the payment transaction id.
     *
     * @return int|null
     */
    public function getPaymentTransactionId(): ?int
    {
        return $this->paymentTransactionId;
    }

    /**
     * Sets the payment transaction id.
     *
     * @param int|null $paymentTransactionId
     * @return self
     */
    public function setPaymentTransactionId(?int $paymentTransactionId): self
    {
        $this->paymentTransactionId = $paymentTransactionId;
        return $this;
    }

    /**
     * Checks whether the pending confirmation applies.
     *
     * @return bool
     */
    public function isPendingConfirmation(): bool
    {
        return $this->pendingConfirmation;
    }

    /**
     * Sets the pending confirmation.
     *
     * @param bool $pendingConfirmation
     * @return self
     */
    public function setPendingConfirmation(bool $pendingConfirmation): self
    {
        $this->pendingConfirmation = $pendingConfirmation;
        return $this;
    }

    /**
     * Creates a new InvoicePayment instance.
     */
    public function __construct()
    {
        $this->lineItems = new ArrayCollection();
        parent::__construct();
    }

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
     * Checks whether the active applies.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Sets the active.
     *
     * @param bool $active
     * @return self
     */
    public function setActive(bool $active): self
    {
        $this->active = $active;
        return $this;
    }

    /**
     * Returns the payment method.
     *
     * @return string|null
     */
    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    /**
     * Sets the payment method.
     *
     * @param string|null $paymentMethod
     * @return self
     */
    public function setPaymentMethod(?string $paymentMethod): self
    {
        $this->paymentMethod = $paymentMethod ?? '';
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
     * @param mixed $currency
     * @return self
     */
    public function setCurrency($currency): self
    {
        $this->currency = (string) $currency;
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
            $this->price = Price::create((string)$this->total, $this->currency);
        }
    }

    /**
     * Handles update price.
     */
    public function updatePrice(): void
    {
        $this->total = (float) ($this->price?->getValue() ?? 0);
        $this->currency = $this->price?->getCurrency();
    }

    /**
     * Returns the created at.
     *
     * @return \DateTime|null
     */
    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    /**
     * Sets the created at.
     *
     * @param \DateTime|null $createdAt
     * @return self
     */
    public function setCreatedAt(?\DateTime $createdAt = null): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Checks whether the created at set applies.
     *
     * @return bool
     */
    public function isCreatedAtSet(): bool
    {
        return null !== $this->createdAt;
    }

    /**
     * Returns the updated at.
     *
     * @return \DateTime|null
     */
    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    /**
     * Sets the updated at.
     *
     * @param \DateTime|null $updatedAt
     * @return self
     */
    public function setUpdatedAt(?\DateTime $updatedAt = null): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Checks whether the updated at set applies.
     *
     * @return bool
     */
    public function isUpdatedAtSet(): bool
    {
        return null !== $this->updatedAt;
    }

    /**
     * Checks whether the line item is present.
     *
     * @param InvoicePaymentLineItem $lineItem
     * @return bool
     */
    public function hasLineItem(InvoicePaymentLineItem $lineItem): bool
    {
        return $this->lineItems->contains($lineItem);
    }

    /**
     * Adds the line item.
     *
     * @param InvoicePaymentLineItem $lineItem
     * @return self
     */
    public function addLineItem(InvoicePaymentLineItem $lineItem): self
    {
        if (!$this->hasLineItem($lineItem)) {
            $this->lineItems[] = $lineItem;
            $lineItem->setInvoicePayment($this);
        }
        $this->recalculateAmount();
        return $this;
    }

    /**
     * Removes the line item.
     *
     * @param InvoicePaymentLineItem $lineItem
     * @return self
     */
    public function removeLineItem(InvoicePaymentLineItem $lineItem): self
    {
        if ($this->hasLineItem($lineItem)) {
            $this->lineItems->removeElement($lineItem);
        }
        $this->recalculateAmount();
        return $this;
    }

    /**
     * @param Collection<int, InvoicePaymentLineItem> $lineItems
     * @return InvoicePayment
     */
    public function setLineItems(Collection $lineItems): self
    {
        foreach ($lineItems as $lineItem) {
            $lineItem->setInvoicePayment($this);
        }

        $this->lineItems = $lineItems;
        $this->recalculateAmount();
        return $this;
    }

    /**
     * @return Collection<int, InvoicePaymentLineItem>
     */
    public function getLineItems(): Collection
    {
        return $this->lineItems;
    }

    /**
     * @return ArrayCollection<int, Invoice>
     */
    public function getInvoices(): ArrayCollection
    {
        $invoices = new ArrayCollection();
        foreach ($this->getLineItems() as $lineItem) {
            $invoices->add($lineItem->getInvoice());
        }
        return $invoices;
    }

    /**
     * Does this InvoicePayment contain a line item with the provided $invoice?
     */
    public function hasInvoice(Invoice $invoice): bool
    {
        return $this->getLineItems()->exists(function ($key, $element) use ($invoice) {
            return $element->getInvoice()->getId() === $invoice->getId();
        });
    }

    /**
     * Removes the line item by invoice.
     *
     * @param Invoice $invoice
     * @return bool
     */
    public function removeLineItemByInvoice(Invoice $invoice): bool
    {
        $lineItems = $this->getLineItems()->filter(function (InvoicePaymentLineItem $lineItem) use ($invoice) {
            return $lineItem->getInvoice()->getId() === $invoice->getId();
        });

        if ($lineItems->count() !== 1) {
            return false;
        }

        $this->removeLineItem($lineItems->current());

        return true;
    }

    /**
     * Handles recalculate amount.
     *
     * @return float
     */
    public function recalculateAmount(): float
    {
        $amount = BigDecimal::zero()->toScale(2);
        foreach ($this->getLineItems() as $lineItem) {
            $amount = $amount->plus(BigDecimal::of($lineItem->getAmount()));
            if (!$this->getCurrency()) {
                $this->setCurrency($lineItem->getCurrency());
            }
        }

        $value = $amount->toFloat();

        $this->setAmount($value);
        $this->setTotal($value);

        return $this->getAmount();
    }

    /**
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function preSave(): void
    {
        $this->updatePrice();
    }

    /**
     * Returns the entity identifier.
     *
     * @return int|null
     */
    public function getEntityIdentifier(): ?int
    {
        return $this->id;
    }
}
