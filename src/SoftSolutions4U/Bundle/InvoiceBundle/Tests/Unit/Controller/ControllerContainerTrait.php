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

namespace SoftSolutions4U\Bundle\InvoiceBundle\Tests\Unit\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use SoftSolutions4U\Bundle\InvoiceBundle\Entity\Invoice;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;

/**
 * Gives a controller the few framework services AbstractController helpers need:
 * the logged-in user, access checks, flash messages and URL generation.
 *
 * Generated URLs are "/{route}?{query}", so redirects can be asserted exactly.
 */
trait ControllerContainerTrait
{
    /** @var Session $session */
    private Session $session;

    /** @var Request $request */
    private Request $request;

    /**
     * Attaches a mock container to the controller.
     *
     * Symfony 7 removed AbstractController::setContainer() and
     * getContainer(), so the protected `container` property is written
     * via reflection.
     *
     * @param AbstractController $controller
     * @param UserInterface|null $user
     * @param bool $authenticated
     */
    private function attachContainer(
        AbstractController $controller,
        ?UserInterface $user,
        bool $authenticated = true
    ): void {
        $tokenStorage = new TokenStorage();
        if (null !== $user) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'frontend', []));
        }

        // Invoice VIEW behaves like a customer-level storefront ACL: only invoices of the user's own customer.
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(
            static function (mixed $attribute, mixed $subject = null) use ($user, $authenticated): bool {
                if ('VIEW' !== $attribute || !$subject instanceof Invoice) {
                    return $authenticated;
                }

                $invoiceCustomer = $subject->getCustomer();
                $userCustomer = $user instanceof CustomerUser ? $user->getCustomer() : null;

                return null !== $invoiceCustomer
                    && null !== $userCustomer
                    && $userCustomer->getId() === $invoiceCustomer->getId();
            }
        );

        $this->session = new Session(new MockArraySessionStorage());
        $this->request = new Request();
        $this->request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($this->request);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => '/' . $route
                . ($parameters ? '?' . http_build_query($parameters) : '')
        );

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('security.authorization_checker', $authorizationChecker);
        $container->set('request_stack', $requestStack);
        $container->set('router', $router);

        self::setControllerContainer($controller, $container);
    }

    /**
     * Returns the mock container attached to a controller.
     *
     * @param AbstractController $controller
     * @return Container
     */
    private function controllerContainer(AbstractController $controller): Container
    {
        $property = new \ReflectionProperty(AbstractController::class, 'container');
        $property->setAccessible(true);

        /** @var Container $container */
        $container = $property->getValue($controller);

        return $container;
    }

    /**
     * Writes to AbstractController::$container via reflection.
     *
     * @param AbstractController $controller
     * @param Container $container
     */
    private static function setControllerContainer(AbstractController $controller, Container $container): void
    {
        $property = new \ReflectionProperty(AbstractController::class, 'container');
        $property->setAccessible(true);
        $property->setValue($controller, $container);
    }

    /**
     * Returns the flashes.
     *
     * @param string $type
     * @return array<int, string>
     */
    private function flashes(string $type): array
    {
        return $this->session->getFlashBag()->peek($type);
    }
}
