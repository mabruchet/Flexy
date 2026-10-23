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

namespace FlexyBundle\Components\Layouts\Subheader;

use FlexyBundle\AccountMenu\AccountMenuItem;
use FlexyBundle\Service\AccountMenuService;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Account
{
    public function __construct(
        private readonly AccountMenuService $accountMenu,
    ) {
    }

    /**
     * @return list<AccountMenuItem>
     */
    public function getItems(): array
    {
        return $this->accountMenu->getItems();
    }
}
