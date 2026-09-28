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

namespace FlexyBundle\Module;

use Thelia\Model\ModuleQuery;

final readonly class ActiveModules
{
    public function has(string $code): bool
    {
        foreach (ModuleQuery::getActivated() as $module) {
            if ($code === $module->getCode()) {
                return true;
            }
        }

        return false;
    }
}
