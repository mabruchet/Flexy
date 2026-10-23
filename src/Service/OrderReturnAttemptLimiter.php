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

namespace FlexyBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Thelia\Model\Customer;

/**
 * Brakes a caller who keeps submitting return requests, accepted or not.
 *
 * The core's ReturnRequestLimiter counts the returns a customer opens and is spent only
 * once a request is accepted. A request the core refuses never reaches it, yet it has
 * already taken row locks to read the returnable quantities: a loop of ineligible posts
 * would go unchecked. This one is wider, consumed before the transaction opens, keyed on
 * the authenticated customer or, failing that, on the client IP.
 */
readonly class OrderReturnAttemptLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.flexy_order_return_attempt')]
        private RateLimiterFactoryInterface $limiterFactory,
        private RequestStack $requestStack,
    ) {
    }

    public function allows(?Customer $customer = null): bool
    {
        $key = null !== $customer?->getId()
            ? 'customer:'.$customer->getId()
            : $this->requestStack->getMainRequest()?->getClientIp();

        // No request and no customer: a console context, nothing to key on.
        if (null === $key) {
            return true;
        }

        return $this->limiterFactory->create($key)->consume()->isAccepted();
    }
}
