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

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Adds entries to the customer account navigation (account subheader and header
 * profile dropdown). A provider decides on the server whether its entries exist:
 * when the feature behind them is off, it returns none.
 *
 * Picked up through autoconfiguration: a module whose configureServices() loads its
 * classes with ->autoconfigure() has nothing else to declare. Without it, tag the
 * service with this interface's name.
 */
#[AutoconfigureTag]
interface AccountMenuItemProviderInterface
{
    /**
     * @return iterable<AccountMenuItem>
     */
    public function getItems(): iterable;
}
