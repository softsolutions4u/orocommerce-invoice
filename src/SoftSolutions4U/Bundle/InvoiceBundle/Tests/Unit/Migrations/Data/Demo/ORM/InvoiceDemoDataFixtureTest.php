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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Migrations\Data\Demo\ORM;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Migrations\Data\Demo\ORM\InvoiceDemoDataFixture;
use SoftSolutions4U\Bundle\InvoiceBundle\Model\InvoicePaymentStatus;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the demo invoice fixture.
 */
class InvoiceDemoDataFixtureTest extends TestCase
{
    /** @var array<int, Invoice> */
    private array $persisted = [];

    /**
     * Loads the invoices.
     *
     * @return array<int, Invoice>
     */
    private function loadInvoices(): array
    {
        if ($this->persisted) {
            return $this->persisted;
        }

        $organization = new Organization();
        $organization->setName('Demo Organization');

        $customerA = new Customer();
        $customerA->setName('Invoice Customer A');
        $customerA->setOrganization($organization);
        $customerB = new Customer();
        $customerB->setName('Invoice Customer B');
        $customerB->setOrganization($organization);

        $customerRepository = $this->createMock(ObjectRepository::class);
        $customerRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => $criteria['name'] === 'Invoice Customer A' ? $customerA : $customerB
        );

