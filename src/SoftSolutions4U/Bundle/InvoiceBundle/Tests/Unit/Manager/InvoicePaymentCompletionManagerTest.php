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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceEmailManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoicePaymentCompletionManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\OrderPaymentStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers the post-payment hook.
 */
class InvoicePaymentCompletionManagerTest extends TestCase
{
    private OrderPaymentStatusUpdater&MockObject $orderPaymentStatusUpdater;
    private InvoiceEmailManager&MockObject $invoiceEmailManager;
    private EntityManagerInterface&MockObject $entityManager;

    /** @var InvoicePaymentCompletionManager $manager */
    private InvoicePaymentCompletionManager $manager;

    /**
     * Sets up the fixtures used by the test cases.
     */
    protected function setUp(): void
    {
        $this->orderPaymentStatusUpdater = $this->createMock(OrderPaymentStatusUpdater::class);
        $this->invoiceEmailManager = $this->createMock(InvoiceEmailManager::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->manager = new InvoicePaymentCompletionManager(
            $this->orderPaymentStatusUpdater,
            $this->invoiceEmailManager,
            $registry,
            new NullLogger()
        );
    }

    /**
     * The whole point of issue 1: the Order's stored payment status has to be refreshed on EVERY.
     */
    public function testOrderPaymentStatusIsAlwaysRefreshed(): void
    {
        $invoice = $this->createInvoice(amount: 100.00, amountPaid: 25.00);

        $this->orderPaymentStatusUpdater->expects($this->once())
            ->method('updateForInvoice')
            ->with($invoice);

        $this->manager->onPaymentApplied($invoice);
    }

    /**
     * Tests that no confirmation while a balance remains.
     */
    public function testNoConfirmationWhileABalanceRemains(): void
    {
        $invoice = $this->createInvoice(amount: 100.00, amountPaid: 99.99);

        $this->invoiceEmailManager->expects($this->never())
            ->method('sendPaymentConfirmation');

        $this->manager->onPaymentApplied($invoice);

        $this->assertNull(
            $invoice->getPaidNotificationSentAt(),
            'A part-paid invoice must not be marked as notified.'
        );
    }

    /**
     * Tests that confirmation is sent and stamped when the balance clears.
     */
    public function testConfirmationIsSentAndStampedWhenTheBalanceClears(): void
    {
        $invoice = $this->createInvoice(amount: 100.00, amountPaid: 100.00);

        $this->invoiceEmailManager->expects($this->once())
            ->method('sendPaymentConfirmation')
            ->with($invoice);

        $this->entityManager->expects($this->once())->method('persist')->with($invoice);
        $this->entityManager->expects($this->once())->method('flush');

        $this->manager->onPaymentApplied($invoice);

        $this->assertNotNull(
            $invoice->getPaidNotificationSentAt(),
            'A sent confirmation must be stamped, or it will be sent again.'
        );
    }

    /**
     * Overpayment still clears the balance.
     */
    public function testOverpaymentIsTreatedAsPaidInFull(): void
    {
        $invoice = $this->createInvoice(amount: 100.00, amountPaid: 120.00);

        $this->invoiceEmailManager->expects($this->once())
            ->method('sendPaymentConfirmation');

        $this->manager->onPaymentApplied($invoice);
    }

    /**
     * The regression this guard exists for: the hook runs again for an invoice that was already paid.
     */
    public function testConfirmationIsNotSentTwice(): void
    {
        $invoice = $this->createInvoice(amount: 100.00, amountPaid: 100.00);
        $invoice->setPaidNotificationSentAt(new \DateTime('-3 weeks'));

        $this->invoiceEmailManager->expects($this->never())
            ->method('sendPaymentConfirmation');
        $this->entityManager->expects($this->never())->method('flush');

        $this->manager->onPaymentApplied($invoice);
    }

    /**
     * A draft has never been sent to the customer.
     */
    public function testDraftInvoiceIsNotConfirmed(): void
    {
        $invoice = $this->createInvoice(amount: 100.00, amountPaid: 100.00);
        $invoice->setStatus(Invoice::STATUS_DRAFT);

        $this->invoiceEmailManager->expects($this->never())
            ->method('sendPaymentConfirmation');

        $this->manager->onPaymentApplied($invoice);

        $this->assertNull($invoice->getPaidNotificationSentAt());
    }

    /**
     * A transient mail failure must leave the invoice unstamped.
     */
    public function testFailedSendIsNotStampedSoItCanBeRetried(): void
    {
        $invoice = $this->createInvoice(amount: 100.00, amountPaid: 100.00);

        $this->invoiceEmailManager->expects($this->once())
            ->method('sendPaymentConfirmation')
            ->willThrowException(new \RuntimeException('SMTP unavailable'));

        $this->entityManager->expects($this->never())->method('flush');

        $this->manager->onPaymentApplied($invoice);

        $this->assertNull($invoice->getPaidNotificationSentAt());
    }

    /**
     * A failure here happens after the money is already committed.
     */
    public function testEmailFailureDoesNotPropagate(): void
    {
        $invoice = $this->createInvoice(amount: 100.00, amountPaid: 100.00);

        $this->invoiceEmailManager->method('sendPaymentConfirmation')
            ->willThrowException(new \RuntimeException('SMTP unavailable'));

        $this->manager->onPaymentApplied($invoice);

        $this->addToAssertionCount(1);
    }

    /**
     * Creates the invoice.
     *
     * @param float $amount
     * @param float $amountPaid
     * @return Invoice
     */
    private function createInvoice(float $amount, float $amountPaid): Invoice
    {
        $invoice = new Invoice();
        $invoice->setStatus(Invoice::STATUS_POSTED);
        $invoice->setAmount($amount);
        $invoice->setAmountPaid($amountPaid);

        return $invoice;
    }
}
