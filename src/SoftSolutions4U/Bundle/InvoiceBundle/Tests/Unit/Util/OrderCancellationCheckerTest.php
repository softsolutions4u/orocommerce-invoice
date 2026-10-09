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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Util;

use SoftSolutions4U\Bundle\InvoiceBundle\Util\OrderCancellationChecker;
use Oro\Bundle\OrderBundle\Entity\Order;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for reading an order's cancelled state out of the several
 * internal-status representations Oro hands over.
 *
 * `Order::getInternalStatus()` is injected by Oro's ExtendEntityTrait at
 * runtime, so PHPUnit's mock generator cannot see it (neither createMock
 * nor addMethods). A lightweight stub subclass exposing the getter is
 * used instead.
 */
class OrderCancellationCheckerTest extends TestCase
{
    /** @var OrderCancellationChecker $checker */
    private OrderCancellationChecker $checker;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->checker = new OrderCancellationChecker();
    }

    /**
     * Builds an order carrying the given internal status value.
     *
     * @param mixed $internalStatus
     * @return Order
     */
    private function order(mixed $internalStatus): Order
    {
        return new class ($internalStatus) extends Order {
            /**
             * Creates a new OrderCancellationCheckerTest instance.
             *
             * @param mixed $status
             */
            public function __construct(private mixed $status)
            {
                // Skip parent constructor - the real one needs a full
                // ORM/extend bootstrap that isn't available in unit tests.
            }

            /**
             * Returns the internal status.
             *
             * @return mixed
             */
            public function getInternalStatus(): mixed
            {
                return $this->status;
            }
        };
    }

    /**
     * Tests recognises an enum option reporting the prefixed id.
     */
    public function testRecognisesAnEnumOptionReportingThePrefixedId(): void
    {
        $status = new class {
            /**
             * Returns the id.
             *
             * @return string
             */
            public function getId(): string
            {
                return 'order_internal_status.cancelled';
            }

            /**
             * Returns the internal id.
             *
             * @return string
             */
            public function getInternalId(): string
            {
                return 'cancelled';
            }
        };

        self::assertTrue($this->checker->isCancelled($this->order($status)));
    }

    /**
     * Tests recognises a legacy enum value reporting the bare id.
     */
    public function testRecognisesALegacyEnumValueReportingTheBareId(): void
    {
        $status = new class {
            /**
             * Returns the id.
             *
             * @return string
             */
            public function getId(): string
            {
                return 'cancelled';
            }
        };

        self::assertTrue($this->checker->isCancelled($this->order($status)));
    }

    /**
     * Tests recognises a bare string status.
     */
    public function testRecognisesABareStringStatus(): void
    {
        self::assertTrue($this->checker->isCancelled($this->order('cancelled')));
    }

    /**
     * Tests recognises a prefixed string status.
     */
    public function testRecognisesAPrefixedStringStatus(): void
    {
        self::assertTrue($this->checker->isCancelled($this->order('order_internal_status.cancelled')));
    }

    /**
     * Tests does not treat other statuses as cancelled.
     */
    public function testDoesNotTreatOtherStatusesAsCancelled(): void
    {
        $status = new class {
            /**
             * Returns the id.
             *
             * @return string
             */
            public function getId(): string
            {
                return 'order_internal_status.open';
            }

            /**
             * Returns the internal id.
             *
             * @return string
             */
            public function getInternalId(): string
            {
                return 'open';
            }
        };

        self::assertFalse($this->checker->isCancelled($this->order($status)));
        self::assertFalse($this->checker->isCancelled($this->order('open')));
    }

    /**
     * Tests does not match an unrelated status ending in the same word.
     */
    public function testDoesNotMatchAnUnrelatedStatusEndingInTheSameWord(): void
    {
        self::assertFalse($this->checker->isCancelled($this->order('part_cancelled')));
    }

    /**
     * Tests treats a missing status as not cancelled.
     */
    public function testTreatsAMissingStatusAsNotCancelled(): void
    {
        self::assertFalse($this->checker->isCancelled($this->order(null)));
    }

    /**
     * Tests treats an unrecognised status object as not cancelled.
     */
    public function testTreatsAnUnrecognisedStatusObjectAsNotCancelled(): void
    {
        self::assertFalse($this->checker->isCancelled($this->order(new \stdClass())));
    }

    /**
     * Tests falls back to get id when internal id is empty.
     */
    public function testFallsBackToGetIdWhenInternalIdIsEmpty(): void
    {
        $status = new class {
            /**
             * Returns the id.
             *
             * @return string
             */
            public function getId(): string
            {
                return 'order_internal_status.cancelled';
            }

            /**
             * Returns the internal id.
             *
             * @return string
             */
            public function getInternalId(): string
            {
                return '';
            }
        };

        self::assertTrue($this->checker->isCancelled($this->order($status)));
    }
}
