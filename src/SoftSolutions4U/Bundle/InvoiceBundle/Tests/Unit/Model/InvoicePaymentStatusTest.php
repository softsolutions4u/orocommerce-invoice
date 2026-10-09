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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Model;

use SoftSolutions4U\Bundle\InvoiceBundle\Model\InvoicePaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the payment status resolver.
 */
class InvoicePaymentStatusTest extends TestCase
{
    /**
     * Tests from amounts.
     *
     * @param float $amountPaid
     * @param float $amountDue
     * @param string|null $currentStatus
     * @param string $expectedStatus
     * @dataProvider amountsDataProvider
     */
    #[DataProvider('amountsDataProvider')]
    public function testFromAmounts(
        float $amountPaid,
        float $amountDue,
        ?string $currentStatus,
        string $expectedStatus
    ): void {
        self::assertSame(
            $expectedStatus,
            InvoicePaymentStatus::fromAmounts($amountPaid, $amountDue, $currentStatus)
        );
    }

    /**
     * Provides the data sets for amounts data.
     *
     * @return \Generator<string, array<string, float|string|null>>
     */
    public static function amountsDataProvider(): \Generator
    {
        yield 'Nothing paid is pending' => [
            'amountPaid' => 0.00,
            'amountDue' => 100.00,
            'currentStatus' => null,
            'expectedStatus' => InvoicePaymentStatus::PENDING,
        ];

        yield 'Sub-cent payment rounds to nothing paid' => [
            'amountPaid' => 0.004,
            'amountDue' => 100.00,
            'currentStatus' => null,
            'expectedStatus' => InvoicePaymentStatus::PENDING,
        ];

        yield 'Nothing paid keeps a known current status' => [
            'amountPaid' => 0.00,
            'amountDue' => 100.00,
            'currentStatus' => InvoicePaymentStatus::AUTHORIZED,
            'expectedStatus' => InvoicePaymentStatus::AUTHORIZED,
        ];

        yield 'Nothing paid normalises a legacy current status' => [
            'amountPaid' => 0.00,
            'amountDue' => 100.00,
            'currentStatus' => 'partial',
            'expectedStatus' => InvoicePaymentStatus::PARTIALLY,
        ];

        yield 'Nothing paid ignores an unknown current status' => [
            'amountPaid' => 0.00,
            'amountDue' => 100.00,
            'currentStatus' => 'bogus',
            'expectedStatus' => InvoicePaymentStatus::PENDING,
        ];

        yield 'Part payment is partially paid' => [
            'amountPaid' => 50.00,
            'amountDue' => 100.00,
            'currentStatus' => null,
            'expectedStatus' => InvoicePaymentStatus::PARTIALLY,
        ];

        yield 'Exact payment is paid in full' => [
            'amountPaid' => 100.00,
            'amountDue' => 100.00,
            'currentStatus' => null,
            'expectedStatus' => InvoicePaymentStatus::FULL,
        ];

        yield 'Float noise below a cent still counts as paid in full' => [
            'amountPaid' => 99.999,
            'amountDue' => 100.00,
            'currentStatus' => null,
            'expectedStatus' => InvoicePaymentStatus::FULL,
        ];

        yield 'Overpayment is paid in full' => [
            'amountPaid' => 150.00,
            'amountDue' => 100.00,
            'currentStatus' => InvoicePaymentStatus::PENDING,
            'expectedStatus' => InvoicePaymentStatus::FULL,
        ];
    }

    /**
     * Tests normalize.
     *
     * @param string|null $status
     * @param string|null $expected
     * @dataProvider normalizeDataProvider
     */
    #[DataProvider('normalizeDataProvider')]
    public function testNormalize(?string $status, ?string $expected): void
    {
        self::assertSame($expected, InvoicePaymentStatus::normalize($status));
    }

    /**
     * Provides the data sets for normalize data.
     *
     * @return \Generator<string, array<string, string|null>>
     */
    public static function normalizeDataProvider(): \Generator
    {
        yield 'null' => ['status' => null, 'expected' => null];
        yield 'empty' => ['status' => '', 'expected' => null];
        yield 'whitespace' => ['status' => '   ', 'expected' => null];
        yield 'known code' => ['status' => 'declined', 'expected' => InvoicePaymentStatus::DECLINED];
        yield 'known code, mixed case' => ['status' => 'Authorized', 'expected' => InvoicePaymentStatus::AUTHORIZED];
        yield 'legacy code, padded' => ['status' => ' PAID ', 'expected' => InvoicePaymentStatus::FULL];
        yield 'legacy partially_paid' => ['status' => 'partially_paid', 'expected' => InvoicePaymentStatus::PARTIALLY];
        yield 'legacy unpaid' => ['status' => 'unpaid', 'expected' => InvoicePaymentStatus::PENDING];
        yield 'unknown' => ['status' => 'refunded', 'expected' => null];
    }

    /**
     * Tests that every legacy code maps onto a code normalize() accepts.
     */
    public function testLegacyMapTargetsAreKnownCodes(): void
    {
        $map = InvoicePaymentStatus::legacyMap();

        self::assertNotEmpty($map);

        foreach ($map as $legacy => $code) {
            self::assertSame($code, InvoicePaymentStatus::normalize($legacy));
            self::assertSame(
                $code,
                InvoicePaymentStatus::normalize($code),
                sprintf('"%s" must be a known code', $code)
            );
        }
    }
}
