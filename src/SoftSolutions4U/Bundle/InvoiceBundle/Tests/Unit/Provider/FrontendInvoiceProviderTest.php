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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Provider;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\FrontendInvoiceProvider;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\FormBundle\Model\UpdateFactory;
use Oro\Bundle\FormBundle\Model\UpdateInterface;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;

/**
 * Unit tests for the storefront invoice provider.
 */
class FrontendInvoiceProviderTest extends TestCase
{
    private ManagerRegistry&MockObject $registry;
    private AclHelper&MockObject $aclHelper;
    private UpdateFactory&MockObject $updateFactory;
    private InvoiceRepository&MockObject $repository;

    /** @var FrontendInvoiceProvider $provider */
    private FrontendInvoiceProvider $provider;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->aclHelper = $this->createMock(AclHelper::class);
        $this->updateFactory = $this->createMock(UpdateFactory::class);
        $this->repository = $this->createMock(InvoiceRepository::class);

        $this->registry
            ->method('getRepository')
            ->with(Invoice::class)
            ->willReturn($this->repository);

        $this->provider = new FrontendInvoiceProvider(
            $this->registry,
            $this->aclHelper,
            $this->updateFactory
        );
    }

    /**
     * Tests that unpaid invoices are restricted to payable statuses.
     */
    public function testUnpaidInvoicesAreRestrictedToPayableStatuses(): void
    {
        $expectedInvoices = [new Invoice(), new Invoice()];

        $this->repository
            ->expects(self::once())
            ->method('getCurrentCustomerInvoices')
            ->with($this->aclHelper, [], Invoice::UNPAID_STATUSES)
            ->willReturn($expectedInvoices);

        self::assertSame($expectedInvoices, $this->provider->getCurrentCustomerUnpaidInvoices());
    }

    /**
     * Tests that unpaid invoices honour the provided id filter.
     */
    public function testUnpaidInvoicesPassTheInvoiceIdFilter(): void
    {
        $invoiceIds = [1, 2, 3];

        $this->repository
            ->expects(self::once())
            ->method('getCurrentCustomerInvoices')
            ->with($this->aclHelper, $invoiceIds, Invoice::UNPAID_STATUSES)
            ->willReturn([]);

        self::assertSame([], $this->provider->getCurrentCustomerUnpaidInvoices($invoiceIds));
    }

    /**
     * Tests that all invoices are returned with no status filter.
     */
    public function testAllInvoicesUseNoStatusFilter(): void
    {
        $expectedInvoices = [new Invoice(), new Invoice()];

        $this->repository
            ->expects(self::once())
            ->method('getCurrentCustomerInvoices')
            ->with($this->aclHelper, [])
            ->willReturn($expectedInvoices);

        self::assertSame($expectedInvoices, $this->provider->getCurrentCustomerInvoices());
    }

    /**
     * Tests that all invoices honour the provided id filter.
     */
    public function testAllInvoicesPassTheInvoiceIdFilter(): void
    {
        $invoiceIds = [7, 8];

        $this->repository
            ->expects(self::once())
            ->method('getCurrentCustomerInvoices')
            ->with($this->aclHelper, $invoiceIds)
            ->willReturn([]);

        self::assertSame([], $this->provider->getCurrentCustomerInvoices($invoiceIds));
    }

    /**
     * Tests that the update factory is called with the payment and form.
     */
    public function testCreateInvoicePaymentFormUpdateDelegatesToTheFactory(): void
    {
        $invoicePayment = new InvoicePayment();
        $form = $this->createMock(FormInterface::class);
        $expected = $this->createMock(UpdateInterface::class);

        $this->updateFactory
            ->expects(self::once())
            ->method('createUpdate')
            ->with($invoicePayment, $form, null, null)
            ->willReturn($expected);

        self::assertSame(
            $expected,
            $this->provider->createInvoicePaymentFormUpdate($invoicePayment, $form)
        );
    }
}
