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
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\OrderPaymentStatusUpdater;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PaymentBundle\Entity\PaymentStatus;
use Oro\Bundle\PaymentBundle\Manager\PaymentStatusManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for keeping the Order payment status in step with invoice payments.
 */
class OrderPaymentStatusUpdaterTest extends TestCase
{
    private PaymentStatusManager&MockObject $paymentStatusManager;
    private LoggerInterface&MockObject $logger;

    /** @var OrderPaymentStatusUpdater $updater */
    private OrderPaymentStatusUpdater $updater;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->paymentStatusManager = $this->createMock(PaymentStatusManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->updater = new OrderPaymentStatusUpdater($this->paymentStatusManager, $this->logger);
    }

    /**
     * Tests that the order status is recalculated and returned.
     */
    public function testRecalculatesTheOrderStatus(): void
    {
        $order = $this->order(55);
        $invoice = (new Invoice())->setOrder($order);

        $status = $this->createStub(PaymentStatus::class);
        $status->method('getPaymentStatus')->willReturn('full');

        $this->paymentStatusManager->expects(self::once())
            ->method('updatePaymentStatus')
            ->with($order)
            ->willReturn($status);

        self::assertSame('full', $this->updater->updateForInvoice($invoice));
    }

    /**
     * Tests that an invoice without an order is ignored.
     */
    public function testInvoiceWithoutAnOrderIsIgnored(): void
    {
        $this->paymentStatusManager->expects(self::never())->method('updatePaymentStatus');

        self::assertNull($this->updater->updateForInvoice(new Invoice()));
    }

    /**
     * Tests that an unsaved order is ignored.
     */
    public function testUnsavedOrderIsIgnored(): void
    {
        $this->paymentStatusManager->expects(self::never())->method('updatePaymentStatus');

        self::assertNull($this->updater->updateForOrder(new Order()));
    }

    /**
     * Tests that a failure is logged and swallowed, so a recorded payment is never rolled back by it.
     */
    public function testFailureIsLoggedNotThrown(): void
    {
        $this->paymentStatusManager->method('updatePaymentStatus')
            ->willThrowException(new \RuntimeException('database unavailable'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('failed to update payment status'), self::callback(
                static fn (array $context): bool => 55 === $context['orderId']
                    && 'database unavailable' === $context['message']
            ));

        self::assertNull($this->updater->updateForOrder($this->order(55)));
    }

    /**
     * Returns the order.
     *
     * @param int $id
     * @return Order
     */
    private function order(int $id): Order
    {
        $order = new Order();
        $class = new \ReflectionClass($order);

        while ($class && !$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }

        $class->getProperty('id')->setValue($order, $id);

        return $order;
    }
}
