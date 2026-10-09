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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Util;

use Oro\Bundle\OrderBundle\Entity\Order;

/**
 * Order cancellation checker.
 */
class OrderCancellationChecker
{
    public const CANCELLED_INTERNAL_STATUS_ID = 'cancelled';

    /**
     * Returns whether the given order is currently cancelled.
     *
     * @param Order $order
     * @return bool
     */
    public function isCancelled(Order $order): bool
    {
        return $this->resolveInternalId($order->getInternalStatus()) === self::CANCELLED_INTERNAL_STATUS_ID;
    }

    /**
     * Reduces whatever internal status representation we were given to its bare internal id.
     *
     * @param mixed $internalStatus
     * @return string|null
     */
    private function resolveInternalId($internalStatus): ?string
    {
        if (null === $internalStatus) {
            return null;
        }

        if (is_string($internalStatus)) {
            return $this->stripEnumCodePrefix($internalStatus);
        }

        if (!is_object($internalStatus)) {
            return null;
        }

        if (method_exists($internalStatus, 'getInternalId')) {
            $internalId = $internalStatus->getInternalId();

            if (is_string($internalId) && '' !== $internalId) {
                return $this->stripEnumCodePrefix($internalId);
            }
        }

        if (method_exists($internalStatus, 'getId')) {
            $id = $internalStatus->getId();

            if (is_string($id) && '' !== $id) {
                return $this->stripEnumCodePrefix($id);
            }
        }

        return null;
    }

    /**
     * 'order_internal_status.cancelled' and 'cancelled' both reduce to 'cancelled'.
     *
     * @param string $id
     * @return string
     */
    private function stripEnumCodePrefix(string $id): string
    {
        $separatorPosition = strrpos($id, '.');

        if (false === $separatorPosition) {
            return $id;
        }

        return substr($id, $separatorPosition + 1);
    }
}
