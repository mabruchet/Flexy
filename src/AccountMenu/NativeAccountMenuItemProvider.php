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

namespace FlexyBundle\AccountMenu;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The entries the theme has always shipped, in the order it has always shown them. The
 * positions leave room between them: "My returns" sits at 250 and "My payment methods"
 * at 400, and an entry of another provider slots in by position the same way.
 */
final readonly class NativeAccountMenuItemProvider implements ShippedAccountMenuItemProviderInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
    ) {
    }

    public function getItems(): iterable
    {
        return [
            new AccountMenuItem('profile', $this->translator->trans('My profile'), $this->urlGenerator->generate('account_index'), 100),
            new AccountMenuItem('orders', $this->translator->trans('My orders'), $this->urlGenerator->generate('account_orders'), 200),
            new AccountMenuItem('addresses', $this->translator->trans('My addresses'), $this->urlGenerator->generate('account_addresses'), 300),
            new AccountMenuItem('purchase-lists', $this->translator->trans('My purchase lists'), $this->urlGenerator->generate('account_purchase_lists'), 500),
            new AccountMenuItem('quick-order', $this->translator->trans('Quick order'), $this->urlGenerator->generate('account_quick_order'), 600),
        ];
    }
}
