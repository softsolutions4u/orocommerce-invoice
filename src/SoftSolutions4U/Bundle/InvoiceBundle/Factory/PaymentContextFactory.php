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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Factory;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\PaymentBundle\Context\Builder\Factory\PaymentContextBuilderFactoryInterface;
use Oro\Bundle\PaymentBundle\Context\PaymentContextInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Payment context factory.
 */
class PaymentContextFactory
{
    /**
     * Creates a new PaymentContextFactory instance.
     *
     * @param PaymentContextBuilderFactoryInterface $builderFactory
     * @param Security $security
     */
    public function __construct(
        private PaymentContextBuilderFactoryInterface $builderFactory,
        private Security $security
    ) {
    }

    /**
     * Builds the payment context for the given invoice payment.
     *
     * @param InvoicePayment $payment
     * @return PaymentContextInterface
     */
    public function create(InvoicePayment $payment): PaymentContextInterface
    {
        $customer = $payment->getCustomer();

        if (!$customer) {
            throw new \LogicException(
                sprintf(
                    'Invoice payment #%s does not have an associated customer.',
                    $payment->getId()
                )
            );
        }

        $customerUser = $payment->getCustomerUser();

        if (!$customerUser) {
            $user = $this->security->getUser();

            if ($user instanceof CustomerUser) {
                $customerUser = $user;
            }
        }

        $builder = $this->builderFactory->createPaymentContextBuilder($payment, $payment->getId());

        $builder
            ->setCustomer($customer)
            ->setCurrency($payment->getCurrency());

        if ($customerUser) {
            $builder->setCustomerUser($customerUser);

            $website = $customerUser->getWebsite();

            if ($website) {
                $builder->setWebsite($website);
            }
        }

        return $builder->getResult();
    }
}
