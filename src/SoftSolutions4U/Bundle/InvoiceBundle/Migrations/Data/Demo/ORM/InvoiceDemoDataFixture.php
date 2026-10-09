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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Data\Demo\ORM;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoiceLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\Model\InvoicePaymentStatus;
use DateTime;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\MigrationBundle\Fixture\VersionedFixtureInterface;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\OrganizationBundle\Entity\OrganizationInterface;

/**
 * Loads a demo invoice for each stage of the invoice lifecycle.
 */
class InvoiceDemoDataFixture implements FixtureInterface, VersionedFixtureInterface
{
    private const CURRENCY = 'USD';
    private const TAX_RATE = 0.10;
    private const PAYMENT_TERM_DAYS = 30;

    /**
     * Returns the fixture version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return '1.0';
    }

    /**
     * Loads the fixture data.
     *
     * @param ObjectManager $manager
     */
    public function load(ObjectManager $manager): void
    {
        $customerA = $this->findCustomerByName($manager, 'Invoice Customer A');
        $customerB = $this->findCustomerByName($manager, 'Invoice Customer B');

        if (!$customerA || !$customerB) {
            [$first, $second] = $this->findFirstTwoCustomers($manager);
            $customerA ??= $first;
            $customerB ??= $second;
        }

        if (!$customerA || !$customerB) {
            return;
        }

        $created = 0;

        foreach ($this->getInvoiceSpecs() as $spec) {
            $customer = $spec['customer'] === 'a' ? $customerA : $customerB;

            if ($this->createInvoiceIfMissing($manager, $spec, $customer)) {
                $created++;
            }
        }

        if ($created > 0) {
            $manager->flush();
        }
    }

