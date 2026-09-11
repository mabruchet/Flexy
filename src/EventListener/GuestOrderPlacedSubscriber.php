<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\EventListener;

use FlexyBundle\Service\GuestOrderTracking;
use FlexyBundle\Service\PlacedOrderMemory;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutStepView;
use Thelia\Domain\Checkout\Service\CheckoutProgressionService;
use Thelia\Model\Order;

/**
 * Keeps the way back to an order placed without an account, and lets it go again.
 *
 * The core retires the guest from the session on ORDER_CART_CLEAR, so the confirmation
 * page that follows has no customer left to read. The tracking token is taken here,
 * while the session still says who placed the order, and kept for that page alone: it
 * opens one order, which is the same thing the buyer receives by email.
 *
 * It is dropped again the moment the session changes hands. A browser is not one person:
 * whoever signs out leaves it to whoever comes next, and a token left behind would show
 * them somebody else's order on the confirmation page.
 *
 * The same moment is the last one at which the tunnel that order was placed through can
 * be read, which is why the steps are taken here too.
 */
final readonly class GuestOrderPlacedSubscriber implements EventSubscriberInterface
{
    /**
     * Above the core listener that clears the guest, which sits at 128.
     */
    private const PRIORITY_BEFORE_THE_GUEST_IS_CLEARED = 256;

    public function __construct(
        private GuestOrderTracking $guestOrderTracking,
        private PlacedOrderMemory $placedOrderMemory,
        private RequestStack $requestStack,
        private CartFacade $cartFacade,
        private CheckoutProgressionService $progression,
    ) {
    }

    public function rememberGuestOrder(OrderEvent $event): void
    {
        $session = $this->session();

        if (!$session instanceof Session) {
            return;
        }

        $order = $event->getOrder();

        // An order with no id yet was never written, and a token signed on it would name
        // nothing. Nothing to remember, and nothing worth failing the checkout over.
        if (!$order instanceof Order || null === $order->getId()) {
            return;
        }

        // Remembered for every buyer, account or not: it is what tells the confirmation
        // page apart from someone who merely typed its url with a full cart.
        $this->placedOrderMemory->remember($order);
        $this->placedOrderMemory->rememberTheStepsWalked($this->stepsThisOrderWasPlacedThrough());

        if (!$session->isCustomerGuest()) {
            return;
        }

        $this->guestOrderTracking->rememberPlacedOrder($order);
    }

    public function forgetGuestOrder(): void
    {
        $this->guestOrderTracking->forgetPlacedOrder();
        $this->placedOrderMemory->forget();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_CART_CLEAR => ['rememberGuestOrder', self::PRIORITY_BEFORE_THE_GUEST_IS_CLEARED],
            TheliaEvents::CUSTOMER_LOGOUT => ['forgetGuestOrder', self::PRIORITY_BEFORE_THE_GUEST_IS_CLEARED],
        ];
    }

    /**
     * The tunnel this order went through, read while there is still a cart to read it
     * off: the very event this listens to is what replaces it with an empty one, and an
     * empty cart answers differently — it is not virtual, so the delivery step it was
     * never shown would come back on the confirmation page.
     *
     * A checkout that cannot even be described is no reason to fail a placement that has
     * already gone through, so anything the read throws leaves the memory empty and the
     * pages after it fall back to the cart in session.
     *
     * @return list<string>
     */
    private function stepsThisOrderWasPlacedThrough(): array
    {
        try {
            $cart = $this->cartFacade->getCartFromSession();

            if (null === $cart) {
                return [];
            }

            return array_map(
                static fn (CheckoutStepView $step): string => $step->code,
                $this->progression->activeSteps($cart),
            );
        } catch (PropelException) {
            return [];
        }
    }

    private function session(): ?Session
    {
        $request = $this->requestStack->getMainRequest();
        $session = $request?->hasSession() ? $request->getSession() : null;

        return $session instanceof Session ? $session : null;
    }
}
