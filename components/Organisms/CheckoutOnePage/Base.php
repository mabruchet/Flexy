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

namespace FlexyBundle\Components\Organisms\CheckoutOnePage;

use FlexyBundle\Event\CheckoutEvents;
use FlexyBundle\Service\GuestCheckoutGate;
use Propel\Runtime\Exception\PropelException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\Service\CheckoutProgressionService;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\CheckoutStep;

/**
 * The whole checkout stacked on one screen, for a shop that asked for it.
 *
 * The steps, their order and their wordings are the configuration's, and the step
 * components are the very ones the several-screen checkout renders — this lays them out,
 * it does not reimplement them.
 *
 * It exists as a live component for one reason: a section has to open the moment the
 * cart has earned it. Picking a carrier in the delivery section is what makes the payment
 * one reachable, and on a page that is the whole tunnel that must not cost a reload. So
 * it listens to everything the step components emit and asks the progression again.
 *
 * A section the cart has not reached holds nothing at all. Not a hidden panel, not a
 * greyed-out one: its content is never rendered. A panel that is in the page is in the
 * page whatever it looks like, and the point of the lock is that the buyer has not got
 * there yet.
 */
#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /** @var list<array{code: string, title: string, componentName: string|null, unlocked: bool, needsIdentification: bool}>|null */
    private ?array $sections = null;

    public function __construct(
        private readonly CartFacade $cartFacade,
        private readonly CheckoutProgressionService $progression,
        private readonly GuestCheckoutGate $guestCheckoutGate,
        private readonly LangService $langService,
    ) {
    }

    #[LiveListener(CheckoutEvents::ADD_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::DELETE_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::UPDATE_ITEM_QUANTITY_EVENT)]
    #[LiveListener(CheckoutEvents::SET_DELIVERY_ORDER_ADDRESS_ID)]
    #[LiveListener(CheckoutEvents::SET_DELIVERY_MODULE_OPTION)]
    #[LiveListener(CheckoutEvents::SET_INVOICE_ORDER_ADDRESS_ID)]
    #[LiveListener(CheckoutEvents::SET_PAYMENT_MODULE_ID)]
    // What the sidebar redraws on: a promotion code applied or removed, and an invoice
    // address picked in the delivery section — which emit this and nothing else. Both
    // change the total, and a promotion code can change what the payment step accepts.
    #[LiveListener('syncSummary')]
    // The generic one every checkout component emits when it has changed something the
    // rest of the page depends on — a pickup point, a consent box.
    #[LiveListener('updateNextButton')]
    public function reconsiderTheTunnel(): void
    {
        // Re-rendering is the whole answer: the sections below are read from the
        // progression, and this is what makes them be read again rather than answered
        // from what they were before the buyer's last click.
        $this->progression->forget();
        $this->sections = null;
    }

    /**
     * The sections to lay out: every step of the tunnel except the confirmation, which
     * is read once the order exists and has a page of its own.
     *
     * @return list<array{code: string, title: string, componentName: string|null, unlocked: bool, needsIdentification: bool}>
     *
     * @throws PropelException
     */
    public function getSections(): array
    {
        if (null !== $this->sections) {
            return $this->sections;
        }

        $cart = $this->cartFacade->getOrCreateFromSession();
        $mayEnterTheCheckout = $this->guestCheckoutGate->mayEnterCheckout();
        $sections = [];

        foreach ($this->progression->activeSteps($cart, $this->langService->getLocale()) as $step) {
            if (CheckoutStep::CODE_CONFIRMATION === $step->code) {
                continue;
            }

            $reachable = $this->progression->isReachable($cart, $step->code);

            // Everything past the cart used to be a screen the checkout guarded one at a
            // time; here they are sections of a page anybody may open, so the guard
            // travels with them. The cart itself is not guarded — it never was.
            //
            // Only said of a section the cart has otherwise got to: a buyer whose cart is
            // still empty is not waiting on their identity, and telling them so would
            // send them off to sign in for a step they have not reached.
            $needsIdentification = $reachable
                && CheckoutStep::CODE_CART !== $step->code
                && !$mayEnterTheCheckout;

            $sections[] = [
                'code' => $step->code,
                'title' => $step->title,
                'componentName' => $step->componentName,
                'unlocked' => $reachable && !$needsIdentification,
                'needsIdentification' => $needsIdentification,
            ];
        }

        return $this->sections = $sections;
    }

    /**
     * The page a visitor with no session is sent to in order to carry on: the
     * identification when the shop lets this cart be ordered without an account, the
     * login when it does not.
     *
     * @throws PropelException
     */
    public function getEntryPointRoute(): string
    {
        return $this->guestCheckoutGate->entryPointRoute();
    }
}
