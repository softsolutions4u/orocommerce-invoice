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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Builder;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Generator\InvoiceNumberGenerator;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceLineItemManager;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderAddress;
use Oro\Bundle\PaymentTermBundle\Provider\PaymentTermProviderInterface;
use Oro\Bundle\TaxBundle\Provider\TaxProviderRegistry;
use Oro\Bundle\PaymentBundle\Manager\PaymentStatusManager;
use Oro\Bundle\PaymentBundle\Provider\PaymentTransactionProvider;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;

/**
 * Invoice builder.
 */
class InvoiceBuilder
{
    private const DEFAULT_DUE_DAYS = 30;

    /** @var InvoiceNumberGenerator $invoiceNumberGenerator */
    private InvoiceNumberGenerator $invoiceNumberGenerator;

    /** @var InvoiceLineItemManager $invoiceLineItemManager */
    private InvoiceLineItemManager $invoiceLineItemManager;

    /** @var TaxProviderRegistry $taxProviderRegistry */
    private TaxProviderRegistry $taxProviderRegistry;

    /** @var PaymentTransactionProvider $paymentTransactionProvider */
    private PaymentTransactionProvider $paymentTransactionProvider;

    /** @var PaymentStatusManager $paymentStatusManager */
    private PaymentStatusManager $paymentStatusManager;

    /** @var PaymentTermProviderInterface $paymentTermProvider */
    private PaymentTermProviderInterface $paymentTermProvider;

    /** @var ManagerRegistry|null $registry Reads the payment method without decrypting the transaction. */
    private ?ManagerRegistry $registry;

    /**
     * Creates a new InvoiceBuilder instance.
     *
     * @param InvoiceNumberGenerator $invoiceNumberGenerator
     * @param InvoiceLineItemManager $invoiceLineItemManager
     * @param TaxProviderRegistry $taxProviderRegistry
     * @param PaymentTransactionProvider $paymentTransactionProvider
     * @param PaymentStatusManager $paymentStatusManager
     * @param PaymentTermProviderInterface $paymentTermProvider
     * @param ManagerRegistry|null $registry
     */
    public function __construct(
        InvoiceNumberGenerator $invoiceNumberGenerator,
        InvoiceLineItemManager $invoiceLineItemManager,
        TaxProviderRegistry $taxProviderRegistry,
        PaymentTransactionProvider $paymentTransactionProvider,
        PaymentStatusManager $paymentStatusManager,
        PaymentTermProviderInterface $paymentTermProvider,
        ?ManagerRegistry $registry = null
    ) {
        $this->invoiceNumberGenerator = $invoiceNumberGenerator;
        $this->invoiceLineItemManager = $invoiceLineItemManager;
        $this->taxProviderRegistry = $taxProviderRegistry;
        $this->paymentTransactionProvider = $paymentTransactionProvider;
        $this->paymentStatusManager = $paymentStatusManager;
        $this->paymentTermProvider = $paymentTermProvider;
        $this->registry = $registry;
    }

    /**
     * Builds the bundle.
     *
     * @param Order $order
     * @return Invoice
     */
    public function build(Order $order): Invoice
    {
        $invoice = new Invoice();

        $invoice->setOrder($order);

        $orderOrganization = $order->getOrganization();
        if (null !== $orderOrganization) {
            $invoice->setOrganization($orderOrganization);
        }

        $invoice->setCustomer($order->getCustomer());

        $invoice->setCustomerUser($order->getCustomerUser());

        $invoice->setCurrency($order->getCurrency());

        $invoice->setInvoiceNo(
            $this->invoiceNumberGenerator->generate()
        );

        $invoice->setPoNumber($order->getPoNumber());

        $issueDate = new \DateTime();
        $invoice->setIssueDate($issueDate);

        $invoice->setDueDate(
            $this->resolveDueDate($order, $issueDate)
        );

        $invoice->setStatus(Invoice::STATUS_DRAFT);

        $invoice->setAmountPaid(0.00);

        $paymentMethod = $this->resolvePaymentMethod($order);
        if (null !== $paymentMethod) {
            $invoice->setPaymentMethod($paymentMethod);
        }

        // Oro may recalculate the status from the order's payment transactions. If
        // those cannot be read (e.g. encrypted with another application secret),
        // the invoice is still created; its payment status is refreshed later.
        try {
            $paymentStatusEntity = $this->paymentStatusManager->getPaymentStatus($order);
        } catch (\Throwable) {
            $paymentStatusEntity = null;
        }
        if (null !== $paymentStatusEntity) {
            $invoice->setPaymentStatus((string) $paymentStatusEntity->getPaymentStatus());
        }

        $this->copyBillingAddress($invoice, $order->getBillingAddress());
        $this->copyShippingAddress($invoice, $order->getShippingAddress());

        $taxResult = $this->taxProviderRegistry->getEnabledProvider()->getTax($order);
        $invoice->setShippingAmount((float) $taxResult->getShipping()->getExcludingTax());

        $this->invoiceLineItemManager->copyFromOrder($invoice, $order, $taxResult);

        $orderLevelDiscount = $this->invoiceLineItemManager->getOrderLevelDiscount($order);

        $this->invoiceLineItemManager->recalculateInvoiceTotals(
            $invoice,
            $taxResult->getShipping(),
            $orderLevelDiscount
        );

        if ($invoice->getPaymentStatus() === 'full') {
            $invoice->setAmountPaid($invoice->getAmount());
        }

        return $invoice;
    }

