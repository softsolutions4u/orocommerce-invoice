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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Form\Handler;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Event\InvoicePaymentSuccessEvent;
use SoftSolutions4U\Bundle\InvoiceBundle\Form\Handler\InvoicePaymentFormHandler;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\PaymentManager;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for submitting the storefront invoice payment form.
 */
class InvoicePaymentFormHandlerTest extends TestCase
{
    private EventDispatcherInterface&MockObject $dispatcher;
    private PaymentManager&MockObject $paymentManager;
    private ManagerRegistry&MockObject $registry;

    /** @var InvoicePaymentFormHandler $handler */
    private InvoicePaymentFormHandler $handler;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->paymentManager = $this->createMock(PaymentManager::class);
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->handler = new InvoicePaymentFormHandler($this->dispatcher, $this->paymentManager, $this->registry);
    }

    /**
     * Tests that a successful payment fires the success event with the gateway response.
     */
    public function testSuccessfulPaymentFiresTheSuccessEvent(): void
    {
        $payment = new InvoicePayment();
        $request = new Request();

        $this->paymentManager->expects(self::once())->method('processPayment')->with($payment, $request);
        $this->paymentManager->method('isSuccessful')->willReturn(true);
        $this->paymentManager->method('getResponse')->willReturn(['successful' => true]);

        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(
                self::callback(static fn ($event): bool => $event instanceof InvoicePaymentSuccessEvent
                    && $event->getInvoicePayment() === $payment
                    && ['successful' => true] === $event->getResponse()),
                InvoicePaymentSuccessEvent::NAME
            )
            ->willReturnArgument(0);

        self::assertTrue($this->handler->process($payment, $this->form(true, true, $request), $request));
    }

    /**
     * Tests that a declined payment reports failure and fires nothing.
     */
    public function testDeclinedPaymentFiresNothing(): void
    {
        $this->paymentManager->method('isSuccessful')->willReturn(false);
        $this->dispatcher->expects(self::never())->method('dispatch');

        $request = new Request();

        self::assertFalse($this->handler->process(new InvoicePayment(), $this->form(true, true, $request), $request));
    }

    /**
     * Tests that an invalid or unsubmitted form never charges the customer.
     */
    public function testInvalidFormIsNotProcessed(): void
    {
        $this->paymentManager->expects(self::never())->method('processPayment');
        $request = new Request();

        self::assertFalse($this->handler->process(new InvoicePayment(), $this->form(true, false, $request), $request));
        self::assertFalse($this->handler->process(new InvoicePayment(), $this->form(false, false, $request), $request));
    }

    /**
     * Tests that a valid form for other data passes without a payment.
     */
    public function testValidFormForOtherDataPasses(): void
    {
        $this->paymentManager->expects(self::never())->method('processPayment');
        $request = new Request();

        self::assertTrue($this->handler->process(new \stdClass(), $this->form(true, true, $request), $request));
    }

    /**
     * Tests that a payment awaiting confirmation is stored and flagged without firing the success event.
     */
    public function testPendingConfirmationPaymentIsStoredAndFlagged(): void
    {
        $payment = new InvoicePayment();
        $request = new Request();

        $this->paymentManager->expects(self::once())->method('processPayment')->with($payment, $request);
        $this->paymentManager->method('isPendingConfirmation')->willReturn(true);

        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('persist')->with($payment);
        $manager->expects(self::once())->method('flush');
        $this->registry->expects(self::once())
            ->method('getManagerForClass')
            ->with(InvoicePayment::class)
            ->willReturn($manager);
        $this->dispatcher->expects(self::never())->method('dispatch');

        self::assertFalse($this->handler->process($payment, $this->form(true, true, $request), $request));
        self::assertTrue($payment->isPendingConfirmation());
    }

    /**
     * Returns the form.
     *
     * @param bool $submitted
     * @param bool $valid
     * @param Request $request
     * @return FormInterface
     */
    private function form(bool $submitted, bool $valid, Request $request): FormInterface
    {
        $form = $this->createMock(FormInterface::class);
        $form->expects(self::once())->method('handleRequest')->with($request);
        $form->method('isSubmitted')->willReturn($submitted);
        $form->method('isValid')->willReturn($valid);

        return $form;
    }
}
