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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Pdf;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;

/**
 * Invoice pdf generator interface.
 */
interface InvoicePdfGeneratorInterface
{
    /**
     * Renders the given invoice as a PDF and returns the raw file contents.
     *
     * @param Invoice $invoice
     * @return string
     */
    public function generate(Invoice $invoice): string;
}
