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

use Oro\Bundle\PaymentBundle\Action\AbstractPaymentMethodAction;
use Oro\Bundle\PaymentBundle\Method\PaymentMethodInterface;
use Oro\Bundle\PaymentBundle\Method\Provider\PaymentMethodProviderInterface;
use Oro\Bundle\PaymentBundle\Provider\PaymentTransactionProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\PaymentManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

class PaymentManagerTest extends TestCase
{
    private PaymentMethodProviderInterface&MockObject $methodProvider;
    private PaymentTransactionProvider&MockObject $transactionProvider;
    private Router&MockObject $router;

    protected function setUp(): void
    {
        $this->methodProvider = $this->createMock(PaymentMethodProviderInterface::class);
        $this->transactionProvider = $this->createMock(PaymentTransactionProvider::class);
        $this->router = $this->createMock(Router::class);
        $this->router->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []) => '/' . $route . '/' . ($params['id'] ?? '')
        );
    }

    private function manager(): PaymentManager
    {
        return new PaymentManager(
            $this->methodProvider,
            $this->transactionProvider,
            $this->router,
            new NullLogger()
        );
    }

    private function payment(string $method = 'payment_term_3'): InvoicePayment
    {
        $payment = new InvoicePayment();
        $payment->setPaymentMethod($method);
        $payment->setCurrency('USD');
        $payment->setAmount(100.0);
        (new \ReflectionProperty($payment, 'id'))->setValue($payment, 9);

        return $payment;
    }

    public function testConstructorUsesNullLoggerWhenNoneProvided(): void
    {
        $manager = new PaymentManager(
            $this->methodProvider,
            $this->transactionProvider,
            $this->router
        );

        self::assertInstanceOf(PaymentManager::class, $manager);
    }

    public function testAddActionStoresTheAction(): void
    {
        $manager = $this->manager();
        $action = $this->createMock(AbstractPaymentMethodAction::class);

        $manager->addAction('purchase', $action);

        $reflection = new \ReflectionProperty($manager, 'actions');
        $actions = $reflection->getValue($manager);

        self::assertSame($action, $actions['purchase']);
    }

    public function testProcessPaymentLogsWarningForUnsupportedMethod(): void
    {
        $this->methodProvider->method('hasPaymentMethod')->willReturn(false);

        $manager = $this->manager();
        $manager->processPayment($this->payment('unknown_method'), new Request());

        self::assertFalse($manager->hasResponse());
    }

    /**
     * Payment Term does NOT mark the payment as successful: it is recorded as
     * awaiting admin confirmation.
     */
    public function testProcessPaymentTermRecordsPendingConfirmation(): void
    {
        $this->methodProvider->method('hasPaymentMethod')->willReturn(true);
        $this->methodProvider->method('getPaymentMethod')
            ->willReturn($this->createMock(PaymentMethodInterface::class));

        $manager = $this->manager();
        $manager->processPayment($this->payment('payment_term_3'), new Request());

        self::assertTrue($manager->hasResponse());

        $response = $manager->getResponse();
        self::assertFalse($response['successful'], 'Payment Term is not successful until an admin confirms');
        self::assertFalse($response['purchaseSuccessful']);
        self::assertTrue($response['pendingConfirmation']);
        self::assertArrayHasKey('redirectUrl', $response);

        self::assertFalse($manager->isSuccessful());
        self::assertTrue($manager->isPendingConfirmation());
    }

    /**
     * Money Order is an offline method too: it is recorded as awaiting admin confirmation.
     */
    public function testProcessMoneyOrderRecordsPendingConfirmation(): void
    {
        $this->methodProvider->method('hasPaymentMethod')->willReturn(true);
        $this->methodProvider->method('getPaymentMethod')
            ->willReturn($this->createMock(PaymentMethodInterface::class));

        $manager = $this->manager();
        $manager->processPayment($this->payment('money_order_2'), new Request());

        self::assertFalse($manager->isSuccessful());
        self::assertTrue($manager->isPendingConfirmation());
        self::assertSame('/softsolutions4u_invoice_frontend_payment_pending/9', $manager->getResponse()['redirectUrl']);
    }

    public function testIsOfflinePaymentMethodRecognisesTheDefaultPrefixes(): void
    {
        $manager = $this->manager();

        self::assertTrue($manager->isOfflinePaymentMethod('payment_term_3'));
        self::assertTrue($manager->isOfflinePaymentMethod('payment_term'));
        self::assertTrue($manager->isOfflinePaymentMethod('money_order_2'));
        self::assertFalse($manager->isOfflinePaymentMethod('credit_card'));
        self::assertFalse($manager->isOfflinePaymentMethod(''));
        self::assertFalse($manager->isOfflinePaymentMethod(null));
    }

    public function testOfflinePrefixesCanBeConfigured(): void
    {
        $manager = new PaymentManager(
            $this->methodProvider,
            $this->transactionProvider,
            $this->router,
            new NullLogger(),
            ['bank_transfer']
        );

        self::assertTrue($manager->isOfflinePaymentMethod('bank_transfer_1'));
        self::assertFalse($manager->isOfflinePaymentMethod('money_order_2'));
    }

    public function testBuildContextForPurchaseIncludesAmountAndCurrency(): void
    {
        $manager = $this->manager();
        $payment = $this->payment();
        $request = new Request();

        $context = $manager->buildContext(PaymentMethodInterface::PURCHASE, $payment, $request);

        self::assertSame(100.0, $context->get('amount'));
        self::assertSame('USD', $context->get('currency'));
        self::assertSame('payment_term_3', $context->get('paymentMethod'));
        self::assertSame($payment, $context->get('object'));
    }

    public function testBuildContextForValidateOmitsAmountAndCurrency(): void
    {
        $manager = $this->manager();
        $payment = $this->payment();
        $request = new Request();

        $context = $manager->buildContext(PaymentMethodInterface::VALIDATE, $payment, $request);

        self::assertNull($context->get('amount'));
        self::assertNull($context->get('currency'));
        self::assertSame('payment_term_3', $context->get('paymentMethod'));
    }

    public function testBuildContextIncludesSuccessAndFailureUrls(): void
    {
        $manager = $this->manager();
        $payment = $this->payment();
        $request = new Request();

        $context = $manager->buildContext(PaymentMethodInterface::PURCHASE, $payment, $request);

        $options = $context->get('transactionOptions');
        self::assertIsArray($options);
        self::assertArrayHasKey('successUrl', $options);
        self::assertArrayHasKey('failureUrl', $options);
        self::assertArrayHasKey('paymentId', $options);
        self::assertSame(9, $options['paymentId']);
    }

    public function testBuildContextHandlesMissingAdditionalData(): void
    {
        $manager = $this->manager();
        $payment = $this->payment();
        $request = new Request();

        $context = $manager->buildContext(PaymentMethodInterface::PURCHASE, $payment, $request);

        $options = $context->get('transactionOptions');
        self::assertSame([], $options['additionalData']);
    }

    /**
     * The frontend form data lives in the POST body under
     * "softsolutions4u_invoice_payment".
     */
    public function testBuildContextPullsAdditionalDataFromPostBody(): void
    {
        $manager = $this->manager();
        $payment = $this->payment();

        $request = new Request();
        $request->request->set('softsolutions4u_invoice_payment', ['additional_data' => ['foo' => 'bar']]);

        $context = $manager->buildContext(PaymentMethodInterface::PURCHASE, $payment, $request);

        $options = $context->get('transactionOptions');
        self::assertSame(['foo' => 'bar'], $options['additionalData']);
    }

    public function testHasResponseIsFalseByDefault(): void
    {
        self::assertFalse($this->manager()->hasResponse());
    }

    public function testGetResponseIsEmptyArrayByDefault(): void
    {
        self::assertSame([], $this->manager()->getResponse());
    }

    public function testIsSuccessfulIsFalseWhenNoResponse(): void
    {
        self::assertFalse($this->manager()->isSuccessful());
    }

    public function testIsSuccessfulIsFalseWhenResponseHasNoSuccessfulKey(): void
    {
        $manager = $this->manager();

        $reflection = new \ReflectionProperty($manager, 'response');
        $reflection->setValue($manager, ['message' => 'some error']);

        self::assertFalse($manager->isSuccessful());
    }

    public function testIsSuccessfulIsFalseWhenSuccessfulIsFalse(): void
    {
        $manager = $this->manager();

        $reflection = new \ReflectionProperty($manager, 'response');
        $reflection->setValue($manager, ['successful' => false]);

        self::assertFalse($manager->isSuccessful());
    }

    public function testIsSuccessfulIsTrueWhenSuccessfulIsTrue(): void
    {
        $manager = $this->manager();

        $reflection = new \ReflectionProperty($manager, 'response');
        $reflection->setValue($manager, ['successful' => true]);

        self::assertTrue($manager->isSuccessful());
    }

    public function testIsPendingConfirmationIsFalseByDefault(): void
    {
        self::assertFalse($this->manager()->isPendingConfirmation());
    }

    public function testIsPendingConfirmationIsTrueWhenFlagIsSet(): void
    {
        $manager = $this->manager();

        $reflection = new \ReflectionProperty($manager, 'response');
        $reflection->setValue($manager, ['pendingConfirmation' => true]);

        self::assertTrue($manager->isPendingConfirmation());
    }
}
