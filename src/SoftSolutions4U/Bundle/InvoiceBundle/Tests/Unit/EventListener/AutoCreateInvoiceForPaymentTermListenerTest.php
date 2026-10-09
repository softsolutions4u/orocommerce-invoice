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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\EventListener;

use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\Configuration;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\AutoCreateInvoiceForPaymentTermListener;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for creating a draft invoice when a Payment Term order is placed.
 */
class AutoCreateInvoiceForPaymentTermListenerTest extends TestCase
{
    private InvoiceManager&MockObject $invoiceManager;
    private LoggerInterface&MockObject $logger;
    private EntityRepository&MockObject $orderRepository;
    private EntityManagerInterface&MockObject $entityManager;

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var AutoCreateInvoiceForPaymentTermListener $listener */
    private AutoCreateInvoiceForPaymentTermListener $listener;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->config = [
            Configuration::getConfigKeyByName(Configuration::INVOICE_ENABLED) => true,
            Configuration::getConfigKeyByName(Configuration::AUTO_CREATE_FOR_PAYMENT_TERM) => true,
        ];

        $this->invoiceManager = $this->createMock(InvoiceManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->orderRepository = $this->createMock(EntityRepository::class);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getRepository')->with(Order::class)->willReturn($this->orderRepository);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('get')->willReturnCallback(fn (string $key) => $this->config[$key] ?? null);

        $this->listener = new AutoCreateInvoiceForPaymentTermListener(
            $registry,
            $this->invoiceManager,
            $configManager,
            $this->logger
        );
    }

    /**
     * Tests that placing a Payment Term order creates and saves a draft invoice after the flush.
     */
    public function testCreatesAnInvoiceForAPaymentTermOrder(): void
    {
        $order = new Order();
        $invoice = new Invoice();

        $this->orderRepository->method('find')->with(55)->willReturn($order);
        $this->invoiceManager->expects(self::once())->method('assertCanCreateForOrder')->with($order);
        $this->invoiceManager->expects(self::once())->method('createFromOrder')->with($order)->willReturn($invoice);
        $this->invoiceManager->expects(self::once())->method('save')->with($invoice);

        $this->persist($this->transaction());
        $this->listener->postFlush();
    }

    /**
     * Tests that nothing happens during persist itself: creation waits for the flush to finish.
     */
    public function testWaitsForTheFlush(): void
    {
        $this->invoiceManager->expects(self::never())->method('createFromOrder');

        $this->persist($this->transaction());
    }

    /**
     * Tests that each order gets one invoice even if several transactions are persisted in one flush.
     */
    public function testOneInvoicePerOrderPerFlush(): void
    {
        $this->orderRepository->method('find')->willReturn(new Order());
        $this->invoiceManager->method('createFromOrder')->willReturn(new Invoice());
        $this->invoiceManager->expects(self::once())->method('save');

        $this->persist($this->transaction());
        $this->persist($this->transaction());
        $this->listener->postFlush();
        $this->listener->postFlush();
    }

    /**
     * Tests the transactions that must not create an invoice.
     *
     * @param string $entityClass
     * @param string $action
     * @param string $method
     * @dataProvider irrelevantTransactionsDataProvider
     */
    #[DataProvider('irrelevantTransactionsDataProvider')]
    public function testIgnoresOtherTransactions(string $entityClass, string $action, string $method): void
    {
        $this->invoiceManager->expects(self::never())->method('createFromOrder');

        $this->persist($this->transaction($entityClass, $action, $method));
        $this->listener->postFlush();
    }

    /**
     * Provides the data sets for irrelevant transactions data.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function irrelevantTransactionsDataProvider(): array
    {
        return [
            'card capture' => [Order::class, 'capture', 'credit_card_3'],
            'payment term, other action' => [Order::class, 'purchase', 'payment_term_1'],
            'not an order' => [\stdClass::class, 'pending', 'payment_term_1'],
            'method merely containing payment_term' => [Order::class, 'pending', 'legacy_payment_term_1'],
        ];
    }

    /**
     * Tests that nothing is created when the feature is switched off.
     */
    public function testDoesNothingWhenDisabled(): void
    {
        $this->config[Configuration::getConfigKeyByName(Configuration::AUTO_CREATE_FOR_PAYMENT_TERM)] = false;
        $this->invoiceManager->expects(self::never())->method('createFromOrder');

        $this->persist($this->transaction());
        $this->listener->postFlush();
    }

