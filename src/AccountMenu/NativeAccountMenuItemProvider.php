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
 * The entries the theme has always shipped, at 100, 200 and 300: an entry of another
 * provider slots between them by position.
 */
final readonly class NativeAccountMenuItemProvider implements AccountMenuItemProviderInterface
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
        ];
    }
}
