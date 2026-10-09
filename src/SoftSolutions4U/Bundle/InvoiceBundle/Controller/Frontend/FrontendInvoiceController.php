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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Controller\Frontend;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\InvoicePaymentFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Form\Handler\InvoicePaymentFormHandler;
use SoftSolutions4U\Bundle\InvoiceBundle\Form\Type\InvoicePaymentType;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\PaymentManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Pdf\InvoicePdfGeneratorInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Pdf\InvoicePdfResponse;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\FrontendInvoiceProvider;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\FormBundle\Form\Handler\FormHandlerInterface;
use Oro\Bundle\FormBundle\Model\UpdateHandlerFacade;
use Oro\Bundle\LayoutBundle\Attribute\Layout;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Frontend invoice controller.
 */
class FrontendInvoiceController extends AbstractController
{
    /** @var UpdateHandlerFacade $updateHandler */
    protected UpdateHandlerFacade $updateHandler;

    /** @var FrontendInvoiceProvider $frontendInvoiceProvider */
    protected FrontendInvoiceProvider $frontendInvoiceProvider;

    /** @var InvoicePaymentFactory $invoicePaymentFactory */
    protected InvoicePaymentFactory $invoicePaymentFactory;

    /** @var InvoicePaymentFormHandler $invoicePaymentFormHandler */
    protected InvoicePaymentFormHandler $invoicePaymentFormHandler;

    /** @var PaymentManager $paymentManager */
    protected PaymentManager $paymentManager;

    /** @var InvoicePdfGeneratorInterface $invoicePdfGenerator */
    protected InvoicePdfGeneratorInterface $invoicePdfGenerator;

    /** @var TranslatorInterface $translator */
    protected TranslatorInterface $translator;

    /**
     * Creates a new FrontendInvoiceController instance.
     *
     * @param UpdateHandlerFacade $updateHandler
     * @param FrontendInvoiceProvider $frontendInvoiceProvider
     * @param InvoicePaymentFactory $invoicePaymentFactory
     * @param InvoicePaymentFormHandler $invoicePaymentFormHandler
     * @param PaymentManager $paymentManager
     * @param InvoicePdfGeneratorInterface $invoicePdfGenerator
     * @param TranslatorInterface $translator
     */
    public function __construct(
        UpdateHandlerFacade $updateHandler,
        FrontendInvoiceProvider $frontendInvoiceProvider,
        InvoicePaymentFactory $invoicePaymentFactory,
        InvoicePaymentFormHandler $invoicePaymentFormHandler,
        PaymentManager $paymentManager,
        InvoicePdfGeneratorInterface $invoicePdfGenerator,
        TranslatorInterface $translator,
    ) {
        $this->updateHandler = $updateHandler;
        $this->frontendInvoiceProvider = $frontendInvoiceProvider;
        $this->invoicePaymentFactory = $invoicePaymentFactory;
        $this->invoicePaymentFormHandler = $invoicePaymentFormHandler;
        $this->paymentManager = $paymentManager;
        $this->invoicePdfGenerator = $invoicePdfGenerator;
        $this->translator = $translator;
    }

