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

use FlexyBundle\Service\StoredPaymentMethodsAvailability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "My payment methods", after "My addresses". Decided here, from the shop, and not by a
 * test in the templates: the page exists only when a payment module keeps payment methods
 * or the customer holds one (StoredPaymentMethodsAvailability), so the link does too.
 */
final readonly class StoredPaymentMethodsAccountMenuItemProvider implements ShippedAccountMenuItemProviderInterface
{
    public function __construct(
        private StoredPaymentMethodsAvailability $availability,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
    ) {
    }

    public function getItems(): iterable
    {
        if (!$this->availability->isAvailable()) {
            return [];
        }

        return [
            new AccountMenuItem('payment-methods', $this->translator->trans('My payment methods'), $this->urlGenerator->generate('account_payment_methods'), 400),
        ];
    }
}
