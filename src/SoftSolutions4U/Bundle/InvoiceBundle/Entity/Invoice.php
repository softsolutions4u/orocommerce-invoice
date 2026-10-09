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

use SoftSolutions4U\Bundle\InvoiceBundle\Model\ExtendInvoice;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\EntityBundle\EntityProperty\DatesAwareInterface;
use Oro\Bundle\EntityBundle\EntityProperty\DatesAwareTrait;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\Config;
use Oro\Bundle\EntityConfigBundle\Metadata\Attribute\ConfigField;
use Oro\Component\Math\BigDecimal;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\OrganizationBundle\Entity\OrganizationInterface;
use Oro\Bundle\OrganizationBundle\Entity\OrganizationAwareInterface;

/**
 * @ORM\Entity(repositoryClass="SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository")
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Table(
 *      name="softsolutions4u_invoice",
 *      uniqueConstraints={
 *          @ORM\UniqueConstraint(name="softsolutions4u_invoice_no_uidx", columns={"invoice_no"}),
 *      },
 *      indexes={
 *          @ORM\Index(name="softsolutions4u_invoice_issue_date_idx", columns={"issue_date"}),
 *          @ORM\Index(name="softsolutions4u_invoice_due_date_idx", columns={"due_date"}),
 *      }
 * )
 */
