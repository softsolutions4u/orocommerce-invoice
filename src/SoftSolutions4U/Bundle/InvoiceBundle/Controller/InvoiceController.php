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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Controller;

use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceEmailManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Pdf\InvoicePdfGeneratorInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Pdf\InvoicePdfResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Backoffice invoice PDF and email actions.
 */
class InvoiceController extends AbstractController
{
    /**
     * Creates a new InvoiceController instance.
     *
     * @param InvoicePdfGeneratorInterface $invoicePdfGenerator
     * @param InvoiceEmailManager $invoiceEmailManager
     * @param TranslatorInterface $translator
     */
    public function __construct(
        private readonly InvoicePdfGeneratorInterface $invoicePdfGenerator,
        private readonly InvoiceEmailManager $invoiceEmailManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Streams the invoice PDF as a download.
     *
     * @param Invoice $invoice
     * @return Response
     */
    #[Route(
        path: '/view/{id}/download-pdf',
        name: 'softsolutions4u_invoice_download_pdf',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    #[Acl(
        id: 'softsolutions4u_invoice_download_pdf',
        type: 'action',
        label: 'softsolutions4u.invoice.acl.download_pdf.label',
        category: 'invoice'
    )]
    public function downloadPdfAction(Invoice $invoice): Response
    {
        return InvoicePdfResponse::create(
            $invoice,
            $this->invoicePdfGenerator->generate($invoice),
            HeaderUtils::DISPOSITION_ATTACHMENT
        );
    }

    /**
     * Streams the invoice PDF inline for the preview dialog.
     *
     * @param Invoice $invoice
     * @return Response
     */
    #[Route(
        path: '/view/{id}/preview-pdf',
        name: 'softsolutions4u_invoice_preview_pdf',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    #[AclAncestor('softsolutions4u_invoice_download_pdf')]
    public function previewPdfAction(Invoice $invoice): Response
    {
        return InvoicePdfResponse::create(
            $invoice,
            $this->invoicePdfGenerator->generate($invoice),
            HeaderUtils::DISPOSITION_INLINE
        );
    }

    /**
     * Sends a payment reminder for an invoice with an outstanding balance.
     *
     * @param Invoice $invoice
     * @return JsonResponse
     */
    #[Route(
        path: '/view/{id}/send-reminder',
        name: 'softsolutions4u_invoice_send_reminder',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    #[Acl(
        id: 'softsolutions4u_invoice_send_reminder',
        type: 'action',
        label: 'softsolutions4u.invoice.acl.send_reminder.label',
        category: 'invoice'
    )]
    #[CsrfProtection()]
    public function sendReminderAction(Invoice $invoice): JsonResponse
    {
        if ($invoice->isBalancePaid()) {
            return $this->errorResponse(
                'softsolutions4u.invoice.messages.reminder_paid_in_full',
                Response::HTTP_CONFLICT
            );
        }

        return $this->sendAndRespond(
            fn () => $this->invoiceEmailManager->sendReminderNotification($invoice),
            $invoice,
            'softsolutions4u.invoice.messages.send_reminder_sent_to'
        );
    }

    /**
     * Sends the paid-in-full payment confirmation for the invoice.
     *
     * @param Invoice $invoice
     * @return JsonResponse
     */
    #[Route(
        path: '/view/{id}/send-payment-confirmation',
        name: 'softsolutions4u_invoice_send_payment_confirmation',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    #[Acl(
        id: 'softsolutions4u_invoice_send_payment_confirmation',
        type: 'action',
        label: 'softsolutions4u.invoice.acl.send_payment_confirmation.label',
        category: 'invoice'
    )]
    #[CsrfProtection()]
    public function sendPaymentConfirmationAction(Invoice $invoice): JsonResponse
    {
        if (!$invoice->isBalancePaid()) {
            return $this->errorResponse(
                'softsolutions4u.invoice.messages.confirmation_balance_outstanding',
                Response::HTTP_CONFLICT
            );
        }

        return $this->sendAndRespond(
            fn () => $this->invoiceEmailManager->sendPaymentConfirmation($invoice),
            $invoice,
            'softsolutions4u.invoice.messages.send_payment_confirmation_sent_to'
        );
    }

    /**
     * Runs an email action and maps its domain errors onto HTTP statuses.
     *
     * @param callable $send
     * @param Invoice $invoice
     * @param string $successMessage Translation key of the success message.
     * @return JsonResponse
     */
    private function sendAndRespond(callable $send, Invoice $invoice, string $successMessage): JsonResponse
    {
        try {
            $send();
        } catch (\DomainException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'successful' => true,
            'message' => $this->translator->trans(
                $successMessage,
                ['%email%' => (string) $invoice->getCustomerUser()?->getEmail()]
            ),
        ]);
    }

    /**
     * Returns a JSON error response with a translated message.
     *
     * @param string $messageKey
     * @param int $status
     * @return JsonResponse
     */
    private function errorResponse(string $messageKey, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $this->translator->trans($messageKey)], $status);
    }
}
