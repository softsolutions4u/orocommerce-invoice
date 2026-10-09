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
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoiceLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\Form\Type\InvoiceLineItemType;
use SoftSolutions4U\Bundle\InvoiceBundle\Form\Type\InvoiceType;
use Oro\Bundle\PaymentBundle\Formatter\PaymentMethodLabelFormatter;
use Oro\Bundle\PaymentBundle\Formatter\PaymentStatusLabelFormatter;
use Oro\Bundle\ProductBundle\Entity\Product;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit tests for the back-office invoice edit form and its line item form.
 *
 * These use a recording builder rather than a full form factory, because the
 * fields rely on Oro's customer selectors and entity types, which need a
 * database-backed container to instantiate.
 */
class InvoiceTypeTest extends TestCase
{
    private PaymentMethodLabelFormatter&MockObject $methodFormatter;
    private PaymentStatusLabelFormatter&MockObject $statusFormatter;
    private AuthorizationCheckerInterface&MockObject $authorizationChecker;
    private TranslatorInterface&MockObject $translator;

    /** @var array<string, array{type: string, options: array<string, mixed>}> */
    private array $fields = [];

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->methodFormatter = $this->createMock(PaymentMethodLabelFormatter::class);
        $this->statusFormatter = $this->createMock(PaymentStatusLabelFormatter::class);
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnMap([
            ['softsolutions4u.invoice.ui.not_available', [], null, null, 'N/A'],
        ]);
    }

    /**
     * Tests the fields of the edit form.
     */
    public function testFields(): void
    {
        $this->build(new Invoice());

        foreach (
            ['customer', 'customerUser', 'invoiceNo', 'poNumber', 'issueDate', 'dueDate', 'status', 'amount',
            'subtotal', 'discountAmount', 'taxAmount', 'grandTotal', 'billingAddressCity', 'shippingAddressCountry',
            'lineItemIdsToDelete'] as $field
        ) {
            self::assertArrayHasKey($field, $this->fields, $field);
        }

        self::assertTrue($this->fields['status']['options']['disabled'], 'Status changes only through actions');
        self::assertFalse($this->fields['lineItemIdsToDelete']['options']['mapped']);
        self::assertSame(array_flip(Invoice::getStatuses()), $this->fields['status']['options']['choices']);
    }

    /**
     * Tests that the invoice number is locked once the invoice exists.
     */
    public function testInvoiceNumberIsLockedOnceSaved(): void
    {
        $this->build(new Invoice());
        self::assertFalse($this->fields['invoiceNo']['options']['disabled']);

        $saved = new Invoice();
        (new \ReflectionProperty($saved, 'id'))->setValue($saved, 19);
        $this->build($saved);
        self::assertTrue($this->fields['invoiceNo']['options']['disabled']);
    }

    /**
     * Tests that payment method and status are shown as read-only formatted labels.
     */
    public function testPaymentLabelsAreFormattedAndReadOnly(): void
    {
        $invoice = new Invoice();
        $invoice->setPaymentMethod('payment_term_1');
        $invoice->setPaymentStatus('full');
        $this->methodFormatter->method('formatPaymentMethodLabel')
            ->with('payment_term_1', false)
            ->willReturn('Payment Term');
        $this->statusFormatter->method('formatPaymentStatusLabel')->with('full')->willReturn('Paid in full');

        $this->build($invoice);

        self::assertSame('Payment Term', $this->fields['paymentMethod']['options']['data']);
        self::assertSame('Paid in full', $this->fields['paymentStatus']['options']['data']);
        foreach (['paymentMethod', 'paymentStatus'] as $field) {
            self::assertTrue($this->fields[$field]['options']['disabled']);
            self::assertFalse($this->fields[$field]['options']['mapped']);
        }
    }

    /**
     * Tests that missing payment details show N/A without calling the formatters.
     */
    public function testMissingPaymentDetailsShowNotAvailable(): void
    {
        $this->methodFormatter->expects(self::never())->method('formatPaymentMethodLabel');
        $this->statusFormatter->expects(self::never())->method('formatPaymentStatusLabel');

        $this->build(new Invoice());

        self::assertSame('N/A', $this->fields['paymentMethod']['options']['data']);
        self::assertSame('N/A', $this->fields['paymentStatus']['options']['data']);
    }

    /**
     * Tests that the note field is only offered to users allowed to add notes.
     */
    public function testMemoRequiresPermission(): void
    {
        $this->authorizationChecker->method('isGranted')
            ->with('softsolutions4u_invoice_add_note')
            ->willReturnOnConsecutiveCalls(false, true);

        $this->build(new Invoice());
        self::assertArrayNotHasKey('memo', $this->fields);

        $this->build(new Invoice());
        self::assertArrayHasKey('memo', $this->fields);
    }

    /**
     * Tests the data classes of both forms.
     */
    public function testDataClasses(): void
    {
        $resolver = new OptionsResolver();
        $this->createType()->configureOptions($resolver);
        self::assertSame(Invoice::class, $resolver->resolve()['data_class']);

        $resolver = new OptionsResolver();
        (new InvoiceLineItemType())->configureOptions($resolver);
        $options = $resolver->resolve();
        self::assertSame(InvoiceLineItem::class, $options['data_class']);
        self::assertSame('softsolutions4u_invoice_line_item', $options['block_prefix']);
    }

    /**
     * Tests the line item form fields and how products are labelled in the picker.
     */
    public function testLineItemFields(): void
    {
        $builder = $this->recordingBuilder();
        (new InvoiceLineItemType())->buildForm($builder, []);

        self::assertSame(
            ['product', 'quantity', 'productUnit', 'id', 'discountAmount', 'taxAmount'],
            array_keys($this->fields)
        );
        self::assertSame('code', $this->fields['productUnit']['options']['choice_label']);
        self::assertFalse($this->fields['id']['options']['mapped']);

        // getDefaultName() is provided through Oro's magic accessors on some versions
        // and declared on others, so it is added to the mock only when missing.
        $builder = $this->getMockBuilder(Product::class)->disableOriginalConstructor();
        $builder = method_exists(Product::class, 'getDefaultName')
            ? $builder->onlyMethods(['getSku', 'getDefaultName'])
            : $builder->onlyMethods(['getSku'])->addMethods(['getDefaultName']);
        $product = $builder->getMock();
        $product->method('getSku')->willReturn('MK6210');
        $product->method('getDefaultName')->willReturn(null);

        self::assertSame('MK6210', $this->fields['product']['options']['choice_label']($product));
        self::assertSame(
            ['data-sku' => 'MK6210', 'data-name' => ''],
            $this->fields['product']['options']['choice_attr']($product)
        );
    }

    /**
     * Builds the given data.
     *
     * @param Invoice $invoice
     */
    private function build(Invoice $invoice): void
    {
        $type = $this->createType();
        $type->buildForm($this->recordingBuilder(), ['data' => $invoice]);
    }

    /**
     * Returns the recording builder.
     *
     * @return FormBuilderInterface
     */
    private function recordingBuilder(): FormBuilderInterface
    {
        $this->fields = [];

        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(
            function (string $name, ?string $type = null, array $options = []) use (&$builder): FormBuilderInterface {
                $this->fields[$name] = ['type' => (string) $type, 'options' => $options];

                return $builder;
            }
        );

        return $builder;
    }

    /**
     * Creates the form type under test with its mocked dependencies.
     *
     * @return InvoiceType
     */
    private function createType(): InvoiceType
    {
        return new InvoiceType(
            $this->methodFormatter,
            $this->statusFormatter,
            $this->authorizationChecker,
            $this->translator
        );
    }
}
