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
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePaymentLineItem;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\FrontendInvoicePaymentLineItemsGridListener;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\InvoicePaymentFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Provider\FrontendInvoiceProvider;
use SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\ExtendEntityInitializerTrait;
use Oro\Bundle\DataGridBundle\Datagrid\Datagrid;
use Oro\Bundle\DataGridBundle\Datagrid\DatagridInterface;
use Oro\Bundle\DataGridBundle\Datagrid\ParameterBag;
use Oro\Bundle\DataGridBundle\Datasource\ArrayDatasource\ArrayDatasource;
use Oro\Bundle\DataGridBundle\Datasource\DatasourceInterface;
use Oro\Bundle\DataGridBundle\Event\BuildAfter;
use Oro\Bundle\DataGridBundle\Exception\UnexpectedTypeException;
use Oro\Component\Testing\Unit\EntityTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FrontendInvoicePaymentLineItemsGridListener.
 */
class FrontendInvoicePaymentLineItemsGridListenerTest extends TestCase
{
    use EntityTrait;
    use ExtendEntityInitializerTrait;

    /**
     * Tests invoices are sorted by due date.
     *
     * @param array<int,array<string,mixed>> $invoiceData
     * @param array<int> $expectedInvoicesIds
     * @param int $expectedInvoiceCount
     * @throws \Exception
     *
     * @dataProvider getInvoiceDataWithDueDates
     */
    #[DataProvider('getInvoiceDataWithDueDates')]
    public function testInvoicesAreSortedByDueDate(
        array $invoiceData,
        int $expectedInvoiceCount,
        array $expectedInvoicesIds,
    ): void {
        $datagrid = $this->getMockBuilder(Datagrid::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDatasource','getParameters'])
            ->getMock();

        $invoices = [];
        foreach ($invoiceData as $invoiceDatum) {
            $invoices[] = $this->getEntity(Invoice::class, [
                'id' => $invoiceDatum['id'],
                'dueDate' => new \DateTime($invoiceDatum['dueDate']),
                'status' => Invoice::STATUS_OPEN,
            ]);
        }

        $frontendInvoiceProvider = $this->getMockBuilder(FrontendInvoiceProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrentCustomerUnpaidInvoices'])
            ->getMock();
        $frontendInvoiceProvider->expects($this->any())
            ->method('getCurrentCustomerUnpaidInvoices')
            ->willReturn($invoices);

        $parameters = $this->getMockBuilder(ParameterBag::class)
            ->onlyMethods(['get'])
            ->getMock();
        $parameters->expects($this->any())
            ->method('get')
            ->with('invoicePayment')
            ->willReturn($this->getEntity(InvoicePayment::class));

        $datagrid->expects($this->any())
            ->method('getDatasource')
            ->willReturn(new ArrayDatasource());
        $datagrid->expects($this->any())
            ->method('getParameters')
            ->willReturn($parameters);

        $listener = new FrontendInvoicePaymentLineItemsGridListener(
            $this->createMock(InvoicePaymentFactory::class),
            $frontendInvoiceProvider,
        );

        // Fire event
        $event = new BuildAfter($datagrid);
        $listener->onBuildAfter($event);

        /** @var ArrayDatasource $datasource */
        $datasource = $event->getDatagrid()->getDatasource();
        $this->assertInstanceOf(ArrayDatasource::class, $datasource);

        $invoices = $datasource->getArraySource();
        $this->assertIsArray($invoices);
        $this->assertCount($expectedInvoiceCount, $invoices);

        $this->assertArrayHasKey('id', $invoices[0]);
        $this->assertArrayHasKey('dueDate', $invoices[0]);

        foreach ($expectedInvoicesIds as $key => $invoiceId) {
            $this->assertEquals($invoiceId, $invoices[$key]['id']);
        }
    }

    /**
     * Tests that a non-array datasource is rejected.
     */
    public function testRejectsNonArrayDatasource(): void
    {
        $datagrid = $this->createMock(Datagrid::class);
        $datagrid->method('getDatasource')->willReturn($this->createMock(DatasourceInterface::class));

        $listener = new FrontendInvoicePaymentLineItemsGridListener(
            $this->createMock(InvoicePaymentFactory::class),
            $this->createMock(FrontendInvoiceProvider::class)
        );

        $this->expectException(UnexpectedTypeException::class);

        $listener->onBuildAfter(new BuildAfter($datagrid));
    }

    /**
     * Tests that a missing invoicePayment parameter clears the datasource.
     */
    public function testMissingInvoicePaymentClearsTheDatasource(): void
    {
        $datasource = new ArrayDatasource();
        $datasource->setArraySource([['id' => 1]]);

        $parameters = $this->createMock(ParameterBag::class);
        $parameters->method('get')->with('invoicePayment')->willReturn(null);

        $datagrid = $this->createMock(Datagrid::class);
        $datagrid->method('getDatasource')->willReturn($datasource);
        $datagrid->method('getParameters')->willReturn($parameters);

        $listener = new FrontendInvoicePaymentLineItemsGridListener(
            $this->createMock(InvoicePaymentFactory::class),
            $this->createMock(FrontendInvoiceProvider::class)
        );

        $listener->onBuildAfter(new BuildAfter($datagrid));

        self::assertSame([], $datasource->getArraySource());
    }

    /**
     * Tests that a row for an invoice with a line item is enabled and carries the line item amount.
     */
    public function testRowsForInvoicesWithLineItemsAreEnabled(): void
    {
        $invoice = $this->getEntity(Invoice::class, [
            'id' => 42,
            'dueDate' => new \DateTime('2026-10-01'),
            'status' => Invoice::STATUS_OPEN,
        ]);

        $payment = $this->getEntity(InvoicePayment::class);

        $lineItem = $this->getEntity(InvoicePaymentLineItem::class, [
            'invoice' => $invoice,
            'amount' => 25.00,
        ]);
        $payment->addLineItem($lineItem);

        $provider = $this->createMock(FrontendInvoiceProvider::class);
        $provider->method('getCurrentCustomerUnpaidInvoices')->willReturn([$invoice]);

        $parameters = $this->createMock(ParameterBag::class);
        $parameters->method('get')->with('invoicePayment')->willReturn($payment);

        $datagrid = $this->createMock(Datagrid::class);
        $datagrid->method('getDatasource')->willReturn(new ArrayDatasource());
        $datagrid->method('getParameters')->willReturn($parameters);

        $listener = new FrontendInvoicePaymentLineItemsGridListener(
            $this->createMock(InvoicePaymentFactory::class),
            $provider
        );
        $listener->onBuildAfter(new BuildAfter($datagrid));

        $source = $datagrid->getDatasource()->getArraySource();

        self::assertCount(1, $source);
        self::assertTrue($source[0]['isEnabled'], 'A row with a line item must be enabled');
        self::assertSame(25.00, $source[0]['paymentAmount']);
    }

    /**
     * Tests that a row for an invoice without a line item is disabled and falls back to the balance.
     */
    public function testRowsForInvoicesWithoutLineItemsAreDisabledAndFallBackToTheBalance(): void
    {
        $invoice = $this->getEntity(Invoice::class, [
            'id' => 42,
            'dueDate' => new \DateTime('2026-10-01'),
            'status' => Invoice::STATUS_OPEN,
            'amount' => 100.00,
            'amountPaid' => 40.00,
        ]);

        $payment = $this->getEntity(InvoicePayment::class);

        $provider = $this->createMock(FrontendInvoiceProvider::class);
        $provider->method('getCurrentCustomerUnpaidInvoices')->willReturn([$invoice]);

        $parameters = $this->createMock(ParameterBag::class);
        $parameters->method('get')->with('invoicePayment')->willReturn($payment);

        $datagrid = $this->createMock(Datagrid::class);
        $datagrid->method('getDatasource')->willReturn(new ArrayDatasource());
        $datagrid->method('getParameters')->willReturn($parameters);

        $listener = new FrontendInvoicePaymentLineItemsGridListener(
            $this->createMock(InvoicePaymentFactory::class),
            $provider
        );
        $listener->onBuildAfter(new BuildAfter($datagrid));

        $source = $datagrid->getDatasource()->getArraySource();

        self::assertCount(1, $source);
        self::assertFalse($source[0]['isEnabled'], 'A row without a line item must be disabled');
        self::assertSame(60.00, $source[0]['paymentAmount'], 'Falls back to the invoice balance');
    }

    /**
     * Tests the deprecated getParameter() throws for missing parameters while getParameterOrNull() returns null.
     */
    public function testDeprecatedGetParameterThrowsForMissingParameter(): void
    {
        $listener = new class (
            $this->createMock(InvoicePaymentFactory::class),
            $this->createMock(FrontendInvoiceProvider::class)
        ) extends FrontendInvoicePaymentLineItemsGridListener {
            /**
             * Returns the call get parameter.
             *
             * @param DatagridInterface $datagrid
             * @param string $name
             * @return mixed
             */
            public function callGetParameter(DatagridInterface $datagrid, string $name): mixed
            {
                return $this->getParameter($datagrid, $name);
            }

            /**
             * Returns the call get parameter or null.
             *
             * @param DatagridInterface $datagrid
             * @param string $name
             * @return mixed
             */
            public function callGetParameterOrNull(DatagridInterface $datagrid, string $name): mixed
            {
                return $this->getParameterOrNull($datagrid, $name);
            }
        };

        $parameters = $this->createMock(ParameterBag::class);
        $parameters->method('get')->willReturn(null);

        $datagrid = $this->createMock(Datagrid::class);
        $datagrid->method('getParameters')->willReturn($parameters);

        // getParameterOrNull returns null without throwing.
        self::assertNull($listener->callGetParameterOrNull($datagrid, 'missing'));

        // getParameter throws for null.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Parameter "missing" must be set');

        $listener->callGetParameter($datagrid, 'missing');
    }

    /**
     * Returns the invoice data with due dates.
     *
     * @return \Generator<string,array<string,int|array<int,mixed>>>
     */
    public static function getInvoiceDataWithDueDates(): \Generator
    {
        yield 'Five invoices with different due dates' => [
            'invoiceData' => [
                ['id' => 12, 'dueDate' => '2022-04-01'],
                ['id' => 34, 'dueDate' => '2022-03-30'],
                ['id' => 56, 'dueDate' => '2022-05-01'],
                ['id' => 78, 'dueDate' => '2021-01-01'],
                ['id' => 90, 'dueDate' => '2022-12-31'],
            ],
            'expectedInvoiceCount' => 5,
            'expectedInvoicesIds' => [
                // In order of ascending Due Date (the oldest first)
                78,
                34,
                12,
                56,
                90,
            ],
        ];
    }
}
