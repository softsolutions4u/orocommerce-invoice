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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Event;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Invoice payment success event.
 */
class InvoicePaymentSuccessEvent extends Event
{
    public const NAME = 'softsolutions4u.invoice.payment_success';

    /** @var InvoicePayment $invoicePayment */
    protected InvoicePayment $invoicePayment;

    /** @var array<string,mixed> */
    protected array $response;

    /**
     * Creates a new InvoicePaymentSuccessEvent instance.
     *
     * @param array<string,mixed> $paymentResponse
     * @param InvoicePayment $invoicePayment
     */
    public function __construct(
        InvoicePayment $invoicePayment,
        array $paymentResponse
    ) {
        $this->invoicePayment = $invoicePayment;
        $this->response = $paymentResponse;
    }

    /**
     * Returns the invoice payment.
     *
     * @return InvoicePayment
     */
    public function getInvoicePayment(): InvoicePayment
    {
        return $this->invoicePayment;
    }

    /**
     * Sets the invoice payment.
     *
     * @param InvoicePayment $invoicePayment
     * @return InvoicePaymentSuccessEvent
     */
    public function setInvoicePayment(InvoicePayment $invoicePayment): InvoicePaymentSuccessEvent
    {
        $this->invoicePayment = $invoicePayment;

        return $this;
    }

    /**
     * Returns the response.
     *
     * @return array<string,mixed>
     */
    public function getResponse(): array
    {
        return $this->response;
    }
}
