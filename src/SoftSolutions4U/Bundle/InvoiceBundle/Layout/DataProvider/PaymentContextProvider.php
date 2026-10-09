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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Layout\DataProvider;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\PaymentContextFactory;
use Oro\Bundle\PaymentBundle\Context\PaymentContextInterface;

/**
 * Payment context provider.
 */
class PaymentContextProvider
{
    /** @var PaymentContextFactory $paymentContextFactory */
    protected PaymentContextFactory $paymentContextFactory;

    /**
     * Creates a new PaymentContextProvider instance.
     *
     * @param PaymentContextFactory $paymentContextFactory
     */
    public function __construct(PaymentContextFactory $paymentContextFactory)
    {
        $this->paymentContextFactory = $paymentContextFactory;
    }

    /**
     * Returns the context.
     *
     * @param InvoicePayment $entity
     * @return PaymentContextInterface
     */
    public function getContext(InvoicePayment $entity): PaymentContextInterface
    {
        return $this->paymentContextFactory->create($entity);
    }
}