    /**
     * Returns the invoice specs.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getInvoiceSpecs(): array
    {
        return [
            [
                'sequence' => 1,
                'customer' => 'a',
                'issuedDaysAgo' => 62,
                'status' => Invoice::STATUS_POSTED,
                'paymentStatus' => InvoicePaymentStatus::FULL,
                'paidRatio' => 1.0,
                'poNumber' => 'PO-48213',
                'memo' => 'Paid in full by EFT. Remittance received.',
                'shippingAmount' => 18.18,
                'lineItems' => [
                    ['4CX789', 'Heavy Duty Steel Shelving Unit 72in', 4, 249.00, 'each', 0.00],
                    ['9GG002', 'Galvanized Storage Bin 30L', 12, 18.50, 'each', 0.00],
                ],
            ],
            [
                'sequence' => 2,
                'customer' => 'b',
                'issuedDaysAgo' => 54,
                'status' => Invoice::STATUS_OVERDUE,
                'paymentStatus' => InvoicePaymentStatus::PARTIALLY,
                'paidRatio' => 0.4,
                'poNumber' => 'PO-48377',
                'memo' => 'Part payment received. Balance overdue.',
                'shippingAmount' => 24.55,
                'lineItems' => [
                    ['5TG101', 'Industrial Workbench 96 x 30in', 2, 685.00, 'each', 50.00],
                    ['1HH334', 'Anti-Fatigue Floor Mat 36 x 60in', 6, 76.40, 'each', 0.00],
                    ['2KM887', 'Cable Management Tray 10ft', 10, 42.75, 'each', 0.00],
                ],
            ],
            [
                'sequence' => 3,
                'customer' => 'a',
                'issuedDaysAgo' => 41,
                'status' => Invoice::STATUS_OVERDUE,
                'paymentStatus' => InvoicePaymentStatus::PENDING,
                'paidRatio' => 0.0,
                'poNumber' => 'PO-48512',
                'memo' => 'Second reminder sent. No payment received to date.',
                'shippingAmount' => 0.00,
                'lineItems' => [
                    ['7BC410', 'Safety Helmet - White (Carton of 10)', 3, 189.00, 'carton', 0.00],
                    ['7BC418', 'Hi-Vis Safety Vest - Large (Carton of 25)', 2, 212.50, 'carton', 25.00],
                ],
            ],
            [
                'sequence' => 4,
                'customer' => 'b',
                'issuedDaysAgo' => 21,
                'status' => Invoice::STATUS_OPEN,
                'paymentStatus' => InvoicePaymentStatus::PENDING,
                'paidRatio' => 0.0,
                'poNumber' => 'PO-48690',
                'memo' => 'Payment due within terms.',
                'shippingAmount' => 32.73,
                'lineItems' => [
                    ['3PL220', 'Pallet Racking Beam 108in', 16, 94.20, 'each', 0.00],
                    ['3PL221', 'Pallet Racking Upright Frame 156in', 4, 318.00, 'each', 120.00],
                ],
            ],
            [
                'sequence' => 5,
                'customer' => 'a',
                'issuedDaysAgo' => 6,
                'status' => Invoice::STATUS_OPEN,
                'paymentStatus' => InvoicePaymentStatus::PENDING,
                'paidRatio' => 0.0,
                'poNumber' => 'PO-48804',
                'memo' => 'Issued on 30 day terms.',
                'shippingAmount' => 14.09,
                'lineItems' => [
                    ['8DR045', 'Lockable Tool Cabinet 7 Drawer', 1, 1249.00, 'each', 0.00],
                    ['8DR050', 'Socket Set 94 Piece SAE/Metric', 3, 168.90, 'each', 0.00],
                ],
            ],
            [
                'sequence' => 6,
                'customer' => 'b',
                'issuedDaysAgo' => 1,
                'status' => Invoice::STATUS_DRAFT,
                'paymentStatus' => InvoicePaymentStatus::PENDING,
                'paidRatio' => 0.0,
                'poNumber' => 'PO-48851',
                'memo' => 'Draft pending internal approval. Not yet issued to customer.',
                'shippingAmount' => 0.00,
                'lineItems' => [
                    ['6WS310', 'Warehouse Step Ladder 8 Tread', 2, 429.00, 'each', 0.00],
                ],
            ],
        ];
    }

    /**
     * Creates the invoice if missing.
     *
     * @param array<string, mixed> $spec
     * @param ObjectManager $manager
     * @param Customer $customer
     * @return bool
     */
    private function createInvoiceIfMissing(ObjectManager $manager, array $spec, Customer $customer): bool
    {
        $issueDate = new DateTime(sprintf('today -%d days', $spec['issuedDaysAgo']));
        $invoiceNo = sprintf('INV-%s-%05d', $issueDate->format('Y-m'), $spec['sequence']);

        if (null !== $manager->getRepository(Invoice::class)->findOneBy(['invoiceNo' => $invoiceNo])) {
            return false;
        }

        $dueDate = (clone $issueDate)->modify(sprintf('+%d days', self::PAYMENT_TERM_DAYS));

        $invoice = new Invoice();
        $invoice->setInvoiceNo($invoiceNo);
        $invoice->setCustomer($customer);
        $invoice->setCurrency(self::CURRENCY);
        $invoice->setIssueDate($issueDate);
        $invoice->setDueDate($dueDate);
        $invoice->setStatus($spec['status']);
        $invoice->setPoNumber($spec['poNumber']);
        $invoice->setMemo($spec['memo']);
        $invoice->setCreatedAt(clone $issueDate);
        $invoice->setUpdatedAt(clone $issueDate);

        $organization = $this->findOrganization($manager, $customer);

        if (null !== $organization) {
            $invoice->setOrganization($organization);
        }

        if (Invoice::STATUS_DRAFT !== $spec['status']) {
            $invoice->setPostedAt(clone $issueDate);
        }

        $this->applyAddresses($invoice, $customer);

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $sortOrder = 0;

        foreach ($spec['lineItems'] as $row) {
            [$sku, $name, $quantity, $unitPrice, $unitCode, $lineDiscount] = $row;

            $lineSubtotal = round($quantity * $unitPrice, 2);
            $lineTax = round(($lineSubtotal - $lineDiscount) * self::TAX_RATE, 2);
            $lineTotal = round($lineSubtotal - $lineDiscount + $lineTax, 2);

            $lineItem = new InvoiceLineItem();
            $lineItem->setProductSku($sku);
            $lineItem->setProductName($name);
            $lineItem->setSummary($name);
            $lineItem->setQuantity((float) $quantity);
            $lineItem->setProductUnitCode($unitCode);
            $lineItem->setCurrency(self::CURRENCY);
            $lineItem->setValue($unitPrice);
            $lineItem->setSubtotal($lineSubtotal);
            $lineItem->setDiscountAmount($lineDiscount);
            $lineItem->setTaxAmount($lineTax);
            $lineItem->setTotal($lineTotal);
            $lineItem->setSortOrder($sortOrder++);

            $invoice->addLineItem($lineItem);

            $subtotal += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxTotal += $lineTax;
        }

        $shippingAmount = (float) $spec['shippingAmount'];
        $taxTotal = round($taxTotal + ($shippingAmount * self::TAX_RATE), 2);
        $subtotal = round($subtotal, 2);
        $discountTotal = round($discountTotal, 2);
        $grandTotal = round($subtotal - $discountTotal + $taxTotal + $shippingAmount, 2);

        $invoice->setSubtotal($subtotal);
        $invoice->setDiscountAmount($discountTotal);
        $invoice->setTaxAmount($taxTotal);
        $invoice->setShippingAmount($shippingAmount);
        $invoice->setGrandTotal($grandTotal);
        $invoice->setAmount($grandTotal);
        $invoice->setAmountPaid(round($grandTotal * (float) $spec['paidRatio'], 2));
        $invoice->setPaymentStatus($spec['paymentStatus']);

        $manager->persist($invoice);

        return true;
    }

