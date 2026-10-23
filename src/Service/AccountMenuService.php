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

use FlexyBundle\AccountMenu\AccountMenuItem;
use FlexyBundle\AccountMenu\AccountMenuItemProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Single source for the customer account navigation, shared by the account
 * subheader and the header profile dropdown.
 *
 * The menu is drawn in the header of every page a customer sees, so a provider a
 * module got wrong is logged and left out rather than allowed to take the page down.
 *
 * Built once per main request: the subheader and the dropdown both ask for it. The
 * cache is keyed by the request object itself, weakly, so a long-running worker never
 * hands one customer's menu to the next request, and nothing outlives its request.
 */
final readonly class AccountMenuService
{
    /** @var \WeakMap<Request, list<AccountMenuItem>> */
    private \WeakMap $itemsByRequest;

    /**
     * @param iterable<AccountMenuItemProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(AccountMenuItemProviderInterface::class)]
        private iterable $providers,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
    ) {
        $this->itemsByRequest = new \WeakMap();
    }

    /**
     * Ordered by position; entries sharing one keep the order their providers gave them.
     * A slug given twice keeps its first entry.
     *
     * @return list<AccountMenuItem>
     */
    public function getItems(): array
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            return $this->collectItems();
        }

        return $this->itemsByRequest[$request] ??= $this->collectItems();
    }

    /**
     * @return list<AccountMenuItem>
     */
    private function collectItems(): array
    {
        $items = [];

        foreach ($this->providers as $provider) {
            foreach ($this->itemsOf($provider) as $item) {
                if (isset($items[$item->slug])) {
                    $this->logger->warning('Account menu entry "{slug}" of {provider} ignored: the slug is already taken.', [
                        'slug' => $item->slug,
                        'provider' => $provider::class,
                    ]);

                    continue;
                }

                $items[$item->slug] = $item;
            }
        }

        $items = array_values($items);
        usort($items, static fn (AccountMenuItem $left, AccountMenuItem $right): int => $left->position <=> $right->position);

        return $items;
    }

    /**
     * Isolates one provider: an exception or an entry of the wrong type drops that
     * provider's entries or that entry, and nothing else.
     *
     * @return list<AccountMenuItem>
     */
    private function itemsOf(AccountMenuItemProviderInterface $provider): array
    {
        try {
            // Typed as mixed on purpose: the interface promises AccountMenuItem, a module
            // written against the old array shape does not keep that promise.
            /** @var list<mixed> $candidates */
            $candidates = iterator_to_array($provider->getItems(), false);
        } catch (\Throwable $exception) {
            $this->logger->error('Account menu provider {provider} failed; its entries are left out.', [
                'provider' => $provider::class,
                'exception' => $exception,
            ]);

            return [];
        }

        $items = [];

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof AccountMenuItem) {
                $this->logger->warning('Account menu provider {provider} returned a {type} instead of an AccountMenuItem; ignored.', [
                    'provider' => $provider::class,
                    'type' => get_debug_type($candidate),
                ]);

                continue;
            }

            $items[] = $candidate;
        }

        return $items;
    }
}
