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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Controller\Admin;

use SoftSolutions4U\Bundle\InvoiceBundle\Controller\Admin\AdminInvoiceController;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceCancellationManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceEmailManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceLineItemManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Controller\ControllerContainerTrait;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\UIBundle\Route\Router;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit tests for the back-office invoice actions: create, post, edit guard, delete.
 */
class AdminInvoiceControllerTest extends TestCase
{
    use ControllerContainerTrait;

    private InvoiceManager&MockObject $invoiceManager;
    private InvoiceEmailManager&MockObject $emailManager;
    private InvoiceCancellationManager&MockObject $cancellationManager;
    private TranslatorInterface&MockObject $translator;

    /** @var array<string, EntityRepository&MockObject> */
    private array $repositories = [];

    /** @var AdminInvoiceController $controller */
    private AdminInvoiceController $controller;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->invoiceManager = $this->createMock(InvoiceManager::class);
        $this->emailManager = $this->createMock(InvoiceEmailManager::class);
        $this->cancellationManager = $this->createMock(InvoiceCancellationManager::class);

        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $parameters
                ? $id . ' ' . json_encode($parameters)
                : $id
        );

        foreach ([Order::class] as $class) {
            $this->repositories[$class] = $this->createMock(EntityRepository::class);
        }
        $objectManager = $this->createMock(ObjectManager::class);
        $objectManager->method('getRepository')->willReturnCallback(fn (string $class) => $this->repositories[$class]);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($objectManager);

        $this->controller = new AdminInvoiceController(
            $doctrine,
            $this->invoiceManager,
            $this->createMock(InvoiceLineItemManager::class),
            $this->createMock(Router::class),
            $this->emailManager,
            $this->translator,
            $this->cancellationManager
        );

        $this->attachContainer($this->controller, null);
        $this->registerContainerServices($this->controller);
    }

    /**
     * Adds 'twig' and 'form.factory' to the controller's mock container.
     *
     * @param AdminInvoiceController $controller
     */
    private function registerContainerServices(AdminInvoiceController $controller): void
    {
        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $container = $this->controllerContainer($controller);
        $container->set('twig', $twig);
    }

    // ----- Create from order -----

    /**
     * Tests creating an invoice from an order: saved, success message linking to it, back to the order.
     */
    public function testCreateFromOrder(): void
    {
        $order = $this->order(55);
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-<1>');
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 19);

        $this->repositories[Order::class]->method('find')->with(55)->willReturn($order);
        $this->invoiceManager->expects(self::once())->method('assertCanCreateForOrder')->with($order);
        $this->invoiceManager->expects(self::once())->method('createFromOrder')->with($order)->willReturn($invoice);
        $this->invoiceManager->expects(self::once())->method('save')->with($invoice);

        $response = $this->controller->createAction(55, new Request());

        self::assertSame('/oro_order_view?id=55', $response->headers->get('Location'));
        $message = $this->flashes('success')[0];
        self::assertStringContainsString('softsolutions4u.invoice.messages.create_success', $message);
        self::assertStringContainsString('INV-&lt;1&gt;', $message, 'The invoice number is escaped in the flash HTML');
        self::assertStringContainsString('softsolutions4u_invoice_view?id=19', $message);
    }

    /**
     * Tests that a second invoice for the same order is refused with the reason shown.
     */
    public function testCreateIsRefusedWhenNotAllowed(): void
    {
        $this->repositories[Order::class]->method('find')->willReturn($this->order(55));
        $this->invoiceManager->method('assertCanCreateForOrder')
            ->willThrowException(new \LogicException('softsolutions4u.invoice.messages.already_created'));
        $this->invoiceManager->expects(self::never())->method('save');

        $response = $this->controller->createAction(55, new Request());

        self::assertSame('/oro_order_view?id=55', $response->headers->get('Location'));
        self::assertSame(['softsolutions4u.invoice.messages.already_created'], $this->flashes('error'));
    }

    /**
     * Tests that an unknown order is a 404.
     */
    public function testCreateForAnUnknownOrder(): void
    {
        $this->repositories[Order::class]->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->controller->createAction(404, new Request());
    }

    // ----- Post -----

    /**
     * Tests posting: status and date set before saving, then the customer is notified.
     */
    public function testPost(): void
    {
        $invoice = $this->invoice();

        $this->invoiceManager->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Invoice $saved): bool => Invoice::STATUS_POSTED === $saved->getStatus()
                && $saved->isPosted()));
        $this->emailManager->expects(self::once())->method('sendNotification')->with($invoice);

        $response = $this->controller->postAction($invoice);

        self::assertSame(['successful' => true, 'emailSent' => true], $this->json($response));
        self::assertSame(['softsolutions4u.invoice.messages.post_success'], $this->flashes('success'));
    }

    /**
     * Tests that a failed email does not undo posting: the invoice stays posted and the user is warned.
     */
    public function testPostWhenTheEmailFails(): void
    {
        $invoice = $this->invoice();
        $this->invoiceManager->expects(self::once())->method('save');
        $this->emailManager->method('sendNotification')->willThrowException(new \RuntimeException('SMTP down'));

        $response = $this->controller->postAction($invoice);

        self::assertSame(['successful' => true, 'emailSent' => false], $this->json($response));
        self::assertTrue($invoice->isPosted());
        self::assertSame(['softsolutions4u.invoice.messages.post_email_error'], $this->flashes('warning'));
    }

    /**
     * Tests that an invoice cannot be posted twice.
     */
    public function testPostTwiceIsAConflict(): void
    {
        $invoice = $this->invoice();
        $invoice->setPostedAt(new \DateTime('-1 day'));
        $this->invoiceManager->expects(self::never())->method('save');

        self::assertSame(Response::HTTP_CONFLICT, $this->controller->postAction($invoice)->getStatusCode());
    }

    /**
     * Tests that an invoice with nobody to send it to is not posted.
     */
    public function testPostWithoutARecipient(): void
    {
        $this->invoiceManager->expects(self::never())->method('save');
        $this->emailManager->expects(self::never())->method('sendNotification');

        $noUser = $this->invoice();
        $noUser->setCustomerUser(null);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->controller->postAction($noUser)->getStatusCode());
        self::assertFalse($noUser->isPosted());

        $noEmail = $this->invoice();
        $noEmail->setCustomerUser(new CustomerUser());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->controller->postAction($noEmail)->getStatusCode());
    }

    /**
     * Tests the case a cancelled order leaves behind: a draft invoice that must
     * be cancelled at the moment someone tries to send it, not emailed out.
     */
    public function testPostCancelsADraftWhoseOrderWasCancelled(): void
    {
        $invoice = $this->invoice();

        $this->cancellationManager->expects(self::once())
            ->method('cancelIfOrderCancelled')
            ->with($invoice)
            ->willReturn(true);

        $this->invoiceManager->expects(self::never())->method('save');
        $this->emailManager->expects(self::never())->method('sendNotification');

        $response = $this->controller->postAction($invoice);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame(
            'softsolutions4u.invoice.messages.post_order_cancelled',
            $this->json($response)['message']
        );
    }

    /**
     * Tests that an already cancelled invoice can never be sent to the customer.
     */
    public function testPostIsRefusedForACancelledInvoice(): void
    {
        $invoice = $this->invoice();
        $invoice->setStatus(Invoice::STATUS_CANCELLED);

        $this->invoiceManager->expects(self::never())->method('save');
        $this->emailManager->expects(self::never())->method('sendNotification');

        $response = $this->controller->postAction($invoice);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('softsolutions4u.invoice.messages.post_cancelled', $this->json($response)['message']);
    }

    // ----- Edit guard and delete -----

    /**
     * Tests that a posted invoice is read-only: editing redirects back with a warning.
     */
    public function testPostedInvoiceCannotBeEdited(): void
    {
        $invoice = $this->invoice();
        $invoice->setPostedAt(new \DateTime());
        $this->invoiceManager->expects(self::never())->method('save');

        $response = $this->controller->updateAction($invoice, new Request());

        self::assertSame('/softsolutions4u_invoice_view?id=19', $response->headers->get('Location'));
        self::assertSame(['softsolutions4u.invoice.messages.posted_readonly'], $this->flashes('warning'));
    }

    /**
     * Tests that a cancelled invoice cannot be edited.
     */
    public function testCancelledInvoiceCannotBeEdited(): void
    {
        $invoice = $this->invoice();
        $invoice->setStatus(Invoice::STATUS_CANCELLED);
        $this->invoiceManager->expects(self::never())->method('save');

        $response = $this->controller->updateAction($invoice, new Request());

        self::assertSame('/softsolutions4u_invoice_view?id=19', $response->headers->get('Location'));
        self::assertSame(['softsolutions4u.invoice.messages.cancelled_readonly'], $this->flashes('warning'));
    }

    /**
     * Tests deleting an invoice.
     */
    public function testDelete(): void
    {
        $invoice = $this->invoice();
        $this->invoiceManager->expects(self::once())->method('delete')->with($invoice);

        self::assertSame(['successful' => true], $this->json($this->controller->deleteAction($invoice)));
    }

    /**
     * Tests that a refused delete explains why, naming the invoice.
     */
    public function testDeleteRefused(): void
    {
        $invoice = $this->invoice();
        $this->invoiceManager->method('delete')
            ->willThrowException(new \LogicException('softsolutions4u.invoice.messages.delete_paid'));

        $response = $this->controller->deleteAction($invoice);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertStringContainsString(
            'softsolutions4u.invoice.messages.delete_paid',
            $this->json($response)['message']
        );
        self::assertStringContainsString('INV-2026-09-00019', $this->json($response)['message']);
    }

    // ----- Index / view / order index -----

    /**
     * Tests that the invoice list renders the grid.
     */
    public function testIndex(): void
    {
        $response = $this->controller->indexAction();

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Tests that the backoffice invoice view renders.
     */
    public function testView(): void
    {
        $response = $this->controller->viewAction($this->invoice());

        self::assertSame(200, $response->getStatusCode());
    }

    // ----- Helpers -----

    /**
     * Returns the invoice.
     *
     * @return Invoice
     */
    private function invoice(): Invoice
    {
        $customerUser = new CustomerUser();
        $customerUser->setEmail('customer@example.com');

        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setStatus(Invoice::STATUS_DRAFT);
        $invoice->setCustomerUser($customerUser);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 19);

        return $invoice;
    }

    /**
     * Returns the order.
     *
     * @param int $id
     * @return Order
     */
    private function order(int $id): Order
    {
        $order = new Order();
        $class = new \ReflectionClass($order);
        while ($class && !$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }
        $class->getProperty('id')->setValue($order, $id);

        return $order;
    }

    /**
     * Returns the json.
     *
     * @param Response $response
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }
}
