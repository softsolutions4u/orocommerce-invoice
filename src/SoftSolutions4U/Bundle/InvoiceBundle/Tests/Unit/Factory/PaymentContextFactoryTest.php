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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Factory;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\PaymentContextFactory;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\PaymentBundle\Context\Builder\Factory\PaymentContextBuilderFactoryInterface;
use Oro\Bundle\PaymentBundle\Context\Builder\PaymentContextBuilderInterface;
use Oro\Bundle\PaymentBundle\Context\PaymentContextInterface;
use Oro\Bundle\WebsiteBundle\Entity\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Unit tests for building the payment context used to pick payment methods for an invoice payment.
 */
class PaymentContextFactoryTest extends TestCase
{
    private PaymentContextBuilderInterface&MockObject $builder;
    private PaymentContextBuilderFactoryInterface&MockObject $builderFactory;
    private Security&MockObject $security;
    private PaymentContextInterface&MockObject $context;

    /** @var PaymentContextFactory $factory */
    private PaymentContextFactory $factory;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->context = $this->createMock(PaymentContextInterface::class);

        $this->builder = $this->createMock(PaymentContextBuilderInterface::class);
        $this->builder->method('setCustomer')->willReturnSelf();
        $this->builder->method('setCurrency')->willReturnSelf();
        $this->builder->method('setCustomerUser')->willReturnSelf();
        $this->builder->method('setWebsite')->willReturnSelf();
        $this->builder->method('getResult')->willReturn($this->context);

        $this->builderFactory = $this->createMock(PaymentContextBuilderFactoryInterface::class);
        $this->builderFactory->method('createPaymentContextBuilder')->willReturn($this->builder);

        $this->security = $this->createMock(Security::class);

        $this->factory = new PaymentContextFactory($this->builderFactory, $this->security);
    }

    /**
     * Tests the context for a payment made by a known customer user on a website.
     */
    public function testBuildsContextFromThePayment(): void
    {
        $customer = new Customer();
        $website = new Website();
        $customerUser = new CustomerUser();
        $customerUser->setWebsite($website);

        $payment = $this->payment($customer, $customerUser);

        $this->builder->expects(self::once())->method('setCustomer')->with($customer)->willReturnSelf();
        $this->builder->expects(self::once())->method('setCurrency')->with('USD')->willReturnSelf();
        $this->builder->expects(self::once())->method('setCustomerUser')->with($customerUser)->willReturnSelf();
        $this->builder->expects(self::once())->method('setWebsite')->with($website)->willReturnSelf();
        $this->security->expects(self::never())->method('getUser');

        self::assertSame($this->context, $this->factory->create($payment));
    }

    /**
     * Tests that the logged-in storefront user is used when the payment has none.
     */
    public function testFallsBackToTheLoggedInCustomerUser(): void
    {
        $loggedIn = new CustomerUser();
        $this->security->method('getUser')->willReturn($loggedIn);

        $this->builder->expects(self::once())->method('setCustomerUser')->with($loggedIn)->willReturnSelf();
        $this->builder->expects(self::never())->method('setWebsite');

        $this->factory->create($this->payment(new Customer(), null));
    }

    /**
     * Tests that a back-office user is never used as the paying customer user.
     */
    public function testIgnoresANonCustomerUser(): void
    {
        $this->security->method('getUser')->willReturn(null);
        $this->builder->expects(self::never())->method('setCustomerUser');

        self::assertSame($this->context, $this->factory->create($this->payment(new Customer(), null)));
    }

    /**
     * Tests that a payment without a customer cannot get a context.
     */
    public function testRequiresACustomer(): void
    {
        $this->builderFactory->expects(self::never())->method('createPaymentContextBuilder');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not have an associated customer');

        $this->factory->create(new InvoicePayment());
    }

    /**
     * Returns the payment.
     *
     * @param Customer $customer
     * @param CustomerUser|null $customerUser
     * @return InvoicePayment
     */
    private function payment(Customer $customer, ?CustomerUser $customerUser): InvoicePayment
    {
        $payment = new InvoicePayment();
        $payment->setCustomer($customer);
        if (null !== $customerUser) {
            $payment->setCustomerUser($customerUser);
        }
        $payment->setCurrency('USD');

        return $payment;
    }
}
