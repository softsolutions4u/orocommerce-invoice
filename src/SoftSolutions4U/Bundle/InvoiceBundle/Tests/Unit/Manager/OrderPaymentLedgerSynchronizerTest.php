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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\OrderPaymentLedgerSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class OrderPaymentLedgerSynchronizerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    /** @var array<int, PaymentTransaction> */
    private array $transactions = [];

    /** @var array<int, int> */
    private array $recordedTransactionIds = [];

    /** @var array<int, object> */
    private array $persisted = [];

    private OrderPaymentLedgerSynchronizer $synchronizer;

    protected function setUp(): void
    {
        $transactionRepository = $this->createMock(EntityRepository::class);
        $transactionRepository->method('findBy')->willReturnCallback(fn () => $this->transactions);

        $paymentRepository = $this->createMock(EntityRepository::class);
        $paymentRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria) => in_array(
                $criteria['paymentTransactionId'] ?? null,
                $this->recordedTransactionIds,
                true
            )
                ? new InvoicePayment()
                : null
        );

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class) => PaymentTransaction::class === $class
                ? $transactionRepository
                : $paymentRepository
        );
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $this->synchronizer = new OrderPaymentLedgerSynchronizer($this->entityManager, new NullLogger());
    }

    public function testRecordsTheCheckoutPayment(): void
    {
        $invoice = $this->createInvoice(100.00);
        $this->transactions = [$this->transaction(11, 'capture', '100.00')];

        $recorded = $this->synchronizer->recordMissingPayments($invoice, 0.0);

        self::assertSame(100.00, $recorded);
        self::assertCount(1, $this->persisted);

        $payment = $this->persisted[0];
        self::assertInstanceOf(InvoicePayment::class, $payment);
        self::assertFalse($payment->isActive());
        self::assertSame(11, $payment->getPaymentTransactionId());
        self::assertSame($invoice, $payment->getInvoice());
        self::assertSame('credit_card', $payment->getPaymentMethod());
        self::assertSame('USD', $payment->getCurrency());

        self::assertCount(1, $payment->getLineItems());
        $lineItem = $payment->getLineItems()->first();
        self::assertSame($invoice, $lineItem->getInvoice());
        self::assertSame(100.00, $lineItem->getAmount());
        self::assertSame(100.00, $payment->getAmount());
    }

    public function testPurchaseAndChargeCountAsPayments(): void
    {
        $invoice = $this->createInvoice(100.00);
        $this->transactions = [
            $this->transaction(11, 'purchase', '60.00'),
            $this->transaction(12, 'charge', '40.00'),
        ];

        self::assertSame(100.00, $this->synchronizer->recordMissingPayments($invoice, 0.0));
        self::assertCount(2, $this->persisted);
    }

    public function testIgnoresTransactionsThatDidNotTakeMoney(): void
    {
        $invoice = $this->createInvoice(100.00);
        $this->transactions = [
            $this->transaction(11, 'authorize', '100.00'),
            $this->transaction(12, 'invoice', '100.00'),
            $this->transaction(15, 'pending', '100.00'),
            $this->transaction(13, 'validate', '0.00'),
            $this->transaction(14, 'capture', '100.00', false),
        ];

        self::assertSame(0.0, $this->synchronizer->recordMissingPayments($invoice, 0.0));
        self::assertSame([], $this->persisted);
    }

    public function testSkipsTransactionsAlreadyInTheLedger(): void
    {
        $invoice = $this->createInvoice(100.00);
        $this->transactions = [$this->transaction(11, 'capture', '100.00')];
        $this->recordedTransactionIds = [11];

        self::assertSame(0.0, $this->synchronizer->recordMissingPayments($invoice, 100.00));
        self::assertSame([], $this->persisted);
    }

    public function testNeverRecordsMoreThanTheBalance(): void
    {
        $invoice = $this->createInvoice(100.00);
        $this->transactions = [$this->transaction(11, 'capture', '100.00')];

        self::assertSame(70.00, $this->synchronizer->recordMissingPayments($invoice, 30.00));
        self::assertSame(70.00, $this->persisted[0]->getAmount());
    }

    public function testStopsOnceTheInvoiceIsCovered(): void
    {
        $invoice = $this->createInvoice(100.00);
        $this->transactions = [
            $this->transaction(11, 'capture', '40.00'),
            $this->transaction(12, 'capture', '60.00'),
            $this->transaction(13, 'capture', '50.00'),
        ];

        self::assertSame(100.00, $this->synchronizer->recordMissingPayments($invoice, 0.0));
        self::assertCount(2, $this->persisted);
    }

    public function testRecordsAPartialPayment(): void
    {
        $invoice = $this->createInvoice(100.00);
        $this->transactions = [$this->transaction(11, 'capture', '25.50')];

        self::assertSame(25.50, $this->synchronizer->recordMissingPayments($invoice, 0.0));
    }

    public function testIgnoresInvoicesWithoutASavedOrder(): void
    {
        $this->transactions = [$this->transaction(11, 'capture', '100.00')];

        $withoutOrder = new Invoice();
        $withoutOrder->setAmount(100.00);
        self::setId($withoutOrder, 1);

        $unsaved = $this->createInvoice(100.00);
        self::setId($unsaved, null);

        self::assertSame(0.0, $this->synchronizer->recordMissingPayments($withoutOrder, 0.0));
        self::assertSame(0.0, $this->synchronizer->recordMissingPayments($unsaved, 0.0));
        self::assertSame([], $this->persisted);
    }

    /**
     * Copies the customerUser from the order when the invoice has none.
     */
    public function testCopiesCustomerUserFromTheOrderWhenMissingOnTheInvoice(): void
    {
        $customerUser = new CustomerUser();
        $customerUser->setEmail('fallback@example.com');

        $invoice = $this->createInvoice(100.00);
        // Remove the invoice's customerUser
        $reflection = new \ReflectionProperty($invoice, 'customerUser');
        $reflection->setValue($invoice, null);

        $invoice->getOrder()->setCustomerUser($customerUser);

        $this->transactions = [$this->transaction(11, 'capture', '100.00')];

        $this->synchronizer->recordMissingPayments($invoice, 0.0);

        self::assertSame($customerUser, $this->persisted[0]->getCustomerUser());
    }

    /**
     * Copies the customer from the order when the invoice has none.
     */
    public function testCopiesCustomerFromTheOrderWhenMissingOnTheInvoice(): void
    {
        $customer = new Customer();
        $customer->setName('Fallback Customer');

        $invoice = $this->createInvoice(100.00);
        $reflection = new \ReflectionProperty($invoice, 'customer');
        $reflection->setValue($invoice, null);

        $invoice->getOrder()->setCustomer($customer);

        $this->transactions = [$this->transaction(11, 'capture', '100.00')];

        $this->synchronizer->recordMissingPayments($invoice, 0.0);

        self::assertSame($customer, $this->persisted[0]->getCustomer());
    }

    /**
     * Copies the organization from the invoice when available.
     */
    public function testCopiesOrganizationFromTheInvoice(): void
    {
        $organization = new Organization();
        $invoice = $this->createInvoice(100.00);
        $invoice->setOrganization($organization);

        $this->transactions = [$this->transaction(11, 'capture', '100.00')];

        $this->synchronizer->recordMissingPayments($invoice, 0.0);

        self::assertSame($organization, $this->persisted[0]->getOrganization());
    }

    /**
     * Falls back to the transaction's currency when the invoice has none.
     */
    public function testFallsBackToTheTransactionCurrency(): void
    {
        $invoice = $this->createInvoice(100.00);
        $invoice->setCurrency('');
        $this->transactions = [$this->transaction(11, 'capture', '100.00')];

        $this->synchronizer->recordMissingPayments($invoice, 0.0);

        self::assertSame('USD', $this->persisted[0]->getCurrency());
    }

    /**
     * Transaction without an id is not treated as already-recorded.
     */
    public function testTransactionWithoutIdIsRecorded(): void
    {
        $invoice = $this->createInvoice(100.00);
        $transaction = $this->transaction(0, 'capture', '50.00');
        // Force getId to return null
        $transaction = $this->createStub(PaymentTransaction::class);
        $transaction->method('getId')->willReturn(null);
        $transaction->method('getAction')->willReturn('capture');
        $transaction->method('getAmount')->willReturn('50.00');
        $transaction->method('isSuccessful')->willReturn(true);
        $transaction->method('getPaymentMethod')->willReturn('credit_card');
        $transaction->method('getCurrency')->willReturn('USD');

        $this->transactions = [$transaction];

        self::assertSame(50.00, $this->synchronizer->recordMissingPayments($invoice, 0.0));
    }

    /**
     * Zero-amount transactions are ignored.
     */
    public function testZeroAmountTransactionsAreIgnored(): void
    {
        $invoice = $this->createInvoice(100.00);
        $this->transactions = [$this->transaction(11, 'capture', '0.00')];

        self::assertSame(0.0, $this->synchronizer->recordMissingPayments($invoice, 0.0));
        self::assertSame([], $this->persisted);
    }

    /**
     * Falls back to loading transactions one at a time when the bulk query fails.
     */
    public function testFallsBackToLoadingTransactionsOneByOne(): void
    {
        // First call to findBy throws (simulating decryption failure)
        $transactionRepository = $this->createMock(EntityRepository::class);
        $transactionRepository->method('findBy')->willThrowException(new \RuntimeException('decrypt failed'));

        $paymentRepository = $this->createMock(EntityRepository::class);
        $paymentRepository->method('findOneBy')->willReturn(null);

        $query = $this->createMock(Query::class);
        $query->method('getSingleColumnResult')->willReturn([11, 12]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class) => PaymentTransaction::class === $class
                ? $transactionRepository
                : $paymentRepository
        );
        $em->method('createQueryBuilder')->willReturn($qb);
        $em->method('find')->willReturnCallback(function (string $class, int $id) {
            if ($id === 11) {
                return $this->transaction(11, 'capture', '40.00');
            }
            // Second transaction is unreadable
            throw new \RuntimeException('unreadable');
        });

        $persisted = [];
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $synchronizer = new OrderPaymentLedgerSynchronizer($em, new NullLogger());

        $invoice = $this->createInvoice(100.00);

        $recorded = $synchronizer->recordMissingPayments($invoice, 0.0);

        self::assertSame(40.00, $recorded);
        self::assertCount(1, $persisted);
    }

    private function createInvoice(float $amount): Invoice
    {
        $customerUser = new CustomerUser();
        $customerUser->setEmail('customer@example.com');

        $order = new Order();
        self::setId($order, 55);

        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setCurrency('USD');
        $invoice->setAmount($amount);
        $invoice->setCustomerUser($customerUser);
        self::setId($invoice, 9);

        return $invoice;
    }

    private function transaction(int $id, string $action, string $amount, bool $successful = true): PaymentTransaction
    {
        $transaction = $this->createStub(PaymentTransaction::class);
        $transaction->method('getId')->willReturn($id);
        $transaction->method('getAction')->willReturn($action);
        $transaction->method('getAmount')->willReturn($amount);
        $transaction->method('isSuccessful')->willReturn($successful);
        $transaction->method('getPaymentMethod')->willReturn('credit_card');
        $transaction->method('getCurrency')->willReturn('USD');

        return $transaction;
    }

    private static function setId(object $entity, ?int $id): void
    {
        $class = new \ReflectionClass($entity);

        while ($class && !$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }

        if ($class) {
            $class->getProperty('id')->setValue($entity, $id);
        }
    }
}