    /**
     * Renders the storefront invoice list.
     *
     * @return array<string, string>
     */
    #[Route(path: '/', name: 'softsolutions4u_invoice_frontend_index')]
    #[Layout(vars: ['entity_class'])]
    #[AclAncestor('softsolutions4u_invoice_frontend_view')]
    public function indexAction(): array
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        return [
            'entity_class' => Invoice::class,
        ];
    }

    /**
     * Renders the storefront invoice page for an invoice the customer owns.
     *
     * @param Invoice $invoice
     * @return array<string, array<string, Invoice>>
     */
    #[Route(path: '/view/{id}', name: 'softsolutions4u_invoice_frontend_view', requirements: ['id' => '\d+'])]
    #[Layout()]
    #[AclAncestor('softsolutions4u_invoice_frontend_view')]
    public function viewAction(Invoice $invoice): array
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        if (!in_array($invoice->getStatus(), Invoice::FRONTEND_VISIBLE_STATUSES, true)) {
            throw new NotFoundHttpException();
        }

        $this->assertCurrentCustomerOwnsInvoice($invoice);

        return [
            'data' => [
                'invoice' => $invoice,
            ],
        ];
    }

    /**
     * Backs the per-row "Pay" action on the frontend invoices grid.
     *
     * @param Invoice $invoice
     * @return RedirectResponse
     */
    #[Route(
        path: '/payment/create/{id}',
        name: 'softsolutions4u_invoice_frontend_payment_create_for_invoice',
        requirements: ['id' => '\d+'],
    )]
    #[AclAncestor('softsolutions4u_invoice_frontend_view')]
    public function createPaymentForInvoiceAction(Invoice $invoice): RedirectResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        $this->assertCurrentCustomerOwnsInvoice($invoice);

        if (!in_array($invoice->getStatus(), Invoice::UNPAID_STATUSES, true)) {
            $this->addFlash(
                'error',
                $this->translator->trans('softsolutions4u.invoice.frontend.payment.not_payable.message')
            );

            return $this->redirectToRoute('softsolutions4u_invoice_frontend_index');
        }

        try {
            $invoicePayment = $this->invoicePaymentFactory->createFromInvoice($invoice, true);
        } catch (\LogicException $e) {
            $this->addFlash('error', $this->translator->trans($e->getMessage()));

            return $this->redirectToRoute('softsolutions4u_invoice_frontend_index');
        }

        return $this->redirectToRoute('softsolutions4u_invoice_frontend_payment', [
            'id' => $invoicePayment->getId(),
        ]);
    }

    /**
     * Renders the payment form for the selected invoices.
     *
     * @param Request $request
     * @param InvoicePayment $invoicePayment
     * @return array<string, array<string, mixed>>|RedirectResponse|JsonResponse
     */
    #[Route(path: '/payment/{id}', name: 'softsolutions4u_invoice_frontend_payment', requirements: ['id' => '\d+'])]
    #[Layout()]
    #[AclAncestor('softsolutions4u_invoice_frontend_view')]
    public function paymentAction(
        Request $request,
        InvoicePayment $invoicePayment
    ): array|RedirectResponse|JsonResponse {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        $invoice = $this->getInvoiceFromPayment($invoicePayment);
        $this->assertCurrentCustomerOwnsInvoice($invoice);

        $this->invoicePaymentFactory->ensureCustomerIsSet($invoicePayment);

        if (!$invoicePayment->isActive()) {
            $this->addFlash(
                'error',
                $this->translator->trans('softsolutions4u.invoice.frontend.payment.inactive.message')
            );

            return $this->redirectToRoute('softsolutions4u_invoice_frontend_index');
        }

        if (!in_array($invoice->getStatus(), Invoice::UNPAID_STATUSES, true) || $invoice->getBalance() <= 0) {
            $this->addFlash(
                'error',
                $this->translator->trans('softsolutions4u.invoice.frontend.payment.not_payable.message')
            );

            return $this->redirectToRoute('softsolutions4u_invoice_frontend_view', ['id' => $invoice->getId()]);
        }

        return $this->update($invoicePayment, $request, $this->invoicePaymentFormHandler);
    }

    /**
     * Persists the in-progress payment state and starts the payment.
     *
     * @param Request $request
     * @param InvoicePayment $invoicePayment
     * @return JsonResponse
     */
    #[Route(
        path: '/payment/save_state/{id}',
        name: 'softsolutions4u_invoice_frontend_payment_save_state',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    #[AclAncestor('softsolutions4u_invoice_frontend_view')]
    public function saveStateAction(Request $request, InvoicePayment $invoicePayment): JsonResponse
    {
        $invoice = $this->getInvoiceFromPayment($invoicePayment);
        $this->assertCurrentCustomerOwnsInvoice($invoice);

        $this->invoicePaymentFactory->ensureCustomerIsSet($invoicePayment);

        if (!$invoicePayment->isActive() || !in_array($invoice->getStatus(), Invoice::UNPAID_STATUSES, true)) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('softsolutions4u.invoice.frontend.payment.not_payable.message'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $form = $this->createForm(InvoicePaymentType::class, $invoicePayment);

        $update = $this->frontendInvoiceProvider->createInvoicePaymentFormUpdate(
            $invoicePayment,
            $form
        );

        if ($update->handle($request)) {
            return new JsonResponse(['success' => true]);
        }

        return new JsonResponse([
            'success' => false,
            'message' => $this->collectFormErrors($form),
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Returns all form errors, including field errors, as a single translated message.
     *
     * @param FormInterface $form
     * @return string
     */
    private function collectFormErrors(FormInterface $form): string
    {
        $messages = [];

        foreach ($form->getErrors(true) as $error) {
            $template = $error->getMessageTemplate() ?: (string) $error->getMessage();
            $message = trim($template);

            if ('' !== $message) {
                $messages[] = $this->translator->trans($message, $error->getMessageParameters());
            }
        }

        if (!$messages) {
            return $this->translator->trans('softsolutions4u.invoice.frontend.payment.save_state_failed.message');
        }

        return implode(' ', array_unique($messages));
    }

    /**
     * Submits the payment form and returns the payment response, the errors or the page data.
     *
     * @param InvoicePayment $invoicePayment
     * @param Request $request
     * @param FormHandlerInterface|null $formHandler
     * @return array<string,array<string,mixed>>|RedirectResponse|JsonResponse
     */
    protected function update(
        InvoicePayment $invoicePayment,
        Request $request,
        ?FormHandlerInterface $formHandler = null,
    ): array|RedirectResponse|JsonResponse {
        $form = $this->createForm(InvoicePaymentType::class, $invoicePayment);

        $result = $this->updateHandler->update(
            $invoicePayment,
            $form,
            '',
            $request,
            $formHandler,
        );

        $flashBag = $request->getSession()->getFlashBag();
        foreach (['success', 'warning', 'error', 'info'] as $flashType) {
            $messages = array_filter(
                $flashBag->get($flashType),
                static fn ($message) => trim((string) $message) !== ''
            );

            if ($messages) {
                $flashBag->set($flashType, array_values($messages));
            }
        }

        $errors = [];

        if ($form->isSubmitted() && !$form->isValid()) {
            $formErrors = $form->getErrors();

            foreach ($formErrors as $error) {
                $errors[] = $error->getMessage();
            }
        }

        if ($this->paymentManager->hasResponse()) {
            $response = $this->paymentManager->getResponse();
            $data = ['responseData' => $response];

            if ($this->paymentManager->isPendingConfirmation() && !empty($response['redirectUrl'])) {
                $data['redirectUrl'] = $response['redirectUrl'];
            }

            return new JsonResponse($data);
        }

        if (count($errors) > 0) {
            return new JsonResponse(['errors' => $errors]);
        }

        return $result instanceof Response
            ? $result
            : [
                'data' => [
                    'entity' => $invoicePayment,
                    'invoice' => $this->getInvoiceFromPayment($invoicePayment),
                    'formView' => $form->createView(),
                ],
            ];
    }

    /**
     * Landing page shown after a successful payment.
     *
     * @param InvoicePayment $payment
     * @return RedirectResponse
     */
    #[Route(
        path: '/success/{id}',
        name: 'softsolutions4u_invoice_frontend_payment_success',
        requirements: ['id' => '\d+']
    )]
    #[AclAncestor('softsolutions4u_invoice_frontend_view')]
    public function successAction(InvoicePayment $payment): RedirectResponse
    {
        $invoice = $this->getInvoiceFromPayment($payment);
        $this->assertCurrentCustomerOwnsInvoice($invoice);

        // A payment that still awaits administrator confirmation must not be reported as completed.
        if ($payment->isPendingConfirmation()) {
            $this->addFlash(
                'info',
                $this->translator->trans('softsolutions4u.invoice.frontend.payment.pending.message')
            );
        } else {
            $this->addFlash(
                'success',
                $this->translator->trans('softsolutions4u.invoice.frontend.payment.success.message')
            );
        }

        return $this->redirectToRoute('softsolutions4u_invoice_frontend_view', ['id' => $invoice->getId()]);
    }

    /**
     * Landing page shown after an offline payment that awaits confirmation by the seller.
     *
     * @param InvoicePayment $payment
     * @return RedirectResponse
     */
    #[Route(
        path: '/pending/{id}',
        name: 'softsolutions4u_invoice_frontend_payment_pending',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    #[AclAncestor('softsolutions4u_invoice_frontend_view')]
    public function pendingAction(InvoicePayment $payment): RedirectResponse
    {
        $invoice = $this->getInvoiceFromPayment($payment);
        $this->assertCurrentCustomerOwnsInvoice($invoice);

        $this->addFlash('info', $this->translator->trans('softsolutions4u.invoice.frontend.payment.pending.message'));

        return $this->redirectToRoute('softsolutions4u_invoice_frontend_view', ['id' => $invoice->getId()]);
    }

    /**
     * Landing page shown after a failed or cancelled payment.
     *
     * @param InvoicePayment $payment
     * @return RedirectResponse
     */
    #[Route(path: '/error/{id}', name: 'softsolutions4u_invoice_frontend_payment_error', requirements: ['id' => '\d+'])]
    #[AclAncestor('softsolutions4u_invoice_frontend_view')]
    public function errorAction(InvoicePayment $payment): RedirectResponse
    {
        $invoice = $this->getInvoiceFromPayment($payment);
        $this->assertCurrentCustomerOwnsInvoice($invoice);

        $this->addFlash('error', $this->translator->trans('softsolutions4u.invoice.frontend.payment.failed.message'));

        return $this->redirectToRoute('softsolutions4u_invoice_frontend_payment', ['id' => $payment->getId()]);
    }

    /**
     * Streams the invoice PDF to the customer that owns it.
     *
     * @param Invoice $invoice
     * @return Response
     */
    #[Route(
        path: '/view/{id}/download-pdf',
        name: 'softsolutions4u_invoice_frontend_download_pdf',
        requirements: ['id' => '\d+'],
    )]
    #[AclAncestor('softsolutions4u_invoice_frontend_view')]
    public function downloadPdfAction(Invoice $invoice): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');
        $this->assertCurrentCustomerOwnsInvoice($invoice);

        if (!in_array($invoice->getStatus(), Invoice::FRONTEND_VISIBLE_STATUSES, true)) {
            throw new NotFoundHttpException();
        }

        return InvoicePdfResponse::create(
            $invoice,
            $this->invoicePdfGenerator->generate($invoice),
            HeaderUtils::DISPOSITION_ATTACHMENT
        );
    }

    /**
     * Returns the invoice the payment is for, or throws a 404.
     *
     * @param InvoicePayment $payment
     * @return Invoice
     */
    private function getInvoiceFromPayment(InvoicePayment $payment): Invoice
    {
        $lineItem = $payment->getLineItems()->first();
        $invoice = $lineItem ? $lineItem->getInvoice() : null;

        if (!$invoice instanceof Invoice) {
            throw new NotFoundHttpException();
        }

        return $invoice;
    }

    /**
     * Throws a 404 unless the invoice belongs to the user's customer or the storefront ACL grants VIEW on it.
     *
     * @param Invoice $invoice
     */
    private function assertCurrentCustomerOwnsInvoice(Invoice $invoice): void
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            throw new NotFoundHttpException();
        }

        $invoiceCustomer = $invoice->getCustomer();
        $ownCustomer = null !== $invoiceCustomer
            && null !== $user->getCustomer()
            && $user->getCustomer()->getId() === $invoiceCustomer->getId();

        // Parent customers may also open sub-customer invoices when their storefront role grants that access level.
        if (!$ownCustomer && !$this->isGranted('VIEW', $invoice)) {
            throw new NotFoundHttpException();
        }
    }
}
