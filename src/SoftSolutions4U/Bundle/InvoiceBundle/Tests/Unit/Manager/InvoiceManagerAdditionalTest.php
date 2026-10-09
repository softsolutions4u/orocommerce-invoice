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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Manager;

use SoftSolutions4U\Bundle\InvoiceBundle\Builder\InvoiceBuilder;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceLineItemManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\OrderPaymentLedgerSynchronizer;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaidAmountProvider;
use Doctrine\ORM\EntityManagerInterface;
use Oro\Bundle\OrderBundle\Entity\Order;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for invoice deletion, creation guards and applying order payments.
 */

/**
 * Additional tests for createDraftFromOrder().
 * Appended as a separate class to keep the main test file focused.
 */
class InvoiceManagerAdditionalTest extends TestCase
{
    public function testCreateDraftFromOrderBuildsAndSaves(): void
    {
        $order = $this->order(10);
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');

        $builder = $this->createMock(InvoiceBuilder::class);
        $builder->expects(self::once())->method('build')->with($order)->willReturn($invoice);

        $lineItemManager = $this->createMock(InvoiceLineItemManager::class);

        $repository = $this->createMock(InvoiceRepository::class);
        $repository->method('findOneByOrderId')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::atLeastOnce())->method('persist')->with($invoice);
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $paidAmountProvider = $this->createMock(InvoicePaidAmountProvider::class);
        $ledgerSynchronizer = $this->createMock(OrderPaymentLedgerSynchronizer::class);
        $ledgerSynchronizer->method('recordMissingPayments')->willReturn(0.0);

        $manager = new InvoiceManager(
            $builder,
            $lineItemManager,
            $entityManager,
            $paidAmountProvider,
            $ledgerSynchronizer
        );

        $result = $manager->createDraftFromOrder($order);

        self::assertSame($invoice, $result);
    }

    /**
     * Forces the paid-amount provider to report a paid state for a draft that
     * does NOT get marked paid because recorded == 0.
     */
    public function testAlreadyPaidLedgerDoesNotReopenAPaidDraft(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setStatus(Invoice::STATUS_DRAFT);
        $invoice->setAmount(100.00);
        $invoice->setAmountPaid(100.00);

        $order = $this->order(55);
        $invoice->setOrder($order);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 9);

        $builder = $this->createMock(InvoiceBuilder::class);
        $lineItemManager = $this->createMock(InvoiceLineItemManager::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $paidAmountProvider = $this->createMock(InvoicePaidAmountProvider::class);
        $paidAmountProvider->method('getPaidAmount')->willReturn(100.00);
        $paidAmountProvider->method('applyTo')->willReturnCallback(
            static function (Invoice $inv): float {
                $inv->setAmountPaid(100.00);
                return 100.00;
            }
        );

        $ledgerSynchronizer = $this->createMock(OrderPaymentLedgerSynchronizer::class);
        $ledgerSynchronizer->method('recordMissingPayments')->willReturn(0.0);

        $manager = new InvoiceManager(
            $builder,
            $lineItemManager,
            $entityManager,
            $paidAmountProvider,
            $ledgerSynchronizer
        );

        self::assertFalse($manager->applyOrderPayments($invoice));
        // Draft stays a draft when nothing new was recorded
        self::assertSame(Invoice::STATUS_DRAFT, $invoice->getStatus());
    }

    private function order(int $id): Order
    {
        return new class ($id) extends Order {
            public function __construct(private int $stubId)
            {
            }

            public function getId(): ?int
            {
                return $this->stubId;
            }

            public function getInternalStatus(): mixed
            {
                return null;
            }
        };
    }
}
