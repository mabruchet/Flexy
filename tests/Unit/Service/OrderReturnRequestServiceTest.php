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

namespace FlexyBundle\Tests\Unit\Service;

use FlexyBundle\Service\OrderReturnRequestService;
use PHPUnit\Framework\TestCase;
use Thelia\Domain\OrderReturn\Service\OrderReturnWriteTransaction;

/**
 * A return opened from the theme must be written in the core's shared write transaction,
 * which locks the order and its lines before the first read. A transaction of the theme's
 * own would read first, so the eligibility checker would fall back to the locking reads
 * that deadlock.
 *
 * Checked on the class rather than on a live request: the HTTP tests run inside a test
 * transaction, where the core's one is nested and cannot be told apart from another.
 */
final class OrderReturnRequestServiceTest extends TestCase
{
    public function testTheReturnIsWrittenThroughTheSharedWriteTransaction(): void
    {
        $constructor = (new \ReflectionClass(OrderReturnRequestService::class))->getConstructor();
        self::assertNotNull($constructor);

        $dependencies = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );

        self::assertContains(OrderReturnWriteTransaction::class, $dependencies);
    }
}
