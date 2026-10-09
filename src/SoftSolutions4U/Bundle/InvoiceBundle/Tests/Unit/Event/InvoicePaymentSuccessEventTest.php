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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Event;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Event\InvoicePaymentSuccessEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the payment success event.
 */
class InvoicePaymentSuccessEventTest extends TestCase
{
    /**
     * Tests that the event carries the payment and gateway response.
     */
    public function testCarriesPaymentAndResponse(): void
    {
        $payment = new InvoicePayment();
        $event = new InvoicePaymentSuccessEvent($payment, ['successful' => true]);

        self::assertSame($payment, $event->getInvoicePayment());
        self::assertSame(['successful' => true], $event->getResponse());
        self::assertSame('softsolutions4u.invoice.payment_success', InvoicePaymentSuccessEvent::NAME);
    }

    /**
     * Tests that listeners can replace the payment.
     */
    public function testSetterReplacesThePayment(): void
    {
        $event = new InvoicePaymentSuccessEvent(new InvoicePayment(), ['reference' => 'abc']);
        $replacement = new InvoicePayment();

        self::assertSame($event, $event->setInvoicePayment($replacement));
        self::assertSame($replacement, $event->getInvoicePayment());
        self::assertSame(['reference' => 'abc'], $event->getResponse());
    }
}