    /**
     * Tests that nothing is created when invoicing as a whole is switched off.
     */
    public function testDoesNothingWhenInvoicingIsDisabled(): void
    {
        $this->config[Configuration::getConfigKeyByName(Configuration::INVOICE_ENABLED)] = false;
        $this->invoiceManager->expects(self::never())->method('createFromOrder');

        $this->persist($this->transaction());
        $this->listener->postFlush();
    }

    /**
     * Tests that an order that already has an invoice is skipped and logged, not duplicated.
     */
    public function testSkipsAnOrderThatAlreadyHasAnInvoice(): void
    {
        $this->orderRepository->method('find')->willReturn(new Order());
        $this->invoiceManager->method('assertCanCreateForOrder')
            ->willThrowException(new \LogicException('softsolutions4u.invoice.messages.already_created'));
        $this->invoiceManager->expects(self::never())->method('createFromOrder');
        $this->logger->expects(self::once())->method('info')->with(self::stringContains('skipped'));

        $this->persist($this->transaction());
        $this->listener->postFlush();
    }

    /**
     * Tests that a failure never breaks checkout: it is logged instead of thrown.
     */
    public function testFailureIsLoggedNotThrown(): void
    {
        $this->orderRepository->method('find')->willReturn(new Order());
        $this->invoiceManager->method('createFromOrder')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('Failed to auto-create'));

        $this->persist($this->transaction());
        $this->listener->postFlush();
    }

    /**
     * Tests that a missing order is ignored.
     */
    public function testMissingOrderIsIgnored(): void
    {
        $this->orderRepository->method('find')->willReturn(null);
        $this->invoiceManager->expects(self::never())->method('createFromOrder');

        $this->persist($this->transaction());
        $this->listener->postFlush();
    }

    /**
     * Tests that a save which itself flushes does not re-enter and create a second invoice.
     */
    public function testDoesNotReenterDuringItsOwnFlush(): void
    {
        $this->orderRepository->method('find')->willReturn(new Order());
        $this->invoiceManager->method('createFromOrder')->willReturn(new Invoice());
        $this->invoiceManager->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (): void {
                // Saving flushes, which calls postFlush again while still processing.
                $this->persist($this->transaction());
                $this->listener->postFlush();
            });

        $this->persist($this->transaction());
        $this->listener->postFlush();
    }

    /**
     * Persists the given data.
     *
     * @param PaymentTransaction $transaction
     */
    private function persist(PaymentTransaction $transaction): void
    {
        $this->listener->postPersist($transaction, new PostPersistEventArgs($transaction, $this->entityManager));
    }

    /**
     * Returns the transaction.
     *
     * @param string $entityClass
     * @param string $action
     * @param string $method
     * @return PaymentTransaction
     */
    private function transaction(
        string $entityClass = Order::class,
        string $action = 'pending',
        string $method = 'payment_term_1'
    ): PaymentTransaction {
        $transaction = new PaymentTransaction();
        $transaction->setEntityClass($entityClass);
        $transaction->setEntityIdentifier(55);
        $transaction->setAction($action);
        $transaction->setPaymentMethod($method);

        return $transaction;
    }

    /**
     * Tests that reset() clears queued work so it cannot leak into the next request or message.
     */
    public function testResetClearsQueuedWork(): void
    {
        $queue = new \ReflectionProperty($this->listener, 'queue');
        $processing = new \ReflectionProperty($this->listener, 'processing');
        $queue->setValue($this->listener, [5 => 5]);
        $processing->setValue($this->listener, true);

        $this->listener->reset();

        self::assertSame([], $queue->getValue($this->listener));
        self::assertFalse($processing->getValue($this->listener));
    }
}
