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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Manager;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\OrderCancellationChecker;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EmailBundle\Model\EmailTemplateCriteria;
use Oro\Bundle\EmailBundle\Model\From;
use Oro\Bundle\EmailBundle\Sender\EmailTemplateSender;
use Psr\Log\LoggerInterface;

/**
 * Sends the customer-facing invoice notification emails.
 */
class InvoiceEmailManager
{
    private const TEMPLATE_UNPAID = 'softsolutions4u_invoice_notification';
    private const TEMPLATE_PAID = 'softsolutions4u_invoice_paid_notification';
    private const TEMPLATE_REMINDER = 'softsolutions4u_invoice_reminder_notification';
    private const TEMPLATE_CANCELLED = 'softsolutions4u_invoice_cancelled_notification';

    public const ERROR_NO_RECIPIENT = 'softsolutions4u.invoice.messages.no_recipient';
    public const ERROR_SEND_FAILED = 'softsolutions4u.invoice.messages.email_send_error';

    /**
     * Creates a new InvoiceEmailManager instance.
     *
     * @param EmailTemplateSender $emailTemplateSender
     * @param LoggerInterface $logger
     * @param ConfigManager $configManager
     * @param OrderCancellationChecker $orderCancellationChecker
     */
    public function __construct(
        private EmailTemplateSender $emailTemplateSender,
        private LoggerInterface $logger,
        private ConfigManager $configManager,
        private OrderCancellationChecker $orderCancellationChecker = new OrderCancellationChecker()
    ) {
    }

    /**
     * Returns whether the invoice or the order behind it is cancelled.
     *
     * @param Invoice $invoice
     * @return bool
     */
    public function isCancelledOrOrderCancelled(Invoice $invoice): bool
    {
        if ($invoice->isCancelled()) {
            return true;
        }

        $order = $invoice->getOrder();

        return null !== $order && $this->orderCancellationChecker->isCancelled($order);
    }

    /**
     * Returns the customer user the invoice emails are sent to.
     *
     * @param Invoice $invoice
     * @return CustomerUser
     * @throws \DomainException When the invoice has no customer user with an email address.
     */
    public function assertHasValidRecipient(Invoice $invoice): CustomerUser
    {
        $customerUser = $invoice->getCustomerUser();

        if (!$customerUser instanceof CustomerUser || !$customerUser->getEmail()) {
            throw new \DomainException(self::ERROR_NO_RECIPIENT);
        }

        return $customerUser;
    }

    /**
     * Sends the new invoice email, or the paid email when nothing is outstanding.
     *
     * @param Invoice $invoice
     * @throws \DomainException When the invoice has no valid recipient.
     */
    public function sendNotification(Invoice $invoice): void
    {
        $templateName = $invoice->getBalance() <= 0
            ? self::TEMPLATE_PAID
            : self::TEMPLATE_UNPAID;

        $this->sendTemplate($invoice, $templateName, 'invoice notification');
    }

    /**
     * Sends the payment reminder.
     *
     * @param Invoice $invoice
     * @throws \DomainException When the invoice has no valid recipient.
     */
    public function sendReminderNotification(Invoice $invoice): void
    {
        $this->sendTemplate($invoice, self::TEMPLATE_REMINDER, 'invoice reminder notification');
    }

    /**
     * Sends the paid-in-full payment confirmation.
     *
     * @param Invoice $invoice
     * @throws \DomainException When the invoice has no valid recipient.
     */
    public function sendPaymentConfirmation(Invoice $invoice): void
    {
        $this->sendTemplate($invoice, self::TEMPLATE_PAID, 'invoice payment confirmation');
    }

    /**
     * Tells the customer their order was cancelled, so this invoice was cancelled too.
     *
     * @param Invoice $invoice
     * @throws \DomainException When the invoice has no valid recipient.
     */
    public function sendCancellationNotification(Invoice $invoice): void
    {
        $this->sendTemplate($invoice, self::TEMPLATE_CANCELLED, 'invoice cancellation notification');
    }

    /**
     * Sends one invoice email, replacing it with the cancellation notice for cancelled invoices.
     *
     * @param Invoice $invoice
     * @param string $templateName
     * @param string $logContext
     * @throws \DomainException
     * @throws \RuntimeException
     */
    private function sendTemplate(Invoice $invoice, string $templateName, string $logContext): void
    {
        $customerUser = $this->assertHasValidRecipient($invoice);

        // A cancelled invoice only ever receives the cancellation notice.
        if (self::TEMPLATE_CANCELLED !== $templateName && $this->isCancelledOrOrderCancelled($invoice)) {
            $this->logger->info(
                'Invoice {invoiceNo}: {context} suppressed - the invoice or its order is cancelled. '
                . 'Sending the cancellation notification instead.',
                ['invoiceNo' => $invoice->getInvoiceNo(), 'context' => $logContext]
            );

            $templateName = self::TEMPLATE_CANCELLED;
            $logContext = 'invoice cancellation notification';
        }

        try {
            $this->send($invoice, $customerUser, $templateName);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Failed to send {context} for invoice {invoiceNo}: {message}',
                [
                    'context' => $logContext,
                    'invoiceNo' => $invoice->getInvoiceNo(),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]
            );

            throw new \RuntimeException(self::ERROR_SEND_FAILED, 0, $exception);
        }
    }

    /**
     * Sends the email template to the customer user.
     *
     * @param Invoice $invoice
     * @param CustomerUser $customerUser
     * @param string $templateName
     */
    private function send(Invoice $invoice, CustomerUser $customerUser, string $templateName): void
    {
        $this->emailTemplateSender->sendEmailTemplateOrFail(
            From::emailAddress(
                $this->configManager->get('oro_notification.email_notification_sender_email')
            ),
            $customerUser,
            new EmailTemplateCriteria($templateName, Invoice::class),
            ['entity' => $invoice]
        );
    }
}
