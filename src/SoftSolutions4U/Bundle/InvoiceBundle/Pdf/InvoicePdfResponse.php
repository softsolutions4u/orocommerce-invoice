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
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the HTTP response that delivers an invoice PDF.
 */
final class InvoicePdfResponse
{
    /**
     * Creates a non-cacheable PDF response with a safely encoded file name.
     *
     * @param Invoice $invoice
     * @param string $content
     * @param string $disposition Either HeaderUtils::DISPOSITION_ATTACHMENT or HeaderUtils::DISPOSITION_INLINE.
     * @return Response
     */
    public static function create(Invoice $invoice, string $content, string $disposition): Response
    {
        // Slashes are not allowed in a Content-Disposition file name, so they are replaced.
        $number = str_replace(['/', '\\'], '-', (string) ($invoice->getInvoiceNo() ?: $invoice->getId()));
        $filename = sprintf('invoice-%s.pdf', $number);
        $fallback = preg_replace('/[^A-Za-z0-9._-]/u', '_', $filename) ?? 'invoice.pdf';

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition($disposition, $filename, $fallback)
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
