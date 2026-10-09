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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Form\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\FormBundle\Form\Handler\FormHandlerInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use SoftSolutions4U\Bundle\InvoiceBundle\Event\InvoicePaymentSuccessEvent;
use SoftSolutions4U\Bundle\InvoiceBundle\Manager\PaymentManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Submits the storefront invoice payment form and runs the selected payment method.
 */
class InvoicePaymentFormHandler implements FormHandlerInterface
{
    /**
     * Creates a new InvoicePaymentFormHandler instance.
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param PaymentManager $paymentManager
     * @param ManagerRegistry $registry
     */
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly PaymentManager $paymentManager,
        private readonly ManagerRegistry $registry,
    ) {
    }

    /**
     * Processes the payment and fires InvoicePaymentSuccessEvent when money was taken.
     *
     * A payment that awaits confirmation (Payment Term, Money Order, ...) is only stored and flagged;
     * the invoice balance is not changed until an administrator confirms it.
     *
     * @param mixed $data
     * @param FormInterface<mixed> $form
     * @param Request $request
     * @return bool
     */
    public function process($data, FormInterface $form, Request $request): bool
    {
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return false;
        }

        if (!$data instanceof InvoicePayment) {
            return true;
        }

        $this->paymentManager->processPayment($data, $request);

        if ($this->paymentManager->isPendingConfirmation()) {
            $data->setPendingConfirmation(true);

            $manager = $this->registry->getManagerForClass(InvoicePayment::class);
            $manager->persist($data);
            $manager->flush();

            return false;
        }

        if (!$this->paymentManager->isSuccessful()) {
            return false;
        }

        $this->eventDispatcher->dispatch(
            new InvoicePaymentSuccessEvent($data, $this->paymentManager->getResponse()),
            InvoicePaymentSuccessEvent::NAME
        );

        return true;
    }
}
