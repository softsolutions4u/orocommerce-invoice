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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Controller\Admin;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\SecurityBundle\Attribute\Acl;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Oro\Bundle\UIBundle\Route\Router;
use Psr\Log\LoggerInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Form\Type\InvoiceType;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceCancellationManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceEmailManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceLineItemManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Backoffice CRUD and the post workflow action for invoices.
 */
#[Route('/')]
class AdminInvoiceController extends AbstractController
{
    /**
     * Creates a new AdminInvoiceController instance.
     *
     * @param ManagerRegistry $doctrine
     * @param InvoiceManager $invoiceManager
     * @param InvoiceLineItemManager $invoiceLineItemManager
     * @param Router $router
     * @param InvoiceEmailManager $invoiceEmailManager
     * @param TranslatorInterface $translator
     * @param InvoiceCancellationManager $invoiceCancellationManager
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly InvoiceManager $invoiceManager,
        private readonly InvoiceLineItemManager $invoiceLineItemManager,
        private readonly Router $router,
        private readonly InvoiceEmailManager $invoiceEmailManager,
        private readonly TranslatorInterface $translator,
        private readonly InvoiceCancellationManager $invoiceCancellationManager,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Renders the backoffice invoice list.
     *
     * @return Response
     */
    #[Route(path: '/', name: 'softsolutions4u_invoice_index', methods: ['GET'])]
    #[Acl(id: 'softsolutions4u_invoice_view', type: 'entity', class: Invoice::class, permission: 'VIEW')]
    public function indexAction(): Response
    {
        return $this->render(
            '@SoftSolutions4UInvoice/Invoice/index.html.twig',
            ['gridName' => 'softsolutions4u-invoices-grid']
        );
    }

