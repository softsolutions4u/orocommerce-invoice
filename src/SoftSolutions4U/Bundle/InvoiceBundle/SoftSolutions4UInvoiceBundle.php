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

namespace SoftSolutions4U\Bundle\InvoiceBundle;

use SoftSolutions4U\Bundle\InvoiceBundle\DependencyInjection\SoftSolutions4UInvoiceExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * SoftSolutions4U OroCommerce Invoice bundle.
 *
 * Main Symfony bundle class for the SoftSolutions4U Invoice module.
 */
class SoftSolutions4UInvoiceBundle extends Bundle
{
    /**
     * Returns the bundle extension; overridden because its alias differs from the default "soft_solutions4_u_invoice".
     *
     * @return ExtensionInterface|null
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new SoftSolutions4UInvoiceExtension();
        }

        return $this->extension ?: null;
    }
}
