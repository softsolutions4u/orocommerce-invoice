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

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact two-decimal money arithmetic for invoice balances.
 *
 * Values are converted to BigDecimal, calculated exactly and returned rounded to cents,
 * so sums and differences never collect binary floating-point errors.
 */
final class Money
{
    public const SCALE = 2;

    /**
     * Prevents instantiation; the class only has static helpers.
     */
    private function __construct()
    {
    }

    /**
     * Rounds a value to cents.
     *
     * @param float|int|string|null $value
     * @return float
     */
    public static function round(float|int|string|null $value): float
    {
        return self::toFloat(self::of($value));
    }

    /**
     * Returns the exact sum of the values, rounded to cents.
     *
     * @param float|int|string|null ...$values
     * @return float
     */
    public static function add(float|int|string|null ...$values): float
    {
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus(self::of($value));
        }

        return self::toFloat($sum);
    }

    /**
     * Returns the exact difference a - b, rounded to cents.
     *
     * @param float|int|string|null $minuend
     * @param float|int|string|null $subtrahend
     * @return float
     */
    public static function subtract(float|int|string|null $minuend, float|int|string|null $subtrahend): float
    {
        return self::toFloat(self::of($minuend)->minus(self::of($subtrahend)));
    }

    /**
     * Compares two amounts in cents: -1 when a < b, 0 when equal, 1 when a > b.
     *
     * @param float|int|string|null $first
     * @param float|int|string|null $second
     * @return int
     */
    public static function compare(float|int|string|null $first, float|int|string|null $second): int
    {
        return self::of($first)->compareTo(self::of($second));
    }

    /**
     * Converts a value to a BigDecimal rounded to cents.
     *
     * @param float|int|string|null $value
     * @return BigDecimal
     */
    public static function of(float|int|string|null $value): BigDecimal
    {
        if (null === $value || '' === $value) {
            return BigDecimal::zero()->toScale(self::SCALE);
        }

        // A fixed-point string avoids the exponent notation PHP uses for very small or large floats.
        $number = is_float($value) ? sprintf('%.10F', $value) : (string) $value;

        return BigDecimal::of($number)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * Converts a BigDecimal to a float rounded to cents.
     *
     * @param BigDecimal $value
     * @return float
     */
    private static function toFloat(BigDecimal $value): float
    {
        return $value->toScale(self::SCALE, RoundingMode::HALF_UP)->toFloat();
    }
}
