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
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\FrontendInvoicesGridActionsListener;
use Oro\Bundle\DataGridBundle\Datasource\ResultRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for when the storefront grid offers "Pay Now".
 */
class FrontendInvoicesGridActionsListenerTest extends TestCase
{
    /**
     * Tests pay action.
     *
     * @param string $status
     * @param mixed $balance
     * @param bool $expectedPay
     * @dataProvider payActionDataProvider
     */
    #[DataProvider('payActionDataProvider')]
    public function testPayAction(string $status, mixed $balance, bool $expectedPay): void
    {
        $record = new ResultRecord(['invoiceStatusId' => $status, 'balance' => $balance]);

        self::assertSame(
            ['pay' => $expectedPay],
            (new FrontendInvoicesGridActionsListener())->getActionConfiguration($record)
        );
    }

    /**
     * Provides the data sets for pay action data.
     *
     * @return \Generator<string, array{status: string, balance: mixed, expectedPay: bool}>
     */
    public static function payActionDataProvider(): \Generator
    {
        foreach (Invoice::UNPAID_STATUSES as $status) {
            yield $status . ' with a balance' => ['status' => $status, 'balance' => '25.00', 'expectedPay' => true];
            yield $status . ' fully paid' => ['status' => $status, 'balance' => '0.00', 'expectedPay' => false];
        }

        yield 'paid' => ['status' => Invoice::STATUS_PAID, 'balance' => '0.00', 'expectedPay' => false];
        yield 'paid, stale balance' => ['status' => Invoice::STATUS_PAID, 'balance' => '10.00', 'expectedPay' => false];
        yield 'draft is never payable' => [
            'status' => Invoice::STATUS_DRAFT,
            'balance' => '10.00',
            'expectedPay' => false,
        ];
        yield 'cancelled' => ['status' => Invoice::STATUS_CANCELLED, 'balance' => '10.00', 'expectedPay' => false];
        yield 'overpaid' => ['status' => Invoice::STATUS_POSTED, 'balance' => '-5.00', 'expectedPay' => false];
        yield 'numeric balance' => ['status' => Invoice::STATUS_POSTED, 'balance' => 0.01, 'expectedPay' => true];
    }
}