    /**
     * Creates a draft invoice from an order and returns to the order page.
     *
     * @param int $orderId
     * @param Request $request
     * @return Response
     */
    #[Route(
        path: '/create/{orderId}',
        name: 'softsolutions4u_invoice_create',
        requirements: ['orderId' => '\d+'],
        methods: ['POST']
    )]
    #[Acl(id: 'softsolutions4u_invoice_create', type: 'entity', class: Invoice::class, permission: 'CREATE')]
    #[CsrfProtection()]
    public function createAction(int $orderId, Request $request): Response
    {
        $order = $this->doctrine->getManager()->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }

        try {
            $this->invoiceManager->assertCanCreateForOrder($order);
        } catch (\LogicException $e) {
            $this->addFlash('error', $this->translator->trans($e->getMessage()));

            return $this->redirectToRoute('oro_order_view', ['id' => $order->getId()]);
        }

        try {
            $invoice = $this->invoiceManager->createFromOrder($order);
            $this->invoiceManager->save($invoice);
        } catch (\Throwable $e) {
            $this->logger?->error(
                'Invoice creation failed for order {orderId}.',
                ['orderId' => $order->getId(), 'exception' => $e]
            );
            $this->addFlash('error', $this->translator->trans('softsolutions4u.invoice.messages.create_error'));

            return $this->redirectToRoute('oro_order_view', ['id' => $order->getId()]);
        }

        $this->addFlash(
            'success',
            $this->translator->trans(
                'softsolutions4u.invoice.messages.create_success',
                [
                    '%invoiceNo%' => htmlspecialchars((string) $invoice->getInvoiceNo(), ENT_QUOTES),
                    '%url%' => $this->generateUrl('softsolutions4u_invoice_view', ['id' => $invoice->getId()]),
                ]
            )
        );

        return $this->redirectToRoute('oro_order_view', ['id' => $order->getId()]);
    }

    /**
     * Renders the backoffice invoice view.
     *
     * @param Invoice $invoice
     * @return Response
     */
    #[Route(path: '/view/{id}', name: 'softsolutions4u_invoice_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[AclAncestor('softsolutions4u_invoice_view')]
    public function viewAction(Invoice $invoice): Response
    {
        return $this->render('@SoftSolutions4UInvoice/Invoice/view.html.twig', ['entity' => $invoice]);
    }

    /**
     * Handles editing a draft invoice.
     *
     * @param Invoice $invoice
     * @param Request $request
     * @return Response
     */
    #[Route(
        path: '/update/{id}',
        name: 'softsolutions4u_invoice_update',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST']
    )]
    #[Acl(id: 'softsolutions4u_invoice_update', type: 'entity', class: Invoice::class, permission: 'EDIT')]
    public function updateAction(Invoice $invoice, Request $request): Response
    {
        if ($invoice->isCancelled()) {
            $this->addFlash('warning', $this->translator->trans('softsolutions4u.invoice.messages.cancelled_readonly'));

            return $this->redirectToRoute('softsolutions4u_invoice_view', ['id' => $invoice->getId()]);
        }

        if ($invoice->isPosted()) {
            $this->addFlash('warning', $this->translator->trans('softsolutions4u.invoice.messages.posted_readonly'));

            return $this->redirectToRoute('softsolutions4u_invoice_view', ['id' => $invoice->getId()]);
        }

        $form = $this->createForm(InvoiceType::class, $invoice);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $idsToDelete = array_filter(explode(',', (string) $form->get('lineItemIdsToDelete')->getData()));
            if ($idsToDelete) {
                $this->invoiceLineItemManager->removeLineItemsByIds($invoice, $idsToDelete);
            }

            $this->invoiceManager->save($invoice);
            $this->addFlash('success', $this->translator->trans('softsolutions4u.invoice.messages.update_success'));

            return $this->router->redirect($invoice);
        }

        return $this->render(
            '@SoftSolutions4UInvoice/Invoice/update.html.twig',
            ['entity' => $invoice, 'form' => $form->createView()]
        );
    }

    /**
     * Deletes a draft invoice.
     *
     * @param Invoice $invoice
     * @return Response
     */
    #[Route(
        path: '/delete/{id}',
        name: 'softsolutions4u_invoice_delete',
        requirements: ['id' => '\d+'],
        methods: ['DELETE']
    )]
    #[Acl(id: 'softsolutions4u_invoice_delete', type: 'entity', class: Invoice::class, permission: 'DELETE')]
    #[CsrfProtection()]
    public function deleteAction(Invoice $invoice): Response
    {
        try {
            $this->invoiceManager->delete($invoice);
        } catch (\LogicException $e) {
            return new JsonResponse(
                [
                    'message' => $this->translator->trans(
                        $e->getMessage(),
                        ['%invoiceNo%' => (string) $invoice->getInvoiceNo()]
                    ),
                ],
                Response::HTTP_CONFLICT
            );
        }

        return new JsonResponse(['successful' => true]);
    }

    /**
     * Posts the invoice and sends the initial notification to the customer.
     *
     * @param Invoice $invoice
     * @return JsonResponse
     */
    #[Route(path: '/{id}/post', name: 'softsolutions4u_invoice_post', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[Acl(
        id: 'softsolutions4u_invoice_post',
        type: 'action',
        label: 'softsolutions4u.invoice.acl.post.label',
        category: 'invoice'
    )]
    #[CsrfProtection()]
    public function postAction(Invoice $invoice): JsonResponse
    {
        if ($invoice->isCancelled()) {
            return $this->errorResponse('softsolutions4u.invoice.messages.post_cancelled', Response::HTTP_CONFLICT);
        }

        if ($this->invoiceCancellationManager->cancelIfOrderCancelled($invoice)) {
            return $this->errorResponse(
                'softsolutions4u.invoice.messages.post_order_cancelled',
                Response::HTTP_CONFLICT
            );
        }

        if ($invoice->isPosted()) {
            return $this->errorResponse('softsolutions4u.invoice.messages.already_posted', Response::HTTP_CONFLICT);
        }

        $customerUser = $invoice->getCustomerUser();
        if (!$customerUser instanceof CustomerUser || !$customerUser->getEmail()) {
            return $this->errorResponse(InvoiceEmailManager::ERROR_NO_RECIPIENT, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $invoice->setStatus(Invoice::STATUS_POSTED);
        $invoice->setPostedAt(new \DateTime());
        $this->invoiceManager->save($invoice);

        try {
            $this->invoiceEmailManager->sendNotification($invoice);
        } catch (\Throwable $exception) {
            $this->logger?->error(
                'Invoice {invoiceNo} was posted but the notification email failed.',
                ['invoiceNo' => $invoice->getInvoiceNo(), 'exception' => $exception]
            );
            $this->addFlash('warning', $this->translator->trans('softsolutions4u.invoice.messages.post_email_error'));

            return new JsonResponse(['successful' => true, 'emailSent' => false]);
        }

        $this->addFlash('success', $this->translator->trans('softsolutions4u.invoice.messages.post_success'));

        return new JsonResponse(['successful' => true, 'emailSent' => true]);
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
