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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Builder;

use SoftSolutions4U\Bundle\InvoiceBundle\Builder\InvoiceBuilder;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use SoftSolutions4U\Bundle\InvoiceBundle\Generator\InvoiceNumberGenerator;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\InvoiceLineItemManager;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PaymentBundle\Entity\PaymentStatus;
use Oro\Bundle\PaymentBundle\Manager\PaymentStatusManager;
use Oro\Bundle\PaymentBundle\Provider\PaymentTransactionProvider;
use Oro\Bundle\TaxBundle\Model\Result;
use Oro\Bundle\TaxBundle\Model\ResultElement;
use Oro\Bundle\TaxBundle\Provider\TaxProviderInterface;
use Oro\Bundle\TaxBundle\Provider\TaxProviderRegistry;
use PHPUnit\Framework\TestCase;
use Oro\Bundle\PaymentTermBundle\Provider\PaymentTermProviderInterface;
use Oro\Bundle\PaymentTermBundle\Entity\PaymentTerm;

/**
 * Unit tests for building a draft invoice from an order.
 */

/**
 * Additional tests exercising the registry-based payment method lookup
 * and the payment-term-driven due date. Appended as a separate class so the
 * main test file stays focused.
 */
class InvoiceBuilderRegistryTest extends TestCase
{
    private function order(): Order
    {
        $customer = new Customer();
        $customerUser = new CustomerUser();
        $customerUser->setCustomer($customer);

        $order = new Order();
        $order->setCustomer($customer);
        $order->setCustomerUser($customerUser);
        $order->setCurrency('USD');

        // Give the order an id so the registry path is taken.
        $reflection = new \ReflectionClass($order);
        while ($reflection && !$reflection->hasProperty('id')) {
            $reflection = $reflection->getParentClass();
        }
        if ($reflection) {
            $reflection->getProperty('id')->setValue($order, 42);
        }

        return $order;
    }

    private function createBuilder(
        ?\Doctrine\Persistence\ManagerRegistry $registry,
        ?PaymentTerm $paymentTerm = null
    ): InvoiceBuilder {
        $numberGenerator = $this->createMock(InvoiceNumberGenerator::class);
        $numberGenerator->method('generate')->willReturn('INV-2026-09-00019');

        $lineItemManager = $this->createMock(InvoiceLineItemManager::class);
        $lineItemManager->method('getOrderLevelDiscount')->willReturn(0.0);
        $lineItemManager->method('recalculateInvoiceTotals')->willReturnCallback(
            static function (Invoice $invoice): void {
                $invoice->setAmount(100.00);
            }
        );

        $taxResult = new Result();
        $shipping = ResultElement::create('18.00', '16.36', '1.64');
        $taxResult->offsetSet(Result::SHIPPING, $shipping);

        $taxProvider = $this->createMock(TaxProviderInterface::class);
        $taxProvider->method('getTax')->willReturn($taxResult);

        $taxRegistry = $this->createMock(TaxProviderRegistry::class);
        $taxRegistry->method('getEnabledProvider')->willReturn($taxProvider);

        $transactionProvider = $this->createMock(PaymentTransactionProvider::class);

        $paymentStatusManager = $this->createMock(PaymentStatusManager::class);

        $paymentStatusManager
            ->method('getPaymentStatus')
            ->willReturn(new PaymentStatus());

        $paymentTermProvider = $this->createMock(PaymentTermProviderInterface::class);
        $paymentTermProvider->method('getPaymentTerm')->willReturn($paymentTerm);

        return new InvoiceBuilder(
            $numberGenerator,
            $lineItemManager,
            $taxRegistry,
            $transactionProvider,
            $paymentStatusManager,
            $paymentTermProvider,
            $registry
        );
    }

    public function testRegistryPathReadsTheLatestPaymentMethod(): void
    {
        $query = $this->createMock(
            \Doctrine\ORM\Query::class
        );
        $query->method('getScalarResult')->willReturn([['paymentMethod' => 'payment_term_1']]);

        $qb = $this->createMock(
            \Doctrine\ORM\QueryBuilder::class
        );
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->method('createQueryBuilder')->willReturn($qb);

        $registry = $this->createMock(
            \Doctrine\Persistence\ManagerRegistry::class
        );
        $registry->method('getManagerForClass')->willReturn($em);

        $builder = $this->createBuilder($registry);
        $invoice = $builder->build($this->order());

        self::assertSame('payment_term_1', $invoice->getPaymentMethod());
    }

    public function testRegistryPathReturnsNullWhenNoTransactionsExist(): void
    {
        $query = $this->createMock(
            \Doctrine\ORM\Query::class
        );
        $query->method('getScalarResult')->willReturn([]);

        $qb = $this->createMock(
            \Doctrine\ORM\QueryBuilder::class
        );
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(
            \Doctrine\ORM\EntityManagerInterface::class
        );
        $em->method('createQueryBuilder')->willReturn($qb);

        $registry = $this->createMock(
            \Doctrine\Persistence\ManagerRegistry::class
        );
        $registry->method('getManagerForClass')->willReturn($em);

        $builder = $this->createBuilder($registry);
        $invoice = $builder->build($this->order());

        self::assertNull($invoice->getPaymentMethod());
    }

    public function testRegistryPathSkipsWhenOrderHasNoId(): void
    {
        $order = new Order();
        $order->setCurrency('USD');

        $registry = $this->createMock(
            \Doctrine\Persistence\ManagerRegistry::class
        );
        $registry->expects(self::never())->method('getManagerForClass');

        $builder = $this->createBuilder($registry);
        $invoice = $builder->build($order);

        self::assertNull($invoice->getPaymentMethod());
    }

    public function testDueDateUsesTheNumberInThePaymentTermLabel(): void
    {
        $paymentTerm = new PaymentTerm();
        $paymentTerm->setLabel('Net 45');

        $builder = $this->createBuilder(null, $paymentTerm);
        $invoice = $builder->build($this->order());

        $expected = (new \DateTime('today'))->modify('+45 days');
        self::assertSame($expected->format('Y-m-d'), $invoice->getDueDate()->format('Y-m-d'));
    }

    public function testDueDateFallsBackToThirtyDaysWhenNoPaymentTerm(): void
    {
        $builder = $this->createBuilder(null);
        $invoice = $builder->build($this->order());

        $expected = (new \DateTime('today'))->modify('+30 days');
        self::assertSame($expected->format('Y-m-d'), $invoice->getDueDate()->format('Y-m-d'));
    }
}