    /**
     * Applies the billing and shipping address details to the invoice.
     *
     * @param Invoice $invoice
     * @param Customer $customer
     */
    private function applyAddresses(Invoice $invoice, Customer $customer): void
    {
        $organization = (string) $customer->getName();

        $invoice->setBillingAddressFirstName('Alex');
        $invoice->setBillingAddressLastName('Whitfield');
        $invoice->setBillingAddressOrganization($organization);
        $invoice->setBillingAddressPhone('+1 312 555 0142');
        $invoice->setBillingAddressStreet1('100 Example Street');
        $invoice->setBillingAddressCity('Springfield');
        $invoice->setBillingAddressState('IL');
        $invoice->setBillingAddressPostalCode('62701');
        $invoice->setBillingAddressCountry('US');

        $invoice->setShippingAddressFirstName('Dana');
        $invoice->setShippingAddressLastName('Okonkwo');
        $invoice->setShippingAddressOrganization($organization);
        $invoice->setShippingAddressPhone('+1 312 555 0187');
        $invoice->setShippingAddressStreet1('200 Sample Avenue');
        $invoice->setShippingAddressStreet2('Loading Dock B');
        $invoice->setShippingAddressCity('Springfield');
        $invoice->setShippingAddressState('IL');
        $invoice->setShippingAddressPostalCode('62702');
        $invoice->setShippingAddressCountry('US');
    }

    /**
     * Returns the organization that should own the demo invoices.
     *
     * @param ObjectManager $manager
     * @param Customer $customer
     * @return OrganizationInterface|null
     */
    private function findOrganization(ObjectManager $manager, Customer $customer): ?OrganizationInterface
    {
        return $customer->getOrganization()
            ?? $manager->getRepository(Organization::class)->findOneBy([], ['id' => 'ASC']);
    }

    /**
     * Finds a customer by name.
     *
     * @param ObjectManager $manager
     * @param string $name
     * @return Customer|null
     */
    private function findCustomerByName(ObjectManager $manager, string $name): ?Customer
    {
        return $manager->getRepository(Customer::class)->findOneBy(['name' => $name]);
    }

    /**
     * Finds the first two customers.
     *
     * @param ObjectManager $manager
     * @return array{0:?Customer,1:?Customer}
     */
    private function findFirstTwoCustomers(ObjectManager $manager): array
    {
        /** @var array<int, Customer> $all */
        $all = $manager->getRepository(Customer::class)->findBy([], ['id' => 'ASC'], 2);

        return [$all[0] ?? null, $all[1] ?? null];
    }
}
