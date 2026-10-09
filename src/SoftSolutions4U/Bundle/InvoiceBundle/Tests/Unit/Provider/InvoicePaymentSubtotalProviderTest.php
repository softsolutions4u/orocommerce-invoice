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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\InvoicePaymentSubtotalProvider;
use Oro\Bundle\PricingBundle\SubtotalProcessor\Provider\SubtotalProviderConstructorArguments;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit tests for the invoice payment subtotal and totals providers.
 */
class InvoicePaymentSubtotalProviderTest extends TestCase
{
    /**
     * Provides the data sets for the test.
     *
     * @return InvoicePaymentSubtotalProvider
     */
    private function provider(): InvoicePaymentSubtotalProvider
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new InvoicePaymentSubtotalProvider(
            $this->createMock(SubtotalProviderConstructorArguments::class),
            $translator
        );
    }

    /**
     * Tests that the subtotal is the payment amount in the payment currency.
     */
    public function testSubtotalIsThePaymentAmount(): void
    {
        $payment = (new InvoicePayment())->setAmount(80.54);
        $payment->setCurrency('AUD');

        $subtotal = $this->provider()->getSubtotal($payment);

        self::assertSame(InvoicePaymentSubtotalProvider::TYPE, $subtotal->getType());
        self::assertSame(InvoicePaymentSubtotalProvider::SUBTOTAL_SORT_ORDER, $subtotal->getSortOrder());
        self::assertSame('softsolutions4u.invoice.invoicepayment.subtotal.label', $subtotal->getLabel());
        self::assertTrue($subtotal->isVisible());
        self::assertSame('AUD', $subtotal->getCurrency());
        self::assertEquals(80.54, $subtotal->getAmount());
    }

    /**
     * Tests that only invoice payments are supported.
     */
    public function testSupportsOnlyInvoicePayments(): void
    {
        self::assertTrue($this->provider()->isSupported(new InvoicePayment()));
        self::assertFalse($this->provider()->isSupported(new \stdClass()));
        self::assertFalse($this->provider()->isSupported(null));
    }

    /**
     * Tests that an unsupported entity is rejected.
     */
    public function testRejectsUnsupportedEntities(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider()->getSubtotal(new \stdClass());
    }
}
