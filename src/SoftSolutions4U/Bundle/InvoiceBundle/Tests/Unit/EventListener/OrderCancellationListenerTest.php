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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Repository\InvoiceRepository;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\OrderCancellationListener;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceCancellationManager;
use SoftSolutions4U\Bundle\InvoiceBundle\Util\OrderCancellationChecker;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\OrderBundle\Entity\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class OrderCancellationListenerTest extends TestCase
{
    private InvoiceCancellationManager&MockObject $cancellationManager;
    private InvoiceRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private ManagerRegistry&MockObject $registry;
    private OrderCancellationListener $listener;

    protected function setUp(): void
    {
        $this->cancellationManager = $this->createMock(InvoiceCancellationManager::class);
        $this->repository = $this->createMock(InvoiceRepository::class);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getRepository')->willReturn($this->repository);

        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->listener = new OrderCancellationListener(
            $this->registry,
            $this->cancellationManager,
            new OrderCancellationChecker(),
            new NullLogger()
        );
    }

    private function order(int $id, ?string $internalId): Order
    {
        $status = null === $internalId ? null : new class ($internalId) {
            public function __construct(private readonly string $internalId)
            {
            }

            public function getId(): string
            {
                return 'order_internal_status.' . $this->internalId;
            }

            public function getInternalId(): string
            {
                return $this->internalId;
            }
        };

        return new class ($id, $status) extends Order {
            public function __construct(private int $stubId, private mixed $stubStatus)
            {
            }

            public function getId(): ?int
            {
                return $this->stubId;
            }

            public function getInternalStatus(): mixed
            {
                return $this->stubStatus;
            }
        };
    }

    private function args(): PostUpdateEventArgs
    {
        return new PostUpdateEventArgs(
            new \stdClass(),
            $this->createMock(EntityManagerInterface::class)
        );
    }

    public function testCancelsTheInvoiceOfACancelledOrder(): void
    {
        $invoice = new Invoice();
        $this->repository->method('findOneByOrderId')->with(42)->willReturn($invoice);

        $this->cancellationManager->expects(self::once())->method('cancelForOrder')->with($invoice);

        $this->listener->postUpdate($this->order(42, 'cancelled'), $this->args());
        $this->listener->postFlush();
    }

    public function testIgnoresAnOrderThatIsNotCancelled(): void
    {
        $this->cancellationManager->expects(self::never())->method('cancelForOrder');

        $this->listener->postUpdate($this->order(42, 'open'), $this->args());
        $this->listener->postFlush();
    }

    public function testIgnoresACancelledOrderWithoutAnInvoice(): void
    {
        $this->repository->method('findOneByOrderId')->willReturn(null);

        $this->cancellationManager->expects(self::never())->method('cancelForOrder');

        $this->listener->postUpdate($this->order(42, 'cancelled'), $this->args());
        $this->listener->postFlush();
    }

    public function testDrainsTheQueueAfterProcessing(): void
    {
        $this->repository->method('findOneByOrderId')->willReturn(new Invoice());

        $this->cancellationManager->expects(self::once())->method('cancelForOrder');

        $this->listener->postUpdate($this->order(42, 'cancelled'), $this->args());
        $this->listener->postFlush();
        $this->listener->postFlush();
    }

    public function testDeduplicatesRepeatedUpdatesToTheSameOrder(): void
    {
        $this->repository->method('findOneByOrderId')->willReturn(new Invoice());

        $this->cancellationManager->expects(self::once())->method('cancelForOrder');

        $this->listener->postUpdate($this->order(42, 'cancelled'), $this->args());
        $this->listener->postUpdate($this->order(42, 'cancelled'), $this->args());
        $this->listener->postFlush();
    }

    public function testSurvivesAFailureWhileCancelling(): void
    {
        $this->repository->method('findOneByOrderId')->willReturn(new Invoice());
        $this->cancellationManager->method('cancelForOrder')
            ->willThrowException(new \RuntimeException('database gone'));

        $this->listener->postUpdate($this->order(42, 'cancelled'), $this->args());

        $this->listener->postFlush();

        $this->expectNotToPerformAssertions();
    }

    /**
     * Exercises the outer catch: a failure while fetching the entity manager
     * must never take down the flush.
     */
    public function testSurvivesFailureResolvingTheEntityManager(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willThrowException(new \RuntimeException('boom'));

        $listener = new OrderCancellationListener(
            $registry,
            $this->cancellationManager,
            new OrderCancellationChecker(),
            $logger
        );

        $this->cancellationManager->expects(self::never())->method('cancelForOrder');

        $listener->postUpdate($this->order(42, 'cancelled'), $this->args());
        $listener->postFlush();
    }

    /**
     * Logs and skips when the manager for Invoice is not a Doctrine ORM entity manager.
     */
    public function testSkipsWhenNoEntityManagerIsAvailable(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $listener = new OrderCancellationListener(
            $registry,
            $this->cancellationManager,
            new OrderCancellationChecker(),
            new NullLogger()
        );

        $this->cancellationManager->expects(self::never())->method('cancelForOrder');

        $listener->postUpdate($this->order(42, 'cancelled'), $this->args());
        $listener->postFlush();
    }

    /**
     * An order with no id (unsaved) is not queued.
     */
    public function testIgnoresAnOrderWithoutAnId(): void
    {
        $order = new class extends Order {
            public function getId(): ?int
            {
                return 0;
            }

            public function getInternalStatus(): mixed
            {
                return 'order_internal_status.cancelled';
            }
        };

        $this->cancellationManager->expects(self::never())->method('cancelForOrder');

        $this->listener->postUpdate($order, $this->args());
        $this->listener->postFlush();
    }

    /**
     * Empty queue on postFlush is a no-op.
     */
    public function testPostFlushWithEmptyQueueIsANoOp(): void
    {
        $this->cancellationManager->expects(self::never())->method('cancelForOrder');

        $this->listener->postFlush();
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
