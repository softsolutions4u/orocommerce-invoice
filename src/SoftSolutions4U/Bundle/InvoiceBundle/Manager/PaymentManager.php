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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use Oro\Bundle\PaymentBundle\Action\AbstractPaymentMethodAction;
use Oro\Bundle\PaymentBundle\Method\PaymentMethodInterface;
use Oro\Bundle\PaymentBundle\Method\Provider\PaymentMethodProviderInterface;
use Oro\Bundle\PaymentBundle\Provider\PaymentTransactionProvider;
use Oro\Component\ChainProcessor\Context;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyPath;

/**
 * Runs the Oro payment method actions for storefront invoice payments.
 */
class PaymentManager
{
    public const RESPONSE_PATH = 'response';
    public const PENDING_CONFIRMATION = 'pendingConfirmation';


    /** Offline payment methods (identifier prefixes) that take no money and need an administrator to confirm them. */
    public const OFFLINE_METHOD_PREFIXES = ['payment_term', 'money_order'];

    /** @var PaymentMethodProviderInterface $paymentMethodProvider */
    protected PaymentMethodProviderInterface $paymentMethodProvider;

    /** @var PaymentTransactionProvider $paymentTransactionProvider */
    protected PaymentTransactionProvider $paymentTransactionProvider;

    /** @var UrlGeneratorInterface $router */
    protected UrlGeneratorInterface $router;

    /** @var LoggerInterface $logger */
    protected LoggerInterface $logger;

    /** @var array<string, AbstractPaymentMethodAction> */
    protected array $actions = [];

    /** @var array<string,mixed> */
    protected array $response = [];

    /** @var array<int, string> */
    protected array $offlineMethodPrefixes;

    /**
     * Creates a new PaymentManager instance.
     *
     * @param PaymentMethodProviderInterface $paymentMethodProvider
     * @param PaymentTransactionProvider $paymentTransactionProvider
     * @param UrlGeneratorInterface $router
     * @param LoggerInterface|null $logger
     * @param array<int, string> $offlineMethodPrefixes Payment method identifier prefixes that need confirmation.
     */
    public function __construct(
        PaymentMethodProviderInterface $paymentMethodProvider,
        PaymentTransactionProvider $paymentTransactionProvider,
        UrlGeneratorInterface $router,
        ?LoggerInterface $logger = null,
        array $offlineMethodPrefixes = self::OFFLINE_METHOD_PREFIXES
    ) {
        $this->paymentMethodProvider = $paymentMethodProvider;
        $this->paymentTransactionProvider = $paymentTransactionProvider;
        $this->router = $router;
        $this->logger = $logger ?? new NullLogger();
        $this->offlineMethodPrefixes = array_values(array_filter($offlineMethodPrefixes, 'is_string'));
    }

    /**
     * Processes the payment.
     *
     * @param InvoicePayment $payment
     * @param Request $request
     */
    public function processPayment(InvoicePayment $payment, Request $request): void
    {
        $this->response = [];
        $identifier = (string) $payment->getPaymentMethod();

        if (!$this->paymentMethodProvider->hasPaymentMethod($identifier)) {
            $this->logger->warning(
                'Unsupported payment method {method} used for invoice payment {paymentId}.',
                ['method' => $identifier, 'paymentId' => $payment->getId()]
            );

            return;
        }

        $method = $this->paymentMethodProvider->getPaymentMethod($identifier);

        if ($this->isOfflinePaymentMethod($identifier)) {
            $this->processOfflinePayment($payment);
            return;
        }

        if ($method->supports(PaymentMethodInterface::VALIDATE)) {
            $context = $this->buildContext(PaymentMethodInterface::VALIDATE, $payment, $request);
            $action = $this->actions[PaymentMethodInterface::VALIDATE];
            $action->initialize($context->toArray());
            $action->execute($context);

            $paymentTransaction = $this
                ->paymentTransactionProvider
                ->getActiveValidatePaymentTransaction($identifier);

            if (!$paymentTransaction->isSuccessful()) {
                $this->response = $context->get(self::RESPONSE_PATH);

                $this->logger->warning(
                    'Payment method {method} failed validation for invoice payment {paymentId}.',
                    ['method' => $identifier, 'paymentId' => $payment->getId()]
                );

                return;
            }
        }

        $context = $this->buildContext(PaymentMethodInterface::PURCHASE, $payment, $request);
        $action = $this->actions[PaymentMethodInterface::PURCHASE];
        $action->initialize($context->toArray());

        try {
            $action->execute($context);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Purchase action failed for invoice payment {paymentId}.',
                ['paymentId' => $payment->getId(), 'method' => $identifier, 'exception' => $exception]
            );

            $this->response = [];

            return;
        }

