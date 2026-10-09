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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Controller\Frontend;

use SoftSolutions4U\Bundle\InvoiceBundle\Controller\Frontend\FrontendInvoiceController;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\InvoicePaymentFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Form\Handler\InvoicePaymentFormHandler;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\PaymentManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Pdf\InvoicePdfGenerator;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\FrontendInvoiceProvider;
use SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Controller\ControllerContainerTrait;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\FormBundle\Model\UpdateHandlerFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit tests for the storefront invoice pages: who may see, pay and download an invoice.
 *
 * A customer user may only reach invoices of their own customer, never drafts,
 * and may only pay invoices that still have money owing. Anything else must
 * look like a missing page (404), so invoice ids cannot be probed.
 */
class FrontendInvoiceControllerTest extends TestCase
{
    use ControllerContainerTrait;

    private InvoicePaymentFactory&MockObject $paymentFactory;
    private InvoicePdfGenerator&MockObject $pdfGenerator;

    /** @var FrontendInvoiceController $controller */
    private FrontendInvoiceController $controller;

    /** @var Customer $ownCustomer */
    private Customer $ownCustomer;

    /** @var CustomerUser $ownUser */
    private CustomerUser $ownUser;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->paymentFactory = $this->createMock(InvoicePaymentFactory::class);
        $this->pdfGenerator = $this->createMock(InvoicePdfGenerator::class);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->controller = new FrontendInvoiceController(
            $this->createMock(UpdateHandlerFacade::class),
            $this->createMock(FrontendInvoiceProvider::class),
            $this->paymentFactory,
            $this->createMock(InvoicePaymentFormHandler::class),
            $this->createMock(PaymentManager::class),
            $this->pdfGenerator,
            $translator
        );

        $this->ownCustomer = $this->customer(1);
        $this->ownUser = new CustomerUser();
        $this->ownUser->setCustomer($this->ownCustomer);

