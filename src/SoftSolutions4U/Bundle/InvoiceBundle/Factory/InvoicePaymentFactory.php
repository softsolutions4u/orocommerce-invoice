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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Factory;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessor;

/**
 * Invoice payment factory.
 */
class InvoicePaymentFactory
{
    /** @var TokenAccessor $tokenAccessor */
    protected TokenAccessor $tokenAccessor;

    /** @var ManagerRegistry $registry */
    protected ManagerRegistry $registry;

    /**
     * Creates a new InvoicePaymentFactory instance.
     *
     * @param TokenAccessor $tokenAccessor
     * @param ManagerRegistry $registry
     */
    public function __construct(
        TokenAccessor $tokenAccessor,
        ManagerRegistry $registry,
    ) {
        $this->tokenAccessor = $tokenAccessor;
        $this->registry = $registry;
    }

    /**
     * Creates an invoice payment covering a single invoice.
     *
     * @param Invoice $invoice
     * @param bool $persist
     * @return InvoicePayment
     */
    public function createFromInvoice(Invoice $invoice, bool $persist = false): InvoicePayment
    {
        if ($persist) {
            $reusable = $this->findReusablePayment($invoice);
            if ($reusable instanceof InvoicePayment) {
                return $reusable;
            }
        }

        return $this->create([$invoice], $persist);
    }

    /**
     * Returns the current customer user's unsubmitted payment for the invoice, so repeated visits reuse one record.
     *
     * @param Invoice $invoice
     * @return InvoicePayment|null
     */
    public function findReusablePayment(Invoice $invoice): ?InvoicePayment
    {
        $customerUser = $this->getCustomerUser();
        if (!$customerUser) {
            return null;
        }

        foreach ($invoice->getInvoicePayments() as $payment) {
            if (
                $payment->isActive()
                && !$payment->isPendingConfirmation()
                && '' === (string) $payment->getPaymentMethod()
                && $this->isSameCustomerUser($payment->getCustomerUser(), $customerUser)
            ) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * Creates the given data.
     *
     * @param array<int, Invoice> $invoices
     * @param bool $persist
     * @return InvoicePayment
     */
    public function create(array $invoices, bool $persist = false): InvoicePayment
    {
        $invoicePayment = new InvoicePayment();

        $customerUser = $this->getCustomerUser();
        if (!$customerUser) {
            throw new \LogicException('softsolutions4u.invoice.frontend.payment.errors.no_customer_user');
        }

        $invoicePayment
            ->setCustomerUser($customerUser)
            ->setCustomer($customerUser->getCustomer())
            ->setActive(true)
            // NOTE: This column is non-nullable; Oro payment bundle expects a string identifier
            ->setPaymentMethod('')
        ;

        $this->setInvoices($invoicePayment, $invoices);

        $onlyInvoice = count($invoices) === 1 ? reset($invoices) : null;

        if ($onlyInvoice instanceof Invoice) {
            $invoicePayment->setInvoice($onlyInvoice);
        }

        $firstInvoice = $onlyInvoice ?? (reset($invoices) ?: null);
        if ($firstInvoice instanceof Invoice && $firstInvoice->getOrganization()) {
            $invoicePayment->setOrganization($firstInvoice->getOrganization());
        }

        if ($persist) {
            $em = $this->registry->getManagerForClass(InvoicePayment::class);

            $em->persist($invoicePayment);
            $em->flush();

            $this->ensureCustomerIsSet($invoicePayment);
        }

        return $invoicePayment;
    }

    /**
     * Repairs an invoice payment that was persisted without its customer owner.
     *
     * @param InvoicePayment $invoicePayment
     * @return bool
     */
    public function ensureCustomerIsSet(InvoicePayment $invoicePayment): bool
    {
        $needsRepair = !$invoicePayment->getCustomer()
            || !$invoicePayment->getCustomerUser()
            || !$invoicePayment->getOrganization();

        if (!$needsRepair) {
            return false;
        }

        $customerUser = $this->getCustomerUser();
        if (!$customerUser) {
            return false;
        }

        if (!$invoicePayment->getCustomerUser()) {
            $invoicePayment->setCustomerUser($customerUser);
        }
        if (!$invoicePayment->getCustomer()) {
            $invoicePayment->setCustomer($customerUser->getCustomer());
        }

        if (!$invoicePayment->getCustomer()) {
            return false;
        }

        if (!$invoicePayment->getOrganization()) {
            $lineItem = $invoicePayment->getLineItems()->first();
            $invoice = $lineItem ? $lineItem->getInvoice() : null;

            if ($invoice instanceof Invoice && $invoice->getOrganization()) {
                $invoicePayment->setOrganization($invoice->getOrganization());
            }
        }

        $em = $this->registry->getManagerForClass(InvoicePayment::class);
        $em->persist($invoicePayment);
        $em->flush();

        return true;
    }

    /**
     * Sets the invoices.
     *
     * @param InvoicePayment $invoicePayment
     * @param array $invoices
     * @return InvoicePayment
     * @throws \Exception
     */
    public function setInvoices(InvoicePayment $invoicePayment, array $invoices): InvoicePayment
    {
        $this->validateInvoiceCurrencies($invoices);

        // Empty out the existing Line Items
        $invoicePayment->setLineItems(new ArrayCollection());

        foreach ($invoices as $invoice) {
            $this->createInvoicePaymentLineItem($invoice, $invoicePayment);
        }

        return $invoicePayment;
    }

    /**
     * Creates an InvoicePaymentLineItem from an Invoice with an Optional amount, and adds to the.
     *
     * @param Invoice $invoice
     * @param InvoicePayment $invoicePayment
     * @param int|float|null $amount
     * @return InvoicePaymentLineItem
     */
    public function createInvoicePaymentLineItem(
        Invoice $invoice,
        InvoicePayment $invoicePayment,
        int|float|null $amount = null,
    ): InvoicePaymentLineItem {
        $paymentLineItem = new InvoicePaymentLineItem();

        /** If Amount specified, use this. */
        $amount = !is_null($amount) ? $amount : $invoice->getBalance();

        $paymentLineItem
            ->setInvoice($invoice)
            ->setInvoicePayment($invoicePayment)
            ->setAmount($amount)
            ->setCurrency($invoice->getCurrency())
        ;

        // Add Line Item Amount to Payment Total
        $invoicePayment->addLineItem($paymentLineItem);

        return $paymentLineItem;
    }

    /**
     * Validates the invoice currencies.
     *
     * @param array $invoices
     * @throws \Exception
     */
    public function validateInvoiceCurrencies(array $invoices): void
    {
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions
        $currencies = array_unique(array_map(function (Invoice $invoice) {
            return $invoice->getCurrency();
        }, $invoices));

        if (count($currencies) > 1) {
            throw new \LogicException('softsolutions4u.invoice.frontend.payment.errors.multi_currency');
        }
    }

    /**
     * Returns whether both values are the same customer user.
     *
     * @param CustomerUser|null $owner
     * @param CustomerUser $current
     * @return bool
     */
    private function isSameCustomerUser(?CustomerUser $owner, CustomerUser $current): bool
    {
        if ($owner === $current) {
            return true;
        }

        return null !== $owner && null !== $owner->getId() && $owner->getId() === $current->getId();
    }

    /**
     * Returns the logged-in customer user, or null for other users.
     *
     * @return CustomerUser|null
     */
    private function getCustomerUser(): ?CustomerUser
    {
        $user = $this->tokenAccessor->getUser();

        return $user instanceof CustomerUser
            ? $user
            : null;
    }
}
