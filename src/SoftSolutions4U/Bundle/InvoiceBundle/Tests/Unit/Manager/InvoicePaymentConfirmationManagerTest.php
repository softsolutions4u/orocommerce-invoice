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

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Event\InvoicePaymentSuccessEvent;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoicePaymentConfirmationManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class InvoicePaymentConfirmationManagerTest extends TestCase
{
    public function testConfirmsEveryPendingPaymentAndDispatchesTheSuccessEvent(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');

        $first = new InvoicePayment();
        $first->setActive(true);
        $first->setPendingConfirmation(true);
        (new \ReflectionProperty($first, 'id'))->setValue($first, 101);
        $invoice->addInvoicePayment($first);

        $second = new InvoicePayment();
        $second->setActive(true);
        $second->setPendingConfirmation(true);
        (new \ReflectionProperty($second, 'id'))->setValue($second, 102);
        $invoice->addInvoicePayment($second);

        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function ($event, $name) use (&$dispatched) {
                $dispatched[] = [$event, $name];
                return $event;
            }
        );

        $manager = new InvoicePaymentConfirmationManager($dispatcher, new NullLogger());

        self::assertSame(2, $manager->confirmPendingPayments($invoice));

        self::assertFalse($first->isPendingConfirmation());
        self::assertFalse($second->isPendingConfirmation());

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(InvoicePaymentSuccessEvent::class, $dispatched[0][0]);
        self::assertSame(InvoicePaymentSuccessEvent::NAME, $dispatched[0][1]);
    }

    public function testSkipsInactivePayments(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');

        // Active but not pending confirmation → skipped by the filter
        $activeNotPending = new InvoicePayment();
        $activeNotPending->setActive(true);
        $activeNotPending->setPendingConfirmation(false);
        $invoice->addInvoicePayment($activeNotPending);

        // Pending confirmation but not active → skipped by the filter
        $pendingNotActive = new InvoicePayment();
        $pendingNotActive->setActive(false);
        $pendingNotActive->setPendingConfirmation(true);
        $invoice->addInvoicePayment($pendingNotActive);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $manager = new InvoicePaymentConfirmationManager($dispatcher, new NullLogger());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(InvoicePaymentConfirmationManager::ERROR_NOTHING_TO_CONFIRM);

        $manager->confirmPendingPayments($invoice);
    }

    public function testThrowsWhenThereIsNothingPending(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $manager = new InvoicePaymentConfirmationManager($dispatcher, new NullLogger());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(InvoicePaymentConfirmationManager::ERROR_NOTHING_TO_CONFIRM);

        $manager->confirmPendingPayments($invoice);
    }
}
