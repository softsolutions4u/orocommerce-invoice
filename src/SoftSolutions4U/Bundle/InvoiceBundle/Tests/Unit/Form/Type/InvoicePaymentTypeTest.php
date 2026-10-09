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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Form\Type;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\Form\Type\InvoicePaymentType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * Unit tests for the storefront payment form's own rules, using a real form factory.
 *
 * The customer enters how much to pay; the form must refuse nothing, negative
 * amounts and more than the balance, and require a payment method.
 */
class InvoicePaymentTypeTest extends TypeTestCase
{
    /**
     * Tests that the form is pre-filled from the payment.
     */
    public function testPrefillsFromThePayment(): void
    {
        $payment = $this->payment(100.0, 60.0);
        (new \ReflectionProperty($payment, 'id'))->setValue($payment, 7);

        $form = $this->factory->create(InvoicePaymentType::class, $payment);

        self::assertEquals(7, $form->get('id')->getData(), 'Hidden fields hold the id as a string');
        self::assertEquals(60.0, $form->get('payment_amount')->getData());
    }

    /**
     * Tests that a valid part payment is applied to the line and the payment total.
     */
    public function testValidPartPayment(): void
    {
        $payment = $this->payment(100.0, 100.0);

        $form = $this->submit($payment, '40.00', 'credit_card_3');

        self::assertTrue($form->isSynchronized());
        self::assertCount(0, $form->getErrors(true));
        self::assertSame('credit_card_3', $payment->getPaymentMethod());
        self::assertSame(40.0, $payment->getLineItems()->first()->getAmount());
        self::assertSame(40.0, $payment->getAmount());
    }

    /**
     * Tests that paying exactly the balance is allowed, and amounts are rounded to cents.
     */
    public function testPayingTheExactBalance(): void
    {
        $payment = $this->payment(50.48, 50.48);

        $form = $this->submit($payment, '50.4799', 'credit_card_3');

        self::assertCount(0, $form->getErrors(true));
        self::assertSame(50.48, $payment->getLineItems()->first()->getAmount());
    }

    /**
     * Tests that more than the balance is refused, with the balance in the message.
     */
    public function testRefusesMoreThanTheBalance(): void
    {
        $payment = $this->payment(100.0, 100.0);
        $payment->getLineItems()->first()->getInvoice()->setAmountPaid(40.0);

        $form = $this->submit($payment, '60.01', 'credit_card_3');

        $errors = $form->get('payment_amount')->getErrors();
        self::assertCount(1, $errors);
        self::assertSame(
            'softsolutions4u.invoice.frontend.payment.errors.amount_exceeds_balance',
            $errors[0]->getMessageTemplate()
        );
        self::assertSame(['%currency%' => 'USD', '%balance%' => '60.00'], $errors[0]->getMessageParameters());
        self::assertSame(100.0, $payment->getLineItems()->first()->getAmount(), 'The line is not changed');
    }

    /**
     * Tests that zero, negative and non-numeric amounts are refused.
     *
     * @param string|null $amount
     * @dataProvider invalidAmountsDataProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAmountsDataProvider')]
    public function testRefusesAmountsThatAreNotPositive(?string $amount): void
    {
        $form = $this->submit($this->payment(100.0, 100.0), $amount, 'credit_card_3');

        self::assertGreaterThan(0, count($form->getErrors(true)));
    }

    /**
     * Provides the data sets for invalid amounts data.
     *
     * @return array<string, array{?string}>
     */
    public static function invalidAmountsDataProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5'],
            'empty' => [null],
        ];
    }

    /**
     * Tests that a payment method is required.
     */
    public function testRequiresAPaymentMethod(): void
    {
        $payment = $this->payment(100.0, 100.0);
        $form = $this->submit($payment, '40', '');

        // payment_method is a hidden field, and hidden fields bubble their errors up to the form.
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            $messages[] = $error->getMessage();
        }
        self::assertSame(['softsolutions4u.invoice.frontend.payment.errors.no_payment_method'], $messages);
        self::assertSame(100.0, $payment->getLineItems()->first()->getAmount(), 'Nothing is applied without a method');
    }

    /**
     * Tests that a payment not attached to an invoice cannot be submitted.
     */
    public function testRequiresAnInvoice(): void
    {
        $form = $this->factory->create(InvoicePaymentType::class, new InvoicePayment());
        $form->submit(['payment_amount' => '40', 'payment_method' => 'credit_card_3']);

        $errors = $form->getErrors();
        self::assertCount(1, $errors);
        self::assertSame('softsolutions4u.invoice.frontend.payment.errors.no_invoice', $errors[0]->getMessage());
    }

    /**
     * Tests the form's options and block prefix.
     */
    public function testOptionsAndName(): void
    {
        $form = $this->factory->create(InvoicePaymentType::class, new InvoicePayment());

        self::assertSame(InvoicePayment::class, $form->getConfig()->getOption('data_class'));
        self::assertTrue($form->getConfig()->getOption('allow_extra_fields'));
        self::assertSame('softsolutions4u_invoice_payment', (new InvoicePaymentType())->getBlockPrefix());
        self::assertSame('softsolutions4u_invoice_payment', (new InvoicePaymentType())->getName());
    }

    /**
     * Submits the given data.
     *
     * @param InvoicePayment $payment
     * @param string|null $amount
     * @param string $method
     * @return FormInterface
     */
    private function submit(InvoicePayment $payment, ?string $amount, string $method): FormInterface
    {
        $form = $this->factory->create(InvoicePaymentType::class, $payment);
        $form->submit(['payment_amount' => $amount, 'payment_method' => $method]);

        return $form;
    }

    /**
     * Returns the payment.
     *
     * @param float $invoiceAmount
     * @param float $lineAmount
     * @return InvoicePayment
     */
    private function payment(float $invoiceAmount, float $lineAmount): InvoicePayment
    {
        $invoice = new Invoice();
        $invoice->setCurrency('USD');
        $invoice->setAmount($invoiceAmount);

        $line = new InvoicePaymentLineItem();
        $line->setInvoice($invoice)->setAmount($lineAmount)->setCurrency('USD');

        $payment = new InvoicePayment();
        $payment->addLineItem($line);

        return $payment;
    }
}
