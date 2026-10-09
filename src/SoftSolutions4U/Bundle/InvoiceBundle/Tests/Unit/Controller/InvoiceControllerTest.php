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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Controller;

use SoftSolutions4U\Bundle\InvoiceBundle\Controller\InvoiceController;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceEmailManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Pdf\InvoicePdfGeneratorInterface;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit tests for the back-office invoice PDF and email actions.
 */
class InvoiceControllerTest extends TestCase
{
    private InvoicePdfGeneratorInterface&MockObject $pdfGenerator;
    private InvoiceEmailManager&MockObject $emailManager;

    /** @var InvoiceController $controller */
    private InvoiceController $controller;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->pdfGenerator = $this->createMock(InvoicePdfGeneratorInterface::class);
        $this->emailManager = $this->createMock(InvoiceEmailManager::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => $params ? $id . ':' . implode(',', $params) : $id
        );
        $this->controller = new InvoiceController($this->pdfGenerator, $this->emailManager, $translator);
    }

    /**
     * Tests the PDF download: attachment, named after the invoice number, never cached.
     */
    public function testDownloadPdf(): void
    {
        $invoice = $this->invoice(0.0);
        $this->pdfGenerator->expects(self::once())->method('generate')->with($invoice)->willReturn('%PDF-1.7');

        $response = $this->controller->downloadPdfAction($invoice);

        self::assertSame('%PDF-1.7', $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^attachment; filename="?invoice-INV-2026-09-00019\.pdf"?$/',
            (string) $response->headers->get('Content-Disposition')
        );
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * Tests that the preview is the same PDF served inline.
     */
    public function testPreviewPdfIsInline(): void
    {
        $this->pdfGenerator->method('generate')->willReturn('%PDF-1.7');

        $response = $this->controller->previewPdfAction($this->invoice(0.0));

        self::assertMatchesRegularExpression(
            '/^inline; filename="?invoice-INV-2026-09-00019\.pdf"?$/',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    /**
     * Tests that an invoice without a number falls back to its id in the file name.
     */
    public function testPdfFileNameFallsBackToTheId(): void
    {
        $invoice = $this->invoice(0.0);
        $invoice->setInvoiceNo(null);
        $this->pdfGenerator->method('generate')->willReturn('');

        self::assertMatchesRegularExpression(
            '/^attachment; filename="?invoice-19\.pdf"?$/',
            (string) $this->controller->downloadPdfAction($invoice)->headers->get('Content-Disposition')
        );
    }

    /**
     * Tests that a reminder is sent for an invoice with money owing.
     */
    public function testSendReminder(): void
    {
        $invoice = $this->invoice(60.0);
        $this->emailManager->expects(self::once())->method('sendReminderNotification')->with($invoice);

        $response = $this->controller->sendReminderAction($invoice);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            [
                'successful' => true,
                'message' => 'softsolutions4u.invoice.messages.send_reminder_sent_to:customer@example.com',
            ],
            json_decode((string) $response->getContent(), true)
        );
    }

    /**
     * Tests that no reminder is sent for a paid invoice.
     */
    public function testNoReminderForAPaidInvoice(): void
    {
        $this->emailManager->expects(self::never())->method('sendReminderNotification');

        self::assertSame(
            Response::HTTP_CONFLICT,
            $this->controller->sendReminderAction($this->invoice(0.0))->getStatusCode()
        );
    }

    /**
     * Tests that a confirmation is sent for a paid invoice.
     */
    public function testSendPaymentConfirmation(): void
    {
        $invoice = $this->invoice(0.0);
        $this->emailManager->expects(self::once())->method('sendPaymentConfirmation')->with($invoice);

        $response = $this->controller->sendPaymentConfirmationAction($invoice);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString(
            'softsolutions4u.invoice.messages.send_payment_confirmation_sent_to',
            (string) $response->getContent()
        );
    }

    /**
     * Tests that no confirmation is sent while money is owed.
     */
    public function testNoConfirmationWhileMoneyIsOwed(): void
    {
        $this->emailManager->expects(self::never())->method('sendPaymentConfirmation');

        self::assertSame(
            Response::HTTP_CONFLICT,
            $this->controller->sendPaymentConfirmationAction($this->invoice(60.0))->getStatusCode()
        );
    }

    /**
     * Tests that a missing recipient is a client error and a send failure a server error.
     */
    public function testEmailErrorsMapToHttpStatuses(): void
    {
        $invoice = $this->invoice(60.0);

        $this->emailManager->method('sendReminderNotification')->willReturnOnConsecutiveCalls(
            self::throwException(new \DomainException('no recipient')),
            self::throwException(new \RuntimeException('SMTP down'))
        );

        $clientError = $this->controller->sendReminderAction($invoice);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $clientError->getStatusCode());
        self::assertSame(['message' => 'no recipient'], json_decode((string) $clientError->getContent(), true));

        self::assertSame(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $this->controller->sendReminderAction($invoice)->getStatusCode()
        );
    }

    /**
     * Returns the invoice.
     *
     * @param float $balance
     * @return Invoice
     */
    private function invoice(float $balance): Invoice
    {
        $customerUser = new CustomerUser();
        $customerUser->setEmail('customer@example.com');

        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setCustomerUser($customerUser);
        $invoice->setAmount(100.0);
        $invoice->setAmountPaid(100.0 - $balance);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 19);

        return $invoice;
    }
}
