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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Layout\DataProvider;

use Oro\Bundle\PaymentBundle\Context\PaymentContextInterface;
use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Factory\PaymentContextFactory;
use SoftSolutions4U\Bundle\InvoiceBundle\Layout\DataProvider\PaymentContextProvider;

class PaymentContextProviderTest extends TestCase
{
    public function testGetContextDelegatesToTheFactory(): void
    {
        $invoicePayment = new InvoicePayment();
        $expected = $this->createMock(PaymentContextInterface::class);

        $factory = $this->createMock(PaymentContextFactory::class);
        $factory->expects(self::once())
            ->method('create')
            ->with($invoicePayment)
            ->willReturn($expected);

        $provider = new PaymentContextProvider($factory);

        self::assertSame($expected, $provider->getContext($invoicePayment));
    }
}
