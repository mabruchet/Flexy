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
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;

/**
 * "My returns", right after "My orders", only while the shop offers returns: with the
 * feature off the page behind it answers 404.
 */
final readonly class OrderReturnsAccountMenuItemProvider implements AccountMenuItemProviderInterface
{
    public function __construct(
        private ReturnEligibilityChecker $eligibility,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
    ) {
    }

    public function getItems(): iterable
    {
        if (!$this->eligibility->isFeatureEnabled()) {
            return [];
        }

        return [
            new AccountMenuItem('returns', $this->translator->trans('My returns'), $this->urlGenerator->generate('account_returns'), 250),
        ];
    }
}