        $invoiceRepository = $this->createMock(ObjectRepository::class);
        $invoiceRepository->method('findOneBy')->willReturn(null);

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn (string $class) => $class === Customer::class ? $customerRepository : $invoiceRepository
        );
        $manager->method('persist')->willReturnCallback(
            function (object $entity): void {
                if ($entity instanceof Invoice) {
                    $this->persisted[] = $entity;
                }
            }
        );

        (new InvoiceDemoDataFixture())->load($manager);

        return $this->persisted;
    }

    /**
     * Tests that every invoice is owned by an organization.
     *
     * Oro's grids filter by organization, so an invoice without one is
     * written to the table but never shown in the UI.
     */
    public function testEveryInvoiceHasAnOrganization(): void
    {
        foreach ($this->loadInvoices() as $invoice) {
            self::assertNotNull(
                $invoice->getOrganization(),
                sprintf('Invoice %s has no organization and would be invisible', $invoice->getInvoiceNo())
            );
        }
    }

    /**
     * Tests that every invoice is linked to a customer.
     */
    public function testEveryInvoiceHasACustomer(): void
    {
        foreach ($this->loadInvoices() as $invoice) {
            self::assertNotNull($invoice->getCustomer());
        }
    }

    /**
     * Tests that the configured currency is applied to invoices and line items alike.
     */
    public function testCurrencyIsConsistent(): void
    {
        foreach ($this->loadInvoices() as $invoice) {
            $currency = $invoice->getCurrency();

            self::assertNotEmpty($currency);

            foreach ($invoice->getLineItems() as $lineItem) {
                self::assertSame(
                    $currency,
                    $lineItem->getCurrency(),
                    sprintf('Invoice %s has a line item in another currency', $invoice->getInvoiceNo())
                );
            }
        }
    }

    /**
     * Tests that the fixture creates one invoice per lifecycle stage.
     */
    public function testCreatesAnInvoiceForEachLifecycleStage(): void
    {
        $invoices = $this->loadInvoices();

        self::assertCount(6, $invoices);

        $statuses = array_map(static fn (Invoice $i) => $i->getStatus(), $invoices);

        self::assertContains(Invoice::STATUS_DRAFT, $statuses);
        self::assertContains(Invoice::STATUS_OPEN, $statuses);
        self::assertContains(Invoice::STATUS_OVERDUE, $statuses);
        self::assertContains(Invoice::STATUS_POSTED, $statuses);
    }

    /**
     * Tests that every invoice satisfies the totals rule.
     */
    public function testEveryInvoiceReconciles(): void
    {
        foreach ($this->loadInvoices() as $invoice) {
            $expected = round(
                $invoice->getSubtotal()
                - $invoice->getDiscountAmount()
                + $invoice->getTaxAmount()
                + $invoice->getShippingAmount(),
                2
            );

            self::assertEqualsWithDelta(
                $expected,
                $invoice->getGrandTotal(),
                0.005,
                sprintf('Invoice %s does not reconcile', $invoice->getInvoiceNo())
            );

            self::assertSame(
                $invoice->getGrandTotal(),
                $invoice->getAmount(),
                sprintf('Invoice %s: amount must mirror grand total', $invoice->getInvoiceNo())
            );
        }
    }

    /**
     * Tests that each invoice subtotal equals the sum of its line item subtotals.
     */
    public function testSubtotalMatchesTheLineItems(): void
    {
        foreach ($this->loadInvoices() as $invoice) {
            $lineSubtotal = 0.0;

            foreach ($invoice->getLineItems() as $lineItem) {
                $lineSubtotal += $lineItem->getSubtotal();

                self::assertNotNull($lineItem->getProductSku(), 'Demo line items need a SKU');
                self::assertNotNull($lineItem->getQuantity(), 'Demo line items need a quantity');
                self::assertNotNull($lineItem->getValue(), 'Demo line items need a unit price');
            }

            self::assertEqualsWithDelta(
                round($lineSubtotal, 2),
                $invoice->getSubtotal(),
                0.005,
                sprintf('Invoice %s subtotal does not match its line items', $invoice->getInvoiceNo())
            );
        }
    }

    /**
     * Tests that invoice numbers use the format the generator produces.
     */
    public function testInvoiceNumbersUseTheGeneratedFormat(): void
    {
        foreach ($this->loadInvoices() as $invoice) {
            self::assertMatchesRegularExpression(
                '/^INV-\d{4}-\d{2}-\d{5}$/',
                (string) $invoice->getInvoiceNo()
            );
        }
    }

    /**
     * Tests that the recorded payment status agrees with the amount paid.
     */
    public function testPaymentStatusAgreesWithAmountPaid(): void
    {
        foreach ($this->loadInvoices() as $invoice) {
            $paid = $invoice->getAmountPaid();
            $total = $invoice->getGrandTotal();

            $expected = match (true) {
                $paid <= 0.0 => InvoicePaymentStatus::PENDING,
                $paid >= $total - 0.01 => InvoicePaymentStatus::FULL,
                default => InvoicePaymentStatus::PARTIALLY,
            };

            self::assertSame(
                $expected,
                $invoice->getPaymentStatus(),
                sprintf('Invoice %s has an inconsistent payment status', $invoice->getInvoiceNo())
            );
        }
    }

    /**
     * Tests that no invoice is paid for more than it is worth.
     */
    public function testAmountPaidNeverExceedsTheTotal(): void
    {
        foreach ($this->loadInvoices() as $invoice) {
            self::assertLessThanOrEqual(
                $invoice->getGrandTotal(),
                $invoice->getAmountPaid(),
                sprintf('Invoice %s is overpaid', $invoice->getInvoiceNo())
            );
        }
    }

    /**
     * Tests that a draft invoice is never marked as posted.
     */
    public function testDraftInvoicesAreNotPosted(): void
    {
        foreach ($this->loadInvoices() as $invoice) {
            if (Invoice::STATUS_DRAFT === $invoice->getStatus()) {
                self::assertNull($invoice->getPostedAt(), 'A draft invoice must not have a posted date');
            }
        }
    }

    /**
     * Tests that the fixture skips invoices that already exist.
     */
    public function testSkipsInvoicesThatAlreadyExist(): void
    {
        $customer = new Customer();
        $customer->setName('Invoice Customer A');

        $customerRepository = $this->createMock(ObjectRepository::class);
        $customerRepository->method('findOneBy')->willReturn($customer);

        $invoiceRepository = $this->createMock(ObjectRepository::class);
        $invoiceRepository->method('findOneBy')->willReturn(new Invoice());

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn (string $class) => $class === Customer::class ? $customerRepository : $invoiceRepository
        );
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::never())->method('flush');

        (new InvoiceDemoDataFixture())->load($manager);
    }
}
