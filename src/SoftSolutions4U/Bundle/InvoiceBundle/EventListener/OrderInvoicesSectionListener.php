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

namespace SoftSolutions4U\Bundle\InvoiceBundle\EventListener;

use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\UIBundle\Event\BeforeListRenderEvent;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds the "Invoices" section to the backoffice order view and edit pages.
 *
 * Uses Oro's scroll-data events (oro_ui.scroll_data.before.order-view and
 * oro_ui.scroll_data.before.order-edit), so Oro's own order templates are
 * rendered unchanged and keep working across OroCommerce upgrades.
 */
class OrderInvoicesSectionListener
{
    /** Template that renders the order's invoice grid. */
    private const TEMPLATE = '@SoftSolutions4UInvoice/Order/invoices_tab.html.twig';

    /** Section position on the view page (payment history is -20). */
    private const VIEW_PRIORITY = -10;

    /** Section position on the edit page. */
    private const EDIT_PRIORITY = 950;

    /** @var AuthorizationCheckerInterface $authorizationChecker */
    private AuthorizationCheckerInterface $authorizationChecker;

    /** @var TranslatorInterface $translator */
    private TranslatorInterface $translator;

    /**
     * Creates a new OrderInvoicesSectionListener instance.
     *
     * @param AuthorizationCheckerInterface $authorizationChecker
     * @param TranslatorInterface $translator
     */
    public function __construct(AuthorizationCheckerInterface $authorizationChecker, TranslatorInterface $translator)
    {
        $this->authorizationChecker = $authorizationChecker;
        $this->translator = $translator;
    }

    /**
     * Adds the Invoices section to the order view page.
     *
     * @param BeforeListRenderEvent $event
     */
    public function onOrderView(BeforeListRenderEvent $event): void
    {
        $this->addInvoicesSection($event, self::VIEW_PRIORITY);
    }

    /**
     * Adds the Invoices section to the order edit page (not to the create page).
     *
     * @param BeforeListRenderEvent $event
     */
    public function onOrderEdit(BeforeListRenderEvent $event): void
    {
        $this->addInvoicesSection($event, self::EDIT_PRIORITY);
    }

    /**
     * Renders the invoice grid and adds it as a new section.
     *
     * @param BeforeListRenderEvent $event
     * @param int $priority
     */
    private function addInvoicesSection(BeforeListRenderEvent $event, int $priority): void
    {
        $order = $event->getEntity();
        if (!$order instanceof Order || null === $order->getId()) {
            return;
        }

        if (!$this->authorizationChecker->isGranted('softsolutions4u_invoice_view')) {
            return;
        }

        $html = $event->getEnvironment()->render(self::TEMPLATE, ['entity' => $order]);

        $scrollData = $event->getScrollData();
        $blockId = $scrollData->addBlock(
            $this->translator->trans('softsolutions4u.invoice.order.tab.invoices.label'),
            $priority
        );
        $subBlockId = $scrollData->addSubBlock($blockId);
        $scrollData->addSubBlockData($blockId, $subBlockId, $html);
    }
}
