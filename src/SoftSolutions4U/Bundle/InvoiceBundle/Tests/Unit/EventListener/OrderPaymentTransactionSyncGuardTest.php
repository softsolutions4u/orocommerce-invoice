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

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\OrderPaymentTransactionSyncGuard;
use Oro\Bundle\PaymentBundle\Entity\PaymentTransaction;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the storefront/Order payment sync loop guard.
 */
class OrderPaymentTransactionSyncGuardTest extends TestCase
{
    /** @var OrderPaymentTransactionSyncGuard $guard */
    private OrderPaymentTransactionSyncGuard $guard;

    /**
     * Sets up a fresh guard.
     */
    protected function setUp(): void
    {
        $this->guard = new OrderPaymentTransactionSyncGuard();
    }

    /**
     * Tests that a claim is visible until it is released.
     */
    public function testClaimAndRelease(): void
    {
        $transaction = $this->createStub(PaymentTransaction::class);
        $invoicePayment = new InvoicePayment();

        self::assertFalse($this->guard->isClaimed($transaction));
        self::assertNull($this->guard->getClaimedInvoicePayment($transaction));

        $this->guard->claim($transaction, $invoicePayment);

        self::assertTrue($this->guard->isClaimed($transaction));
        self::assertSame($invoicePayment, $this->guard->getClaimedInvoicePayment($transaction));

        $this->guard->release($transaction);

        self::assertFalse($this->guard->isClaimed($transaction));
        self::assertNull($this->guard->getClaimedInvoicePayment($transaction));
    }

    /**
     * Tests that claims are tracked per transaction object.
     */
    public function testClaimsAreScopedToTheTransaction(): void
    {
        $claimed = $this->createStub(PaymentTransaction::class);
        $other = $this->createStub(PaymentTransaction::class);

        $this->guard->claim($claimed, new InvoicePayment());

        self::assertTrue($this->guard->isClaimed($claimed));
        self::assertFalse($this->guard->isClaimed($other));
    }

    /**
     * Tests that releasing an unclaimed transaction is a harmless no-op.
     */
    public function testReleasingAnUnclaimedTransactionIsANoOp(): void
    {
        $this->guard->release($this->createStub(PaymentTransaction::class));

        $this->addToAssertionCount(1);
    }

    /**
     * Tests that the queue deduplicates and is emptied by draining.
     */
    public function testQueueDeduplicatesAndDrains(): void
    {
        $first = $this->createStub(PaymentTransaction::class);
        $second = $this->createStub(PaymentTransaction::class);

        $this->guard->enqueue($first);
        $this->guard->enqueue($second);
        $this->guard->enqueue($first);

        $drained = $this->guard->drainQueue();

        self::assertCount(2, $drained);
        self::assertContains($first, $drained);
        self::assertContains($second, $drained);
        self::assertSame([], $this->guard->drainQueue(), 'Draining must empty the queue');
    }

    /**
     * Tests the re-entrancy flag.
     */
    public function testProcessingFlag(): void
    {
        self::assertFalse($this->guard->isProcessing());

        $this->guard->beginProcessing();
        self::assertTrue($this->guard->isProcessing());

        $this->guard->endProcessing();
        self::assertFalse($this->guard->isProcessing());
    }

    /**
     * Tests that reset clears claims, queue and the processing flag.
     */
    public function testResetClearsEverything(): void
    {
        $transaction = $this->createStub(PaymentTransaction::class);

        $this->guard->claim($transaction, new InvoicePayment());
        $this->guard->enqueue($transaction);
        $this->guard->beginProcessing();

        $this->guard->reset();

        self::assertFalse($this->guard->isClaimed($transaction));
        self::assertSame([], $this->guard->drainQueue());
        self::assertFalse($this->guard->isProcessing());
    }
}
