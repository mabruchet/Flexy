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

namespace FlexyBundle\Service;

/**
 * The days a carrier says a parcel arrives between, read from the two dates a delivery option
 * carries. Whatever fills them (a module of delivery delays, the carrier itself) is shown the
 * same way, and an option without them shows nothing.
 *
 * Only the day counts: a date written as an instant ('2025-10-01T00:00:00Z') is read by its
 * calendar day as written, never moved to another time zone, so the buyer reads the day the
 * module meant.
 */
final class DeliveryEstimate
{
    /**
     * Both days as Y-m-d, or null when one is missing, unreadable, or comes after the other.
     *
     * @return array{earliest: string, latest: string}|null
     */
    public static function of(?string $minimumDeliveryDate, ?string $maximumDeliveryDate): ?array
    {
        $earliest = self::dayOf($minimumDeliveryDate);
        $latest = self::dayOf($maximumDeliveryDate);

        if (null === $earliest || null === $latest || $latest < $earliest) {
            return null;
        }

        return ['earliest' => $earliest, 'latest' => $latest];
    }

    private static function dayOf(?string $date): ?string
    {
        if (null === $date || 1 !== preg_match('/^(\d{4}-\d{2}-\d{2})(?:$|[T ])/', trim($date), $matches)) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);

        // createFromFormat() rolls an impossible day over (2025-02-30 becomes March 2): such a
        // date is refused rather than shown as another day.
        return false !== $day && $day->format('Y-m-d') === $matches[1] ? $matches[1] : null;
    }
}