        $this->attachContainer($this->controller, $this->ownUser);
    }

    // ----- Viewing -----

    /**
     * Tests that a customer user sees their own customer's issued invoice.
     */
    public function testViewOwnInvoice(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED);

        self::assertSame(['data' => ['invoice' => $invoice]], $this->controller->viewAction($invoice));
    }

    /**
     * Tests the invoices a customer user must not see.
     *
     * @param string $case
     * @dataProvider hiddenInvoiceDataProvider
     */
    #[DataProvider('hiddenInvoiceDataProvider')]
    public function testViewIsNotFound(string $case): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED);

        match ($case) {
            'draft' => $invoice->setStatus(Invoice::STATUS_DRAFT),
            'another customer' => $invoice->setCustomer($this->customer(2)),
            'no customer' => $invoice->setCustomer(null),
            'not a customer user' => $this->attachContainer($this->controller, null),
        };

        $this->expectException(NotFoundHttpException::class);

        $this->controller->viewAction($invoice);
    }

    /**
     * Provides the data sets for hidden invoice data.
     *
     * @return array<string, array{string}>
     */
    public static function hiddenInvoiceDataProvider(): array
    {
        return [
            'draft' => ['draft'],
            'another customer' => ['another customer'],
            'no customer' => ['no customer'],
            'not a customer user' => ['not a customer user'],
        ];
    }

    /**
     * Tests that every non-draft status stays visible, including paid and cancelled invoices.
     */
    public function testIssuedInvoicesOfEveryStatusAreVisible(): void
    {
        foreach (Invoice::FRONTEND_VISIBLE_STATUSES as $status) {
            $invoice = $this->invoice($status);
            self::assertSame(['data' => ['invoice' => $invoice]], $this->controller->viewAction($invoice), $status);
        }

        self::assertNotContains(Invoice::STATUS_DRAFT, Invoice::FRONTEND_VISIBLE_STATUSES);
    }

    /**
     * Tests that pages require a logged-in user.
     */
    public function testViewRequiresLogin(): void
    {
        $this->attachContainer($this->controller, $this->ownUser, false);

        $this->expectException(AccessDeniedException::class);

        $this->controller->viewAction($this->invoice(Invoice::STATUS_POSTED));
    }

    /**
     * Tests that the invoice list requires a logged-in user.
     */
    public function testIndexRequiresLogin(): void
    {
        self::assertSame(['entity_class' => Invoice::class], $this->controller->indexAction());

        $this->attachContainer($this->controller, null, false);
        $this->expectException(AccessDeniedException::class);

        $this->controller->indexAction();
    }

    // ----- Starting a payment -----

    /**
     * Tests that paying an unpaid invoice creates a payment and opens it.
     */
    public function testCreatePaymentForAnUnpaidInvoice(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED);
        $payment = new InvoicePayment();
        (new \ReflectionProperty($payment, 'id'))->setValue($payment, 7);

        $this->paymentFactory->expects(self::once())
            ->method('createFromInvoice')
            ->with($invoice, true)
            ->willReturn($payment);

        $response = $this->controller->createPaymentForInvoiceAction($invoice);

        self::assertSame('/softsolutions4u_invoice_frontend_payment?id=7', $response->getTargetUrl());
    }

    /**
     * Tests that a paid invoice cannot be paid again.
     */
    public function testCreatePaymentForAPaidInvoiceIsRefused(): void
    {
        $this->paymentFactory->expects(self::never())->method('createFromInvoice');

        $response = $this->controller->createPaymentForInvoiceAction($this->invoice(Invoice::STATUS_PAID));

        self::assertSame('/softsolutions4u_invoice_frontend_index', $response->getTargetUrl());
        self::assertSame(['softsolutions4u.invoice.frontend.payment.not_payable.message'], $this->flashes('error'));
    }

    /**
     * Tests that another customer's invoice cannot be paid.
     */
    public function testCreatePaymentForAnotherCustomersInvoiceIsNotFound(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED);
        $invoice->setCustomer($this->customer(2));
        $this->paymentFactory->expects(self::never())->method('createFromInvoice');

        $this->expectException(NotFoundHttpException::class);

        $this->controller->createPaymentForInvoiceAction($invoice);
    }

    /**
     * Tests that a failure creating the payment is shown to the customer.
     */
    public function testCreatePaymentFailureIsShown(): void
    {
        $this->paymentFactory->method('createFromInvoice')
            ->willThrowException(new \LogicException('softsolutions4u.invoice.frontend.payment.errors.multi_currency'));

        $response = $this->controller->createPaymentForInvoiceAction($this->invoice(Invoice::STATUS_POSTED));

        self::assertSame('/softsolutions4u_invoice_frontend_index', $response->getTargetUrl());
        self::assertSame(
            ['softsolutions4u.invoice.frontend.payment.errors.multi_currency'],
            $this->flashes('error')
        );
    }

    // ----- The payment page -----

    /**
     * Tests that a completed payment cannot be reopened.
     */
    public function testCompletedPaymentCannotBeReopened(): void
    {
        $payment = $this->payment($this->invoice(Invoice::STATUS_POSTED));
        $payment->setActive(false);

        $this->paymentFactory->expects(self::once())->method('ensureCustomerIsSet')->with($payment);

        $response = $this->controller->paymentAction(new Request(), $payment);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/softsolutions4u_invoice_frontend_index', $response->getTargetUrl());
        self::assertSame(['softsolutions4u.invoice.frontend.payment.inactive.message'], $this->flashes('error'));
    }

    /**
     * Tests that a payment page for an invoice paid in the meantime sends the customer back to the invoice.
     */
    public function testPaymentPageForAnInvoicePaidMeanwhile(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED);
        $invoice->setAmountPaid(100.0);
        $payment = $this->payment($invoice);
        $payment->setActive(true);

        $response = $this->controller->paymentAction(new Request(), $payment);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/softsolutions4u_invoice_frontend_view?id=19', $response->getTargetUrl());
    }

    /**
     * Tests that another customer's payment page is not found.
     */
    public function testPaymentPageOfAnotherCustomerIsNotFound(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED);
        $invoice->setCustomer($this->customer(2));

        $this->expectException(NotFoundHttpException::class);

        $this->controller->paymentAction(new Request(), $this->payment($invoice));
    }

    /**
     * Tests that a payment not attached to any invoice is not found.
     */
    public function testPaymentWithoutAnInvoiceIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller->paymentAction(new Request(), new InvoicePayment());
    }

    /**
     * Tests that the payment state cannot be saved for a completed payment.
     */
    public function testSaveStateForACompletedPaymentIsRefused(): void
    {
        $payment = $this->payment($this->invoice(Invoice::STATUS_POSTED));
        $payment->setActive(false);

        $response = $this->controller->saveStateAction(new Request(), $payment);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertFalse(json_decode((string) $response->getContent(), true)['success']);
    }

    /**
     * Tests the redirects after the gateway returns.
     */
    public function testSuccessAndErrorReturns(): void
    {
        $payment = $this->payment($this->invoice(Invoice::STATUS_POSTED));
        (new \ReflectionProperty($payment, 'id'))->setValue($payment, 7);

        self::assertSame(
            '/softsolutions4u_invoice_frontend_view?id=19',
            $this->controller->successAction($payment)->getTargetUrl()
        );
        self::assertSame(['softsolutions4u.invoice.frontend.payment.success.message'], $this->flashes('success'));

        self::assertSame(
            '/softsolutions4u_invoice_frontend_payment?id=7',
            $this->controller->errorAction($payment)->getTargetUrl()
        );
        self::assertSame(['softsolutions4u.invoice.frontend.payment.failed.message'], $this->flashes('error'));
    }

    /**
     * Tests that a payment awaiting confirmation is reported as pending, not as successful.
     */
    public function testSuccessReturnForPendingConfirmationPaymentShowsInfo(): void
    {
        $payment = $this->payment($this->invoice(Invoice::STATUS_POSTED));
        $payment->setPendingConfirmation(true);

        $this->controller->successAction($payment);

        self::assertSame([], $this->flashes('success'));
        self::assertSame(['softsolutions4u.invoice.frontend.payment.pending.message'], $this->flashes('info'));
    }

    /**
     * Tests that the pending page shows the awaiting-confirmation message and returns to the invoice.
     */
    public function testPendingPaymentReturnsToTheInvoiceWithInfo(): void
    {
        $payment = $this->payment($this->invoice(Invoice::STATUS_POSTED));

        $response = $this->controller->pendingAction($payment);

        self::assertSame('/softsolutions4u_invoice_frontend_view?id=19', $response->getTargetUrl());
        self::assertSame(['softsolutions4u.invoice.frontend.payment.pending.message'], $this->flashes('info'));
    }

    /**
     * Tests that the pending page of another customer's payment is not found.
     */
    public function testPendingPaymentOfAnotherCustomerIsNotFound(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED);
        $invoice->setCustomer($this->customer(2));

        $this->expectException(NotFoundHttpException::class);

        $this->controller->pendingAction($this->payment($invoice));
    }

    /**
     * Tests that a parent customer opens a sub-customer invoice when the storefront ACL grants VIEW.
     */
    public function testViewSubCustomerInvoiceWhenTheAclGrantsIt(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_POSTED);
        $invoice->setCustomer($this->customer(2));

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);
        $this->controllerContainer($this->controller)->set('security.authorization_checker', $authorizationChecker);

        self::assertSame(['data' => ['invoice' => $invoice]], $this->controller->viewAction($invoice));
    }

    // ----- PDF download -----

    /**
     * Tests that a customer user downloads their own invoice PDF.
     */
    public function testDownloadOwnPdf(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_PAID);
        $this->pdfGenerator->expects(self::once())->method('generate')->with($invoice)->willReturn('%PDF');

        $response = $this->controller->downloadPdfAction($invoice);

        self::assertSame('%PDF', $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^attachment; filename="?invoice-INV-2026-09-00019\.pdf"?$/',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    /**
     * Tests that another customer's PDF, or a draft's, is not found and never generated.
     */
    public function testDownloadPdfIsNotFound(): void
    {
        $this->pdfGenerator->expects(self::never())->method('generate');

        $foreign = $this->invoice(Invoice::STATUS_POSTED);
        $foreign->setCustomer($this->customer(2));

        foreach ([$foreign, $this->invoice(Invoice::STATUS_DRAFT)] as $invoice) {
            try {
                $this->controller->downloadPdfAction($invoice);
                self::fail('Expected a 404');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Returns the invoice.
     *
     * @param string $status
     * @return Invoice
     */
    private function invoice(string $status): Invoice
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setStatus($status);
        $invoice->setCustomer($this->ownCustomer);
        $invoice->setCurrency('USD');
        $invoice->setAmount(100.0);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 19);

        return $invoice;
    }

    /**
     * Returns the payment.
     *
     * @param Invoice $invoice
     * @return InvoicePayment
     */
    private function payment(Invoice $invoice): InvoicePayment
    {
        $payment = new InvoicePayment();
        $payment->addLineItem((new InvoicePaymentLineItem())->setInvoice($invoice)->setAmount(40.0));

        return $payment;
    }

    /**
     * Returns the customer.
     *
     * @param int $id
     * @return Customer
     */
    private function customer(int $id): Customer
    {
        $customer = new Customer();
        $class = new \ReflectionClass($customer);
        while ($class && !$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }
        $class->getProperty('id')->setValue($customer, $id);

        return $customer;
    }
}
