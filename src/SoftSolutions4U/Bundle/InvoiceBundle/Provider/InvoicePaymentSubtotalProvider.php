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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Provider;

use SoftSolutions4U\Bundle\InvoiceBundle\Entity\InvoicePayment;
use Oro\Bundle\PricingBundle\SubtotalProcessor\Model\Subtotal;
use Oro\Bundle\PricingBundle\SubtotalProcessor\Model\SubtotalProviderInterface;
use Oro\Bundle\PricingBundle\SubtotalProcessor\Provider\AbstractSubtotalProvider;
use Oro\Bundle\PricingBundle\SubtotalProcessor\Provider\SubtotalProviderConstructorArguments;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Invoice payment subtotal provider.
 */
class InvoicePaymentSubtotalProvider extends AbstractSubtotalProvider implements SubtotalProviderInterface
{
    public const TYPE = 'invoice_payment_subtotal';
    public const SUBTOTAL_SORT_ORDER = 200;

    /** @var TranslatorInterface $translator */
    private TranslatorInterface $translator;

    /**
     * Creates a new InvoicePaymentSubtotalProvider instance.
     *
     * @param SubtotalProviderConstructorArguments $arguments
     * @param TranslatorInterface $translator
     */
    public function __construct(
        SubtotalProviderConstructorArguments $arguments,
        TranslatorInterface $translator,
    ) {
        parent::__construct($arguments);
        $this->translator = $translator;
    }

    /**
     * Returns the subtotal.
     *
     * @param mixed $entity
     * @return Subtotal|null
     */
    public function getSubtotal(mixed $entity): ?Subtotal
    {
        if (!$this->isSupported($entity)) {
            throw new \InvalidArgumentException('Entity not supported for provider');
        }

        /** @var InvoicePayment $entity */
        $subtotal = new Subtotal();
        $subtotal
            ->setType(self::TYPE)
            ->setSortOrder(self::SUBTOTAL_SORT_ORDER)
            ->setLabel($this->translator->trans('softsolutions4u.invoice.invoicepayment.subtotal.label'))
            ->setVisible(true)
            ->setCurrency($entity->getCurrency())
            ->setAmount($entity->getAmount());

        return $subtotal;
    }

    /**
     * Returns whether it is supported.
     *
     * @param mixed $entity
     * @return bool
     */
    public function isSupported(mixed $entity): bool
    {
        return $entity instanceof InvoicePayment;
    }
}
