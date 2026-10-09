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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Manager;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EmailBundle\Sender\EmailTemplateSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceEmailManager;

class InvoiceEmailManagerTest extends TestCase
{
    private function invoiceWithCustomer(): Invoice
    {
        $customer = new CustomerUser();
        $customer->setEmail('customer@example.com');

        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setCustomerUser($customer);
        $invoice->setAmount(100.0);
        $invoice->setCurrency('USD');

        return $invoice;
    }

    public function testIsCancelledOrOrderCancelledWhenInvoiceIsCancelled(): void
    {
        $invoice = new Invoice();
        $invoice->setStatus(Invoice::STATUS_CANCELLED);

        $manager = new InvoiceEmailManager(
            $this->createMock(EmailTemplateSender::class),
            new NullLogger(),
            $this->createMock(ConfigManager::class)
        );

        self::assertTrue($manager->isCancelledOrOrderCancelled($invoice));
    }

    public function testIsCancelledOrOrderCancelledWithoutAnOrder(): void
    {
        $invoice = new Invoice();
        $invoice->setStatus(Invoice::STATUS_POSTED);

        $manager = new InvoiceEmailManager(
            $this->createMock(EmailTemplateSender::class),
            new NullLogger(),
            $this->createMock(ConfigManager::class)
        );

        self::assertFalse($manager->isCancelledOrOrderCancelled($invoice));
    }

    public function testAssertHasValidRecipientReturnsTheCustomerUser(): void
    {
        $invoice = $this->invoiceWithCustomer();

        $manager = new InvoiceEmailManager(
            $this->createMock(EmailTemplateSender::class),
            new NullLogger(),
            $this->createMock(ConfigManager::class)
        );

        self::assertSame($invoice->getCustomerUser(), $manager->assertHasValidRecipient($invoice));
    }

    public function testAssertHasValidRecipientThrowsWhenThereIsNoCustomerUser(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');

        $manager = new InvoiceEmailManager(
            $this->createMock(EmailTemplateSender::class),
            new NullLogger(),
            $this->createMock(ConfigManager::class)
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(InvoiceEmailManager::ERROR_NO_RECIPIENT);

        $manager->assertHasValidRecipient($invoice);
    }

    public function testAssertHasValidRecipientThrowsWhenTheCustomerUserHasNoEmail(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNo('INV-2026-09-00019');
        $invoice->setCustomerUser(new CustomerUser());

        $manager = new InvoiceEmailManager(
            $this->createMock(EmailTemplateSender::class),
            new NullLogger(),
            $this->createMock(ConfigManager::class)
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(InvoiceEmailManager::ERROR_NO_RECIPIENT);

        $manager->assertHasValidRecipient($invoice);
    }

    public function testSendNotificationUsesTheUnpaidTemplateWhenBalanceIsPositive(): void
    {
        $invoice = $this->invoiceWithCustomer();
        $invoice->setAmountPaid(40.0);

        $sender = $this->createMock(EmailTemplateSender::class);
        $sender->expects(self::once())->method('sendEmailTemplateOrFail');

        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturn('noreply@example.com');

        $manager = new InvoiceEmailManager($sender, new NullLogger(), $config);
        $manager->sendNotification($invoice);
    }

    public function testSendNotificationUsesThePaidTemplateWhenFullyPaid(): void
    {
        $invoice = $this->invoiceWithCustomer();
        $invoice->setAmountPaid(100.0);

        $sender = $this->createMock(EmailTemplateSender::class);
        $sender->expects(self::once())->method('sendEmailTemplateOrFail');

        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturn('noreply@example.com');

        $manager = new InvoiceEmailManager($sender, new NullLogger(), $config);
        $manager->sendNotification($invoice);
    }

    public function testSendReminderNotification(): void
    {
        $invoice = $this->invoiceWithCustomer();

        $sender = $this->createMock(EmailTemplateSender::class);
        $sender->expects(self::once())->method('sendEmailTemplateOrFail');

        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturn('noreply@example.com');

        $manager = new InvoiceEmailManager($sender, new NullLogger(), $config);
        $manager->sendReminderNotification($invoice);
    }

    public function testSendPaymentConfirmation(): void
    {
        $invoice = $this->invoiceWithCustomer();
        $invoice->setAmountPaid(100.0);

        $sender = $this->createMock(EmailTemplateSender::class);
        $sender->expects(self::once())->method('sendEmailTemplateOrFail');

        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturn('noreply@example.com');

        $manager = new InvoiceEmailManager($sender, new NullLogger(), $config);
        $manager->sendPaymentConfirmation($invoice);
    }

    public function testSendCancellationNotification(): void
    {
        $invoice = $this->invoiceWithCustomer();
        $invoice->setStatus(Invoice::STATUS_CANCELLED);

        $sender = $this->createMock(EmailTemplateSender::class);
        $sender->expects(self::once())->method('sendEmailTemplateOrFail');

        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturn('noreply@example.com');

        $manager = new InvoiceEmailManager($sender, new NullLogger(), $config);
        $manager->sendCancellationNotification($invoice);
    }

    public function testSendNotificationForACancelledInvoiceUsesTheCancellationTemplate(): void
    {
        $invoice = $this->invoiceWithCustomer();
        $invoice->setStatus(Invoice::STATUS_CANCELLED);

        $sender = $this->createMock(EmailTemplateSender::class);
        $sender->expects(self::once())->method('sendEmailTemplateOrFail');

        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturn('noreply@example.com');

        $manager = new InvoiceEmailManager($sender, new NullLogger(), $config);
        $manager->sendNotification($invoice);
    }

    public function testSendTemplateWrapsFailuresInARuntimeException(): void
    {
        $invoice = $this->invoiceWithCustomer();

        $sender = $this->createMock(EmailTemplateSender::class);
        $sender->method('sendEmailTemplateOrFail')
            ->willThrowException(new \RuntimeException('smtp down'));

        $config = $this->createMock(ConfigManager::class);
        $config->method('get')->willReturn('noreply@example.com');

        $manager = new InvoiceEmailManager($sender, new NullLogger(), $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(InvoiceEmailManager::ERROR_SEND_FAILED);

        $manager->sendReminderNotification($invoice);
    }
}