        $this->response = $context->get(self::RESPONSE_PATH);
    }

    /**
     * Registers a payment action for later dispatch.
     *
     * @param string $actionKey
     * @param AbstractPaymentMethodAction $action
     */
    public function addAction(string $actionKey, AbstractPaymentMethodAction $action): void
    {
        $this->actions[$actionKey] = $action;
    }

    /**
     * Records an offline payment (Payment Term, Money Order, ...) as awaiting confirmation by the seller.
     *
     * Offline methods take no money online, so the invoice stays unpaid until an administrator
     * confirms the payment with the "Confirm Payment" action on the invoice.
     *
     * @param InvoicePayment $payment
     */
    protected function processOfflinePayment(InvoicePayment $payment): void
    {
        $this->logger->info(
            'Offline payment {paymentId} ({method}) recorded; awaiting confirmation.',
            ['paymentId' => $payment->getId(), 'method' => $payment->getPaymentMethod()]
        );

        $this->response = [
            'successful' => false,
            'purchaseSuccessful' => false,
            self::PENDING_CONFIRMATION => true,
            'redirectUrl' => $this->router->generate(
                'softsolutions4u_invoice_frontend_payment_pending',
                ['id' => $payment->getId()]
            ),
        ];
    }

    /**
     * Builds the action context for the given payment method action type.
     *
     * @param string $type
     * @param InvoicePayment $payment
     * @param Request $request
     * @return Context<string,mixed>
     */
    public function buildContext(string $type, InvoicePayment $payment, Request $request): Context
    {
        $successUrl = $this->router->generate(
            'softsolutions4u_invoice_frontend_payment_success',
            ['id' => $payment->getId()]
        );
        $failureUrl = $this->router->generate(
            'softsolutions4u_invoice_frontend_payment_error',
            ['id' => $payment->getId()]
        );

        $frontendPayment = $request->request->all('softsolutions4u_invoice_payment');

        $options = [
            'attribute' => new PropertyPath(self::RESPONSE_PATH),
            'paymentMethod' => $payment->getPaymentMethod(),
            'object' => $payment,
            'transactionOptions' => [
                'saveForLaterUse' => $request->request->getBoolean('save_for_later'),
                'successUrl' => $successUrl,
                'failureUrl' => $failureUrl,
                'paymentId' => $payment->getId(),
                'additionalData' => $frontendPayment['additional_data'] ?? [],
            ]
        ];

        if ($type === PaymentMethodInterface::PURCHASE) {
            $options['amount'] = $payment->getTotal() ?: $payment->getAmount();
            $options['currency'] = $payment->getCurrency();
        }

        $context = new Context();

        foreach ($options as $key => $option) {
            $context->set($key, $option);
        }

        return $context;
    }

    /**
     * Returns whether the response is set.
     *
     * @return bool
     */
    public function hasResponse(): bool
    {
        return !empty($this->response);
    }

    /**
     * Returns the response.
     *
     * @return array<string,mixed>
     */
    public function getResponse(): array
    {
        return $this->response;
    }

    /**
     * Returns whether the last payment response is a successful one.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        $response = $this->getResponse();
        return (isset($response['successful']) && $response['successful'] === true);
    }

    /**
     * Returns whether the last payment was recorded and now awaits confirmation by the seller.
     *
     * @return bool
     */
    public function isPendingConfirmation(): bool
    {
        return true === ($this->response[self::PENDING_CONFIRMATION] ?? false);
    }

    /**
     * Returns whether the payment method is an offline method that an administrator must confirm.
     *
     * @param string|null $identifier Payment method identifier, e.g. "payment_term_1" or "money_order_2".
     * @return bool
     */
    public function isOfflinePaymentMethod(?string $identifier): bool
    {
        if (null === $identifier || '' === $identifier) {
            return false;
        }

        foreach ($this->offlineMethodPrefixes as $prefix) {
            if (str_starts_with($identifier, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
