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

/**
 * Marks a provider the theme itself ships. When one of its entries and an entry of any
 * other provider share a slug, the other provider's entry wins, whatever the order the
 * services were tagged in: a module replaces a native entry by giving it the same slug.
 */
interface ShippedAccountMenuItemProviderInterface extends AccountMenuItemProviderInterface
{
}
