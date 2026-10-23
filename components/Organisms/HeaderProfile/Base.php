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

namespace FlexyBundle\Components\Organisms\HeaderProfile;

use FlexyBundle\AccountMenu\AccountMenuItem;
use FlexyBundle\Service\AccountMenuService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Base
{
    public function __construct(
        private readonly AccountMenuService $accountMenu,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * The path of the page being shown, to mark its own entry as the current one. Base
     * path included, as the generated hrefs carry it on a shop installed in a subdirectory.
     */
    public function getCurrentPath(): ?string
    {
        $request = $this->requestStack->getMainRequest();

        return null === $request ? null : $request->getBaseUrl().$request->getPathInfo();
    }

    /**
     * @return list<AccountMenuItem>
     */
    public function getItems(): array
    {
        return $this->accountMenu->getItems();
    }
}
