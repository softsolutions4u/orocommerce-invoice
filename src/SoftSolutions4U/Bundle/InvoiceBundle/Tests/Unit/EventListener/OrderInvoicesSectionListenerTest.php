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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\EventListener;

use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\UIBundle\Event\BeforeListRenderEvent;
use Oro\Bundle\UIBundle\View\ScrollData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SoftSolutions4U\Bundle\InvoiceBundle\EventListener\OrderInvoicesSectionListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Unit tests for the listener that adds the Invoices section to order pages.
 */
class OrderInvoicesSectionListenerTest extends TestCase
{
    /** @var AuthorizationCheckerInterface&MockObject $authorizationChecker */
    private AuthorizationCheckerInterface&MockObject $authorizationChecker;

    /** @var Environment&MockObject $twig */
    private Environment&MockObject $twig;

    /** @var OrderInvoicesSectionListener $listener */
    private OrderInvoicesSectionListener $listener;

    /**
     * Sets up the test fixture.
     */
    protected function setUp(): void
    {
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->twig = $this->createMock(Environment::class);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => 'translated:' . $id);

        $this->listener = new OrderInvoicesSectionListener($this->authorizationChecker, $translator);
    }

    /**
     * Tests that the view page gets an Invoices section with the rendered grid.
     */
    public function testAddsSectionToOrderView(): void
    {
        $this->authorizationChecker->method('isGranted')->with('softsolutions4u_invoice_view')->willReturn(true);
        $this->twig->expects(self::once())
            ->method('render')
            ->with('@SoftSolutions4UInvoice/Order/invoices_tab.html.twig', self::arrayHasKey('entity'))
            ->willReturn('<div>grid</div>');

        $scrollData = new ScrollData();
        $this->listener->onOrderView($this->createEvent($this->createOrder(3), $scrollData));

        $data = $scrollData->getData();
        self::assertCount(1, $data[ScrollData::DATA_BLOCKS]);

        $block = reset($data[ScrollData::DATA_BLOCKS]);
        self::assertSame('translated:softsolutions4u.invoice.order.tab.invoices.label', $block[ScrollData::TITLE]);
        self::assertSame(-10, $block[ScrollData::PRIORITY]);
        self::assertStringContainsString(
            '<div>grid</div>',
            json_encode($block[ScrollData::SUB_BLOCKS], JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Tests that the edit page gets the section with the edit-page priority.
     */
    public function testAddsSectionToOrderEdit(): void
    {
        $this->authorizationChecker->method('isGranted')->willReturn(true);
        $this->twig->method('render')->willReturn('<div>grid</div>');

        $scrollData = new ScrollData();
        $this->listener->onOrderEdit($this->createEvent($this->createOrder(3), $scrollData));

        $block = reset($scrollData->getData()[ScrollData::DATA_BLOCKS]);
        self::assertSame(950, $block[ScrollData::PRIORITY]);
    }

    /**
     * Tests that nothing is added when the user may not view invoices.
     */
    public function testSkipsWithoutPermission(): void
    {
        $this->authorizationChecker->method('isGranted')->willReturn(false);
        $this->twig->expects(self::never())->method('render');

        $scrollData = new ScrollData();
        $this->listener->onOrderView($this->createEvent($this->createOrder(3), $scrollData));

        self::assertEmpty($scrollData->getData()[ScrollData::DATA_BLOCKS] ?? []);
    }

    /**
     * Tests that nothing is added on the order create page (order without id).
     */
    public function testSkipsNewOrder(): void
    {
        $this->authorizationChecker->method('isGranted')->willReturn(true);
        $this->twig->expects(self::never())->method('render');

        $scrollData = new ScrollData();
        $this->listener->onOrderEdit($this->createEvent(new Order(), $scrollData));

        self::assertEmpty($scrollData->getData()[ScrollData::DATA_BLOCKS] ?? []);
    }

    /**
     * Tests that nothing is added for entities other than orders.
     */
    public function testSkipsOtherEntities(): void
    {
        $this->twig->expects(self::never())->method('render');

        $scrollData = new ScrollData();
        $this->listener->onOrderView($this->createEvent(new \stdClass(), $scrollData));

        self::assertEmpty($scrollData->getData()[ScrollData::DATA_BLOCKS] ?? []);
    }

    /**
     * Returns an order with the given id.
     *
     * @param int $id
     * @return Order
     */
    private function createOrder(int $id): Order
    {
        $order = new Order();
        $reflection = new \ReflectionProperty(Order::class, 'id');
        $reflection->setValue($order, $id);

        return $order;
    }

    /**
     * Returns a scroll-data event for the given entity.
     *
     * @param object $entity
     * @param ScrollData $scrollData
     * @return BeforeListRenderEvent
     */
    private function createEvent(object $entity, ScrollData $scrollData): BeforeListRenderEvent
    {
        return new BeforeListRenderEvent($this->twig, $scrollData, $entity);
    }
}