    /**
     * Returns the payment method of the order's latest payment transaction.
     *
     * Only the plain "payment_method" column is read. Loading the whole
     * PaymentTransaction entity would also decrypt its secure fields, which
     * fails for transactions encrypted with a different application secret
     * (for example after a database was copied between installations).
     *
     * @param Order $order
     * @return string|null
     */
    private function resolvePaymentMethod(Order $order): ?string
    {
        if (null === $this->registry) {
            $transaction = $this->paymentTransactionProvider->getPaymentTransaction($order);

            return null !== $transaction ? (string) $transaction->getPaymentMethod() : null;
        }

        if (null === $order->getId()) {
            return null;
        }

        $rows = $this->registry->getManagerForClass(PaymentTransaction::class)
            ->createQueryBuilder()
            ->select('transaction.paymentMethod')
            ->from(PaymentTransaction::class, 'transaction')
            ->where('transaction.entityClass = :entityClass')
            ->andWhere('transaction.entityIdentifier = :entityIdentifier')
            ->setParameter('entityClass', Order::class)
            ->setParameter('entityIdentifier', (int) $order->getId())
            ->orderBy('transaction.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult();

        $paymentMethod = $rows[0]['paymentMethod'] ?? null;

        return null !== $paymentMethod && '' !== $paymentMethod ? (string) $paymentMethod : null;
    }

    /**
     * Resolves the due date.
     *
     * @param Order $order
     * @param \DateTime $issueDate
     * @return \DateTime
     */
    private function resolveDueDate(Order $order, \DateTime $issueDate): \DateTime
    {
        $days = self::DEFAULT_DUE_DAYS;

        $customer = $order->getCustomer();
        $paymentTerm = null !== $customer ? $this->paymentTermProvider->getPaymentTerm($customer) : null;
        if (null !== $paymentTerm) {
            $label = (string) $paymentTerm->getLabel();
            if (preg_match('/(\d+)/', $label, $matches) === 1) {
                $days = (int) $matches[1];
            }
        }

        return (clone $issueDate)->modify(sprintf('+%d days', $days));
    }

    /**
     * Copies the full contact + street-level details from the Order's billing OrderAddress onto the.
     *
     * @param Invoice $invoice
     * @param OrderAddress|null $address
     */
    private function copyBillingAddress(Invoice $invoice, ?OrderAddress $address): void
    {
        if (null === $address) {
            return;
        }

        $invoice->setBillingAddressFirstName($address->getFirstName());
        $invoice->setBillingAddressLastName($address->getLastName());
        $invoice->setBillingAddressOrganization($address->getOrganization());
        $invoice->setBillingAddressPhone($address->getPhone());
        $invoice->setBillingAddressStreet1($address->getStreet());
        $invoice->setBillingAddressStreet2($address->getStreet2());
        $invoice->setBillingAddressCity($address->getCity());
        $invoice->setBillingAddressState(
            $address->getRegion()?->getName() ?? $address->getRegionText()
        );
        $invoice->setBillingAddressPostalCode($address->getPostalCode());
        $invoice->setBillingAddressCountry($address->getCountry()?->getIso2Code());
    }

    /**
     * Copies the full contact + street-level details from the Order's shipping OrderAddress onto the.
     *
     * @param Invoice $invoice
     * @param OrderAddress|null $address
     */
    private function copyShippingAddress(Invoice $invoice, ?OrderAddress $address): void
    {
        if (null === $address) {
            return;
        }

        $invoice->setShippingAddressFirstName($address->getFirstName());
        $invoice->setShippingAddressLastName($address->getLastName());
        $invoice->setShippingAddressOrganization($address->getOrganization());
        $invoice->setShippingAddressPhone($address->getPhone());
        $invoice->setShippingAddressStreet1($address->getStreet());
        $invoice->setShippingAddressStreet2($address->getStreet2());
        $invoice->setShippingAddressCity($address->getCity());
        $invoice->setShippingAddressState(
            $address->getRegion()?->getName() ?? $address->getRegionText()
        );
        $invoice->setShippingAddressPostalCode($address->getPostalCode());
        $invoice->setShippingAddressCountry($address->getCountry()?->getIso2Code());
    }
}
