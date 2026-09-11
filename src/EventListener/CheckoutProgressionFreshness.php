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

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Domain\Checkout\Service\CheckoutProgressionService;

/**
 * Starts every request with the checkout progression unread.
 *
 * The progression memoizes what it answered for a cart, which is what keeps one page
 * from asking the shipping modules for a quote three times. That memo belongs to the
 * request, and a worker that serves several requests from one container — the test
 * harness does, and so does a shop running on a persistent runtime — would otherwise
 * hand the delivery and payment of the previous page to the next one: a buyer who has
 * just chosen a carrier would still be sent back to the delivery step.
 *
 * Not a workaround for the theme's own writes: a component that changes the cart within
 * a request drops the memo itself, straight after, because this has long run by then.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
final readonly class CheckoutProgressionFreshness
{
    public function __construct(
        private CheckoutProgressionService $progression,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->progression->forget();
    }
}
