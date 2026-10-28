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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\Service\DeliveryEstimate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliveryEstimateTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?string, 2: array{earliest: string, latest: string}|null}>
     */
    public static function dates(): iterable
    {
        yield 'two days' => ['2030-01-08', '2030-01-10', ['earliest' => '2030-01-08', 'latest' => '2030-01-10']];
        yield 'the same day' => ['2030-01-08', '2030-01-08', ['earliest' => '2030-01-08', 'latest' => '2030-01-08']];
        yield 'instants are read by the day written, whatever the zone' => ['2030-01-08T00:00:00Z', '2030-01-10T23:30:00-05:00', ['earliest' => '2030-01-08', 'latest' => '2030-01-10']];
        yield 'no date' => [null, null, null];
        yield 'empty dates, as a carrier with nothing to say writes them' => ['', '', null];
        yield 'only the first day' => ['2030-01-08', null, null];
        yield 'only the last day' => [null, '2030-01-10', null];
        yield 'the last day before the first' => ['2030-01-10', '2030-01-08', null];
        yield 'an impossible day' => ['2030-02-30', '2030-03-02', null];
        yield 'not a date' => ['soon', 'later', null];
    }

    /**
     * @param array{earliest: string, latest: string}|null $expected
     */
    #[DataProvider('dates')]
    public function testTheTwoDatesOfAnOptionGiveTheDaysOfTheEstimate(?string $minimum, ?string $maximum, ?array $expected): void
    {
        self::assertSame($expected, DeliveryEstimate::of($minimum, $maximum));
    }
}