#[Config(
    routeName: 'softsolutions4u_invoice_index',
    routeView: 'softsolutions4u_invoice_view',
    defaultValues: [
        'entity' => [
            'icon' => 'fa-file-text-o'
        ],
        'ownership' => [
            'owner_type' => 'ORGANIZATION',
            'owner_field_name' => 'organization',
            'owner_column_name' => 'organization_id',
            'frontend_owner_type' => 'FRONTEND_CUSTOMER',
            'frontend_owner_field_name' => 'customer',
            'frontend_owner_column_name' => 'customer_id',
        ],
        'security' => [
            'type' => 'ACL',
            'group_name' => 'commerce',
            'category' => 'invoice',
            'permissions' => 'VIEW;CREATE;EDIT;DELETE',
        ],
        'dataaudit' => [
            'auditable' => true,
        ],
        'email' => [
            'available_in_template' => true,
        ],
    ]
)]
class Invoice extends ExtendInvoice implements
    DatesAwareInterface,
    OrganizationAwareInterface
{
    use DatesAwareTrait;

    /**
     * @ORM\Column(name="created_at", type="datetime")
     */
    #[ConfigField(defaultValues: [
        'entity' => ['label' => 'oro.ui.created_at'],
        'email' => ['available_in_template' => true],
    ])]
    protected ?\DateTimeInterface $createdAt = null;

    /**
     * @ORM\Column(name="updated_at", type="datetime")
     */
    #[ConfigField(defaultValues: [
        'entity' => ['label' => 'oro.ui.updated_at'],
        'email' => ['available_in_template' => true],
    ])]
    protected ?\DateTimeInterface $updatedAt = null;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_OPEN = 'open';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_CANCELLED = 'cancelled';

    /** Status code => translation key of its label. */
    public const STATUSES = [
        self::STATUS_DRAFT => 'softsolutions4u.invoice.statuses.draft',
        self::STATUS_POSTED => 'softsolutions4u.invoice.statuses.posted',
        self::STATUS_OPEN => 'softsolutions4u.invoice.statuses.open',
        self::STATUS_OVERDUE => 'softsolutions4u.invoice.statuses.overdue',
        self::STATUS_PAID => 'softsolutions4u.invoice.statuses.paid',
        self::STATUS_PARTIALLY_PAID => 'softsolutions4u.invoice.statuses.partially_paid',
        self::STATUS_CANCELLED => 'softsolutions4u.invoice.statuses.cancelled',
    ];

    /** Statuses a customer can pay; drafts are never payable. */
    public const UNPAID_STATUSES = [
        self::STATUS_POSTED,
        self::STATUS_OPEN,
        self::STATUS_OVERDUE,
        self::STATUS_PARTIALLY_PAID,
    ];

    /** Statuses the overdue process may switch to Overdue; part-paid invoices keep Partially Paid. */
    public const OVERDUE_CANDIDATE_STATUSES = [
        self::STATUS_POSTED,
        self::STATUS_OPEN,
    ];

    /** Statuses visible on the storefront; drafts are internal only. */
    public const FRONTEND_VISIBLE_STATUSES = [
        self::STATUS_POSTED,
        self::STATUS_OPEN,
        self::STATUS_OVERDUE,
        self::STATUS_PAID,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_CANCELLED,
    ];

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    #[ConfigField(defaultValues: [
        'importexport' => ['identity' => false],
        'email' => ['available_in_template' => true],
    ])]
    protected ?int $id = null;

    /**
     * @ORM\OneToMany(
     *     targetEntity="SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment",
     *     mappedBy="invoice",
     *     cascade={"persist", "remove"},
     *     orphanRemoval=true
     * )
     * @ORM\OrderBy({"createdAt" = "DESC"})
     * @var Collection<int, InvoicePayment>
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true], 'email' => ['available_in_template' => true]])]
    protected Collection $invoicePayments;

    /**
     * @ORM\Column(name="invoice_no", type="string", length=50, nullable=false)
     */
    #[ConfigField(defaultValues: [
        'importexport' => ['identity' => true],
        'dataaudit' => ['auditable' => true],
        'email' => ['available_in_template' => true],
    ])]
    protected ?string $invoiceNo = null;

    /**
     * @ORM\ManyToOne(targetEntity="Oro\Bundle\CustomerBundle\Entity\Customer")
     * @ORM\JoinColumn(name="customer_id", referencedColumnName="id", onDelete="SET NULL", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?Customer $customer = null;

    /**
     * @ORM\Column(name="issue_date", type="date", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?\DateTime $issueDate = null;

    /**
     * @ORM\Column(name="due_date", type="date", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?\DateTime $dueDate = null;

    /**
     * @ORM\Column(name="posted_at", type="datetime", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?\DateTime $postedAt = null;

    /**
     * When the "paid in full" email was sent; guards against sending it twice.
     *
     * @ORM\Column(name="paid_notification_sent_at", type="datetime", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?\DateTime $paidNotificationSentAt = null;

    /**
     * When the cancellation email was sent; guards against sending it twice.
     *
     * @ORM\Column(name="cancelled_notification_sent_at", type="datetime", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?\DateTime $cancelledNotificationSentAt = null;

    /**
     * @ORM\Column(name="amount", type="money", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected float $amount = 0;

    /**
     * @ORM\Column(name="subtotal", type="money")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected float $subtotal = 0;

    /**
     * @ORM\Column(name="discount_amount", type="money")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected float $discountAmount = 0;

    /**
     * @ORM\Column(name="tax_amount", type="money")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected float $taxAmount = 0;

    /**
     * @ORM\Column(name="grand_total", type="money")
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected float $grandTotal = 0;

    /**
     * @ORM\Column(name="currency", type="string", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $currency = null;

    protected ?Price $price = null;

    /**
     * @ORM\Column(name="amount_paid", type="money", nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected float $amountPaid = 0;

    /**
     * @ORM\Column(name="shipping_amount", type="money", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected float $shippingAmount = 0;

    /**
     * @ORM\OneToMany(targetEntity="SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoiceLineItem",
     *      mappedBy="invoice", cascade={"ALL"}, orphanRemoval=true
     * )
     * @ORM\OrderBy({"id" = "ASC"})
     * @var Collection<int,InvoiceLineItem>
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected Collection $lineItems;

    /**
     * @ORM\ManyToOne(targetEntity="Oro\Bundle\OrderBundle\Entity\Order")
     * @ORM\JoinColumn(name="order_id", referencedColumnName="id", onDelete="SET NULL", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?Order $order = null;

    /**
     * @ORM\ManyToOne(targetEntity="Oro\Bundle\CustomerBundle\Entity\CustomerUser")
     * @ORM\JoinColumn(name="customer_user_id", referencedColumnName="id", onDelete="SET NULL", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?CustomerUser $customerUser = null;

    /**
     * @ORM\Column(name="payment_method", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $paymentMethod = null;

    /**
     * @ORM\Column(name="payment_status", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $paymentStatus = null;

    /**
     * @ORM\Column(name="internal_status", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $internalStatus = null;

    /**
     * @ORM\Column(name="po_number", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $poNumber = null;

    /**
     * @ORM\Column(name="memo", type="text", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $memo = null;

    /**
     * @ORM\Column(name="billing_address_first_name", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $billingAddressFirstName = null;

    /**
     * @ORM\Column(name="billing_address_last_name", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $billingAddressLastName = null;

    /**
     * @ORM\Column(name="billing_address_organization", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $billingAddressOrganization = null;

    /**
     * @ORM\Column(name="billing_address_phone", type="string", length=100, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $billingAddressPhone = null;

    /**
     * @ORM\Column(name="billing_address_street1", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $billingAddressStreet1 = null;

    /**
     * @ORM\Column(name="billing_address_street2", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $billingAddressStreet2 = null;

    /**
     * @ORM\Column(name="billing_address_city", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $billingAddressCity = null;

    /**
     * @ORM\Column(name="billing_address_state", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $billingAddressState = null;

    /**
     * @ORM\Column(name="billing_address_postal_code", type="string", length=50, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $billingAddressPostalCode = null;

    /**
     * @ORM\Column(name="billing_address_country", type="string", length=2, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $billingAddressCountry = null;

    /**
     * @ORM\Column(name="shipping_address_first_name", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $shippingAddressFirstName = null;

    /**
     * @ORM\Column(name="shipping_address_last_name", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $shippingAddressLastName = null;

    /**
     * @ORM\Column(name="shipping_address_organization", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $shippingAddressOrganization = null;

    /**
     * @ORM\Column(name="shipping_address_phone", type="string", length=100, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $shippingAddressPhone = null;

    /**
     * @ORM\Column(name="shipping_address_street1", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $shippingAddressStreet1 = null;

    /**
     * @ORM\Column(name="shipping_address_street2", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $shippingAddressStreet2 = null;

    /**
     * @ORM\Column(name="shipping_address_city", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $shippingAddressCity = null;

    /**
     * @ORM\Column(name="shipping_address_state", type="string", length=255, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $shippingAddressState = null;

    /**
     * @ORM\Column(name="shipping_address_postal_code", type="string", length=50, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $shippingAddressPostalCode = null;

    /**
     * @ORM\Column(name="shipping_address_country", type="string", length=2, nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected ?string $shippingAddressCountry = null;

    /**
     * @ORM\ManyToOne(targetEntity="Oro\Bundle\OrganizationBundle\Entity\Organization")
     * @ORM\JoinColumn(name="organization_id", referencedColumnName="id", onDelete="SET NULL", nullable=true)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true]])]
    protected ?Organization $organization = null;

    /**
     * @ORM\Column(name="status", type="string", length=32, nullable=false)
     */
    #[ConfigField(defaultValues: ['dataaudit' => ['auditable' => true],'email' => ['available_in_template' => true]])]
    protected string $status = self::STATUS_DRAFT;

    /**
     * Creates a new Invoice instance.
     */
    public function __construct()
    {
        $this->customer = null;
        $this->lineItems = new ArrayCollection();
        $this->invoicePayments = new ArrayCollection();
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
     * Returns the invoice no.
     *
     * @return string|null
     */
    public function getInvoiceNo(): ?string
    {
        return $this->invoiceNo;
    }

    /**
     * Sets the invoice no.
     *
     * @param string|null $invoiceNo
     * @return self
     */
    public function setInvoiceNo(?string $invoiceNo): self
    {
        $this->invoiceNo = $invoiceNo;
        return $this;
    }

    /**
     * Returns the customer.
     *
     * @return Customer|null
     */
    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    /**
     * Sets the customer.
     *
     * @param Customer|null $customer
     * @return self
     */
    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;
        return $this;
    }

    /**
     * Returns the order.
     *
     * @return Order|null
     */
    public function getOrder(): ?Order
    {
        return $this->order;
    }

    /**
     * Sets the order.
     *
     * @param Order|null $order
     * @return self
     */
    public function setOrder(?Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    /**
     * Returns the customer user.
     *
     * @return CustomerUser|null
     */
    public function getCustomerUser(): ?CustomerUser
    {
        return $this->customerUser;
    }

    /**
     * Sets the customer user.
     *
     * @param CustomerUser|null $customerUser
     * @return self
     */
    public function setCustomerUser(?CustomerUser $customerUser): self
    {
        $this->customerUser = $customerUser;
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
        $this->paymentMethod = $paymentMethod;
        return $this;
    }

    /**
     * Returns the payment status.
     *
     * @return string|null
     */
    public function getPaymentStatus(): ?string
    {
        return $this->paymentStatus;
    }

    /**
     * Sets the payment status.
     *
     * @param string|null $paymentStatus
     * @return self
     */
    public function setPaymentStatus(?string $paymentStatus): self
    {
        $this->paymentStatus = $paymentStatus;
        return $this;
    }

    /**
     * Returns the internal status.
     *
     * @return string|null
     */
    public function getInternalStatus(): ?string
    {
        return $this->internalStatus;
    }

    /**
     * Sets the internal status.
     *
     * @param string|null $internalStatus
     * @return self
     */
    public function setInternalStatus(?string $internalStatus): self
    {
        $this->internalStatus = $internalStatus;
        return $this;
    }

    /**
     * Returns the po number.
     *
     * @return string|null
     */
    public function getPoNumber(): ?string
    {
        return $this->poNumber;
    }

    /**
     * Sets the po number.
     *
     * @param string|null $poNumber
     * @return self
     */
    public function setPoNumber(?string $poNumber): self
    {
        $this->poNumber = $poNumber;
        return $this;
    }

    /**
     * Returns the memo.
     *
     * @return string|null
     */
    public function getMemo(): ?string
    {
        return $this->memo;
    }

    /**
     * Sets the memo.
     *
     * @param string|null $memo
     * @return self
     */
    public function setMemo(?string $memo): self
    {
        $this->memo = $memo;
        return $this;
    }

    /**
     * Returns the billing address first name.
     *
     * @return string|null
     */
    public function getBillingAddressFirstName(): ?string
    {
        return $this->billingAddressFirstName;
    }

    /**
     * Sets the billing address first name.
     *
     * @param string|null $value
     * @return self
     */
    public function setBillingAddressFirstName(?string $value): self
    {
        $this->billingAddressFirstName = $value;
        return $this;
    }

    /**
     * Returns the billing address last name.
     *
     * @return string|null
     */
    public function getBillingAddressLastName(): ?string
    {
        return $this->billingAddressLastName;
    }

    /**
     * Sets the billing address last name.
     *
     * @param string|null $value
     * @return self
     */
    public function setBillingAddressLastName(?string $value): self
    {
        $this->billingAddressLastName = $value;
        return $this;
    }

    /**
     * Returns the billing address organization.
     *
     * @return string|null
     */
    public function getBillingAddressOrganization(): ?string
    {
        return $this->billingAddressOrganization;
    }

    /**
     * Sets the billing address organization.
     *
     * @param string|null $value
     * @return self
     */
    public function setBillingAddressOrganization(?string $value): self
    {
        $this->billingAddressOrganization = $value;
        return $this;
    }

    /**
     * Returns the billing address phone.
     *
     * @return string|null
     */
    public function getBillingAddressPhone(): ?string
    {
        return $this->billingAddressPhone;
    }

    /**
     * Sets the billing address phone.
     *
     * @param string|null $value
     * @return self
     */
    public function setBillingAddressPhone(?string $value): self
    {
        $this->billingAddressPhone = $value;
        return $this;
    }

    /**
     * Full "First Last" name for the billing address, or null if neither is set.
     */
    public function getBillingAddressFullName(): ?string
    {
        $name = trim(sprintf('%s %s', (string) $this->billingAddressFirstName, (string) $this->billingAddressLastName));
        return $name !== '' ? $name : null;
    }

    /**
     * Returns the billing address street1.
     *
     * @return string|null
     */
    public function getBillingAddressStreet1(): ?string
    {
        return $this->billingAddressStreet1;
    }

    /**
     * Sets the billing address street1.
     *
     * @param string|null $value
     * @return self
     */
    public function setBillingAddressStreet1(?string $value): self
    {
        $this->billingAddressStreet1 = $value;
        return $this;
    }

    /**
     * Returns the billing address street2.
     *
     * @return string|null
     */
    public function getBillingAddressStreet2(): ?string
    {
        return $this->billingAddressStreet2;
    }

    /**
     * Sets the billing address street2.
     *
     * @param string|null $value
     * @return self
     */
    public function setBillingAddressStreet2(?string $value): self
    {
        $this->billingAddressStreet2 = $value;
        return $this;
    }

    /**
     * Returns the billing address city.
     *
     * @return string|null
     */
    public function getBillingAddressCity(): ?string
    {
        return $this->billingAddressCity;
    }

    /**
     * Sets the billing address city.
     *
     * @param string|null $value
     * @return self
     */
    public function setBillingAddressCity(?string $value): self
    {
        $this->billingAddressCity = $value;
        return $this;
    }

    /**
     * Returns the billing address state.
     *
     * @return string|null
     */
    public function getBillingAddressState(): ?string
    {
        return $this->billingAddressState;
    }

    /**
     * Sets the billing address state.
     *
     * @param string|null $value
     * @return self
     */
    public function setBillingAddressState(?string $value): self
    {
        $this->billingAddressState = $value;
        return $this;
    }

    /**
     * Returns the billing address postal code.
     *
     * @return string|null
     */
    public function getBillingAddressPostalCode(): ?string
    {
        return $this->billingAddressPostalCode;
    }

    /**
     * Sets the billing address postal code.
     *
     * @param string|null $value
     * @return self
     */
    public function setBillingAddressPostalCode(?string $value): self
    {
        $this->billingAddressPostalCode = $value;
        return $this;
    }

    /**
     * Returns the billing address country.
     *
     * @return string|null
     */
    public function getBillingAddressCountry(): ?string
    {
        return $this->billingAddressCountry;
    }

    /**
     * Sets the billing address country.
     *
     * @param string|null $value
     * @return self
     */
    public function setBillingAddressCountry(?string $value): self
    {
        $this->billingAddressCountry = $value;
        return $this;
    }

    /**
     * Returns the shipping address first name.
     *
     * @return string|null
     */
    public function getShippingAddressFirstName(): ?string
    {
        return $this->shippingAddressFirstName;
    }

    /**
     * Sets the shipping address first name.
     *
     * @param string|null $value
     * @return self
     */
    public function setShippingAddressFirstName(?string $value): self
    {
        $this->shippingAddressFirstName = $value;
        return $this;
    }

    /**
     * Returns the shipping address last name.
     *
     * @return string|null
     */
    public function getShippingAddressLastName(): ?string
    {
        return $this->shippingAddressLastName;
    }

    /**
     * Sets the shipping address last name.
     *
     * @param string|null $value
     * @return self
     */
    public function setShippingAddressLastName(?string $value): self
    {
        $this->shippingAddressLastName = $value;
        return $this;
    }

    /**
     * Returns the shipping address organization.
     *
     * @return string|null
     */
    public function getShippingAddressOrganization(): ?string
    {
        return $this->shippingAddressOrganization;
    }

    /**
     * Sets the shipping address organization.
     *
     * @param string|null $value
     * @return self
     */
    public function setShippingAddressOrganization(?string $value): self
    {
        $this->shippingAddressOrganization = $value;
        return $this;
    }

    /**
     * Returns the shipping address phone.
     *
     * @return string|null
     */
    public function getShippingAddressPhone(): ?string
    {
        return $this->shippingAddressPhone;
    }

    /**
     * Sets the shipping address phone.
     *
     * @param string|null $value
     * @return self
     */
    public function setShippingAddressPhone(?string $value): self
    {
        $this->shippingAddressPhone = $value;
        return $this;
    }

    /**
     * Full "First Last" name for the shipping address, or null if neither is set.
     */
    public function getShippingAddressFullName(): ?string
    {
        $name = trim(sprintf(
            '%s %s',
            (string) $this->shippingAddressFirstName,
            (string) $this->shippingAddressLastName
        ));
        return $name !== '' ? $name : null;
    }

    /**
     * Returns the shipping address street1.
     *
     * @return string|null
     */
    public function getShippingAddressStreet1(): ?string
    {
        return $this->shippingAddressStreet1;
    }

    /**
     * Sets the shipping address street1.
     *
     * @param string|null $value
     * @return self
     */
    public function setShippingAddressStreet1(?string $value): self
    {
        $this->shippingAddressStreet1 = $value;
        return $this;
    }

    /**
     * Returns the shipping address street2.
     *
     * @return string|null
     */
    public function getShippingAddressStreet2(): ?string
    {
        return $this->shippingAddressStreet2;
    }

    /**
     * Sets the shipping address street2.
     *
     * @param string|null $value
     * @return self
     */
    public function setShippingAddressStreet2(?string $value): self
    {
        $this->shippingAddressStreet2 = $value;
        return $this;
    }

    /**
     * Returns the shipping address city.
     *
     * @return string|null
     */
    public function getShippingAddressCity(): ?string
    {
        return $this->shippingAddressCity;
    }

    /**
     * Sets the shipping address city.
     *
     * @param string|null $value
     * @return self
     */
    public function setShippingAddressCity(?string $value): self
    {
        $this->shippingAddressCity = $value;
        return $this;
    }

    /**
     * Returns the shipping address state.
     *
     * @return string|null
     */
    public function getShippingAddressState(): ?string
    {
        return $this->shippingAddressState;
    }

    /**
     * Sets the shipping address state.
     *
     * @param string|null $value
     * @return self
     */
    public function setShippingAddressState(?string $value): self
    {
        $this->shippingAddressState = $value;
        return $this;
    }

    /**
     * Returns the shipping address postal code.
     *
     * @return string|null
     */
    public function getShippingAddressPostalCode(): ?string
    {
        return $this->shippingAddressPostalCode;
    }

    /**
     * Sets the shipping address postal code.
     *
     * @param string|null $value
     * @return self
     */
    public function setShippingAddressPostalCode(?string $value): self
    {
        $this->shippingAddressPostalCode = $value;
        return $this;
    }

    /**
     * Returns the shipping address country.
     *
     * @return string|null
     */
    public function getShippingAddressCountry(): ?string
    {
        return $this->shippingAddressCountry;
    }

    /**
     * Sets the shipping address country.
     *
     * @param string|null $value
     * @return self
     */
    public function setShippingAddressCountry(?string $value): self
    {
        $this->shippingAddressCountry = $value;
        return $this;
    }

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
     * Returns the issue date.
     *
     * @return DateTimeInterface|null
     */
    public function getIssueDate(): ?DateTimeInterface
    {
        return $this->issueDate;
    }

    /**
     * Sets the issue date.
     *
     * @param DateTimeInterface|null $issueDate
     * @return self
     */
    public function setIssueDate(?DateTimeInterface $issueDate): self
    {
        $this->issueDate = self::toMutableDate($issueDate);
        return $this;
    }

    /**
     * Returns the due date.
     *
     * @return DateTimeInterface|null
     */
    public function getDueDate(): ?DateTimeInterface
    {
        return $this->dueDate;
    }

    /**
     * Sets the due date.
     *
     * @param DateTimeInterface|null $dueDate
     * @return self
     */
    public function setDueDate(?DateTimeInterface $dueDate): self
    {
        $this->dueDate = self::toMutableDate($dueDate);
        return $this;
    }

    /**
     * Returns the posted at.
     *
     * @return \DateTime|null
     */
    public function getPostedAt(): ?\DateTime
    {
        return $this->postedAt;
    }

    /**
     * Sets the posted at.
     *
     * @param \DateTime|null $postedAt
     * @return self
     */
    public function setPostedAt(?\DateTime $postedAt): self
    {
        $this->postedAt = $postedAt;
        return $this;
    }

    /**
     * Returns the paid notification sent at.
     *
     * @return \DateTime|null
     */
    public function getPaidNotificationSentAt(): ?\DateTime
    {
        return $this->paidNotificationSentAt;
    }

    /**
     * Sets the paid notification sent at.
     *
     * @param \DateTime|null $paidNotificationSentAt
     * @return self
     */
    public function setPaidNotificationSentAt(?\DateTime $paidNotificationSentAt): self
    {
        $this->paidNotificationSentAt = $paidNotificationSentAt;

        return $this;
    }

    /**
     * Has the customer already been told, automatically, that this invoice
     * was paid in full?
     */
    public function isPaidNotificationSent(): bool
    {
        return $this->paidNotificationSentAt !== null;
    }

    /**
     * Returns the cancelled notification sent at.
     *
     * @return \DateTime|null
     */
    public function getCancelledNotificationSentAt(): ?\DateTime
    {
        return $this->cancelledNotificationSentAt;
    }

    /**
     * Sets the cancelled notification sent at.
     *
     * @param \DateTime|null $cancelledNotificationSentAt
     * @return self
     */
    public function setCancelledNotificationSentAt(?\DateTime $cancelledNotificationSentAt): self
    {
        $this->cancelledNotificationSentAt = $cancelledNotificationSentAt;

        return $this;
    }

    /**
     * Has the customer already been told, automatically, that this invoice
     * was cancelled because its order was cancelled?
     */
    public function isCancelledNotificationSent(): bool
    {
        return $this->cancelledNotificationSentAt !== null;
    }

    /**
     * Checks whether the posted applies.
     *
     * @return bool
     */
    public function isPosted(): bool
    {
        return $this->postedAt !== null;
    }

    /**
     * Checks whether the cancelled applies.
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Checks whether the cancellable applies.
     *
     * @return bool
     */
    public function isCancellable(): bool
    {
        return !$this->isCancelled();
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
     * Returns the shipping amount.
     *
     * @return float
     */
    public function getShippingAmount(): float
    {
        return $this->shippingAmount;
    }

    /**
     * Sets the shipping amount.
     *
     * @param float $shippingAmount
     * @return self
     */
    public function setShippingAmount(float $shippingAmount): self
    {
        $this->shippingAmount = $shippingAmount;
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
     * @param float $discountAmount
     * @return self
     */
    public function setDiscountAmount(float $discountAmount): self
    {
        $this->discountAmount = $discountAmount;
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
     * @param float $taxAmount
     * @return self
     */
    public function setTaxAmount(float $taxAmount): self
    {
        $this->taxAmount = $taxAmount;
        return $this;
    }

    /**
     * Returns the grand total.
     *
     * @return float
     */
    public function getGrandTotal(): float
    {
        return $this->grandTotal;
    }

    /**
     * Sets the grand total.
     *
     * @param float $grandTotal
     * @return self
     */
    public function setGrandTotal(float $grandTotal): self
    {
        $this->grandTotal = $grandTotal;
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
     * Returns the amount paid.
     *
     * @return float
     */
    public function getAmountPaid(): float
    {
        return $this->amountPaid;
    }

    /**
     * Sets the amount paid.
     *
     * @param float $amountPaid
     * @return self
     */
    public function setAmountPaid(float $amountPaid): self
    {
        $this->amountPaid = $amountPaid;
        return $this;
    }

    /**
     * Returns the balance.
     *
     * @return float
     */
    public function getBalance(): float
    {
        return BigDecimal::of($this->getAmount())
            ->minus($this->getAmountPaid())
            ->toFloat();
    }

    /**
     * Is this Invoice Balance Paid?
     * (Used to determine whether to update Status)
     */
    public function isBalancePaid(): bool
    {
        return ($this->getBalance() <= 0);
    }

    /**
     * Checks whether the line item is present.
     *
     * @param InvoiceLineItem $lineItem
     * @return bool
     */
    public function hasLineItem(InvoiceLineItem $lineItem): bool
    {
        return $this->lineItems->contains($lineItem);
    }

    /**
     * Adds the line item.
     *
     * @param InvoiceLineItem $lineItem
     * @return self
     */
    public function addLineItem(InvoiceLineItem $lineItem): self
    {
        if (!$this->hasLineItem($lineItem)) {
            $this->lineItems[] = $lineItem;
            $lineItem->setInvoice($this);
        }

        return $this;
    }

    /**
     * Removes the line item.
     *
     * @param InvoiceLineItem $lineItem
     * @return self
     */
    public function removeLineItem(InvoiceLineItem $lineItem): self
    {
        if ($this->hasLineItem($lineItem)) {
            $this->lineItems->removeElement($lineItem);
        }

        return $this;
    }

    /**
     * @param Collection<int, InvoiceLineItem> $lineItems
     * @return Invoice
     */
    public function setLineItems(Collection $lineItems): self
    {
        foreach ($lineItems as $lineItem) {
            $lineItem->setInvoice($this);
        }

        $this->lineItems = $lineItems;

        return $this;
    }

    /**
     * @return Collection<int, InvoiceLineItem>
     */
    public function getLineItems(): Collection
    {
        return $this->lineItems;
    }

    /**
     * @return array<string,string>
     */
    public static function getStatuses(): array
    {
        return self::STATUSES;
    }

    /**
     * Returns the status.
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Sets the status.
     *
     * @param string|null $status
     * @return self
     */
    public function setStatus(?string $status): self
    {
        $this->status = $status ?? $this->status;
        return $this;
    }

    /**
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function preSave(): void
    {
        $this->updatePrice();

        $now = new \DateTime('now');

        if (null === $this->createdAt) {
            $this->createdAt = $now;
        }

        $this->updatedAt = $now;
    }

    /**
     * Renders the billing address as an array of non-empty display lines,
     * e.g. ["John Smith", "ABC Manufacturing", "123 Main Street", "Suite 100",
     * "Los Angeles, CA 90001", "US", "+1 213-555-0123"].
     * Used by the invoice details page, the PDF, and the notification email
     * so all three stay in sync instead of re-implementing formatting three times.
     *
     * @return string[]
     */
    public function getBillingAddressLines(): array
    {
        return $this->formatAddressLines(
            $this->getBillingAddressFullName(),
            $this->billingAddressOrganization,
            $this->billingAddressStreet1,
            $this->billingAddressStreet2,
            $this->billingAddressCity,
            $this->billingAddressState,
            $this->billingAddressPostalCode,
            $this->billingAddressCountry,
            $this->billingAddressPhone
        );
    }

    /**
     * @return string[]
     */
    public function getShippingAddressLines(): array
    {
        return $this->formatAddressLines(
            $this->getShippingAddressFullName(),
            $this->shippingAddressOrganization,
            $this->shippingAddressStreet1,
            $this->shippingAddressStreet2,
            $this->shippingAddressCity,
            $this->shippingAddressState,
            $this->shippingAddressPostalCode,
            $this->shippingAddressCountry,
            $this->shippingAddressPhone
        );
    }

    /**
     * @return string[]
     */
    private function formatAddressLines(
        ?string $fullName,
        ?string $organization,
        ?string $street1,
        ?string $street2,
        ?string $city,
        ?string $state,
        ?string $postalCode,
        ?string $country,
        ?string $phone
    ): array {
        $lines = [];

        if ($fullName) {
            $lines[] = $fullName;
        }

        if ($organization) {
            $lines[] = $organization;
        }

        if ($street1) {
            $lines[] = $street1;
        }

        if ($street2) {
            $lines[] = $street2;
        }

        $cityLine = trim(sprintf(
            '%s%s %s',
            $city ?: '',
            ($city && $state) ? ',' : '',
            $state ?: ''
        ));
        $cityLine = trim($cityLine . ' ' . ($postalCode ?: ''));
        if ($cityLine !== '') {
            $lines[] = $cityLine;
        }

        if ($country) {
            $lines[] = $country;
        }

        if ($phone) {
            $lines[] = $phone;
        }

        return $lines;
    }

    /**
     * Converts an immutable date into the mutable DateTime the Doctrine date type expects.
     *
     * @param DateTimeInterface|null $date
     * @return \DateTime|null
     */
    private static function toMutableDate(?DateTimeInterface $date): ?\DateTime
    {
        return $date instanceof \DateTimeImmutable ? \DateTime::createFromImmutable($date) : $date;
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

    /**
     * Handles __clone.
     */
    public function __clone()
    {
        if ($this->id) {
            $this->id = null;
        }
    }

    /**
     * @return Collection<int, InvoicePayment>
     */
    public function getInvoicePayments(): Collection
    {
        return $this->invoicePayments;
    }

    /**
     * Adds the invoice payment.
     *
     * @param InvoicePayment $invoicePayment
     * @return self
     */
    public function addInvoicePayment(InvoicePayment $invoicePayment): self
    {
        if (!$this->invoicePayments->contains($invoicePayment)) {
            $this->invoicePayments[] = $invoicePayment;
            $invoicePayment->setInvoice($this);
        }
        return $this;
    }

    /**
     * Removes the invoice payment.
     *
     * @param InvoicePayment $invoicePayment
     * @return self
     */
    public function removeInvoicePayment(InvoicePayment $invoicePayment): self
    {
        if ($this->invoicePayments->contains($invoicePayment)) {
            $this->invoicePayments->removeElement($invoicePayment);
        }
        return $this;
    }

    /**
     * @return array<int, InvoicePayment>
     */
    public function getPendingConfirmationPayments(): array
    {
        return array_values($this->invoicePayments->filter(
            static fn (InvoicePayment $payment): bool => $payment->isActive() && $payment->isPendingConfirmation()
        )->toArray());
    }

    /**
     * Checks whether the pending payment confirmation is present.
     *
     * @return bool
     */
    public function hasPendingPaymentConfirmation(): bool
    {
        return [] !== $this->getPendingConfirmationPayments();
    }
}
