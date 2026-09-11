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

namespace FlexyBundle\Components\Organisms\NextButton;

use FlexyBundle\Event\CheckoutEvents;
use Propel\Runtime\Exception\PropelException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Checkout\DTO\CheckoutStepView;
use Thelia\Domain\Checkout\Exception\MissingConsentException;
use Thelia\Domain\Checkout\Service\CheckoutProgressionService;
use Thelia\Domain\Checkout\Service\ConsentGuard;
use Thelia\Domain\Legal\CompanyIdentifierRules;
use Thelia\Model\Cart;
use Thelia\Model\CartAddressQuery;
use Thelia\Model\CheckoutStep;

/**
 * The button that leads out of a step, live until the step is settled.
 *
 * Which steps it waits for comes from the configuration — the list of active steps, up to
 * and including its own — so a shop that turned the delivery step off no longer keeps a
 * button greyed out forever, waiting for a carrier nobody is ever asked for.
 *
 * Whether a step is settled is answered here, from the cart's own columns, and not by the
 * progression. The deliberate compromise: the progression's delivery check asks every
 * shipping module for a quote — a module call, possibly an outgoing HTTP request — and
 * this runs on every live event, down to each consent box ticked on the payment step, so
 * the button reads the columns those steps write and leaves the real check where it is
 * enforced (CheckoutValidationService, at the placement) and where it is run once per
 * mutation (CheckoutOnePage::reconsiderTheTunnel).
 */
#[AsLiveComponent]
class Base
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /** The code of the step this button leads out of. */
    #[LiveProp(updateFromParent: true)]
    public string $step;

    #[LiveProp(updateFromParent: true)]
    public string $href;

    public function __construct(
        private readonly CartFacade $cartFacade,
        private readonly CheckoutProgressionService $progression,
        private readonly ConsentGuard $consentGuard,
    ) {
    }

    public function mount(string $step, string $href): void
    {
        $this->step = $step;
        $this->href = $href;
    }

    #[LiveListener(CheckoutEvents::DELETE_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::ADD_ITEM_EVENT)]
    #[LiveListener(CheckoutEvents::SET_PAYMENT_MODULE_ID)]
    #[LiveListener(CheckoutEvents::SET_INVOICE_ORDER_ADDRESS_ID)]
    // The generic one: every checkout component that changes something the button
    // depends on emits it, the consent boxes of the payment step included.
    #[LiveListener('updateNextButton')]
    public function getIsValid(): bool
    {
        try {
            $cart = $this->cartFacade->getOrCreateFromSession();

            // Reading the tunnel runs no check of its own: it asks each step whether this
            // cart skips it, which for the delivery is "has it anything to ship".
            $codes = array_map(
                static fn (CheckoutStepView $step): string => $step->code,
                $this->progression->activeSteps($cart),
            );

            $here = array_search($this->step, $codes, true);

            if (false === $here) {
                return false;
            }

            foreach (\array_slice($codes, 0, $here + 1) as $code) {
                if (!$this->isSettled($cart, $code)) {
                    return false;
                }
            }

            return true;
        } catch (PropelException) {
            // The checks read the cart, its addresses and the consents. A button left
            // grey is a far better outcome than a 500 swallowing the whole step, and the
            // order is refused a moment later by the very same rules.
            return false;
        }
    }

    /**
     * @throws PropelException
     */
    private function isSettled(Cart $cart, string $code): bool
    {
        return match ($code) {
            CheckoutStep::CODE_CART => $cart->countCartItems() > 0,
            CheckoutStep::CODE_DELIVERY => null !== $cart->getAddressDeliveryId()
                && null !== $cart->getDeliveryModuleId(),
            CheckoutStep::CODE_PAYMENT => $this->isPaymentSettled($cart),
            // A step declared by a module: nothing here knows what it waits for, and a
            // button this side of it must not be the thing that stops the buyer. What
            // that step requires is still checked at the placement.
            default => true,
        };
    }

    /**
     * @throws PropelException
     */
    private function isPaymentSettled(Cart $cart): bool
    {
        return null !== $cart->getPaymentModuleId()
            && null !== $cart->getAddressInvoiceId()
            && $this->hasBillableInvoiceAddress($cart)
            && $this->hasGivenEveryRequiredConsent();
    }

    /**
     * Greys out the button until every box the shop requires is ticked.
     *
     * Asked of the very guard that refuses the order rather than re-reading the
     * acceptances here: which consents are mandatory, and what counts as an answer,
     * stays decided in one place. The refusal is a business one, and the only thing
     * this needs from it is that it happened. It reads the consent table and the
     * session store, and calls no module.
     */
    private function hasGivenEveryRequiredConsent(): bool
    {
        try {
            $this->consentGuard->checkMandatoryConsentsAccepted();
        } catch (MissingConsentException|PropelException) {
            return false;
        }

        return true;
    }

    /**
     * Greys out the button rather than letting the buyer submit and bounce back: an invoice for
     * a business needs its legal identifiers. CheckoutValidationService holds the same rule and
     * is the one that decides - this only spares a round trip.
     *
     * @throws PropelException
     */
    private function hasBillableInvoiceAddress(Cart $cart): bool
    {
        $address = CartAddressQuery::create()->findPk($cart->getAddressInvoiceId());

        if (null === $address) {
            return false;
        }

        return [] === CompanyIdentifierRules::violationsFor(
            $address->getCompany(),
            $address->getSiret(),
            $address->getVatNumber(),
            $address->getCountry()?->getIsoalpha2(),
        );
    }
}
