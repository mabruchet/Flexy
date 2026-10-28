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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The core states the refusals of a return in English and the theme carries their
 * translation: a refusal missing from a catalog reaches the customer untranslated. The
 * sentences below are the ones the core throws, word for word, with the texts of the returns
 * pages and of the request form, which every catalog carries too.
 */
final class ReturnTranslationsTest extends TestCase
{
    private const array LOCALES = ['en_US', 'fr_FR', 'de_DE', 'es_ES', 'it_IT'];

    private const array KEYS = [
        'My returns',
        'My account menu',
        'You have no returns yet',
        'This order has been cancelled and can no longer be returned.',
        'This order has already been refunded and can no longer be returned.',
        'This order has not been paid yet and cannot be returned.',
        'Another request is being processed on this order, please try again.',
        'Returns',
        'Return %ref%',
        'Request a return',
        'Return request',
        'Products to return',
        'Quantity to return (up to %quantity%)',
        'Reason for the return',
        'Select a reason',
        'What do you expect?',
        'What you wish to receive in exchange',
        'A refund',
        'A store credit',
        'An exchange',
        'Comment',
        'Anything the shop should know about this return',
        'Send my return request',
        'Your return request has been sent. You will be notified as soon as it has been reviewed.',
        'Reason given by the shop',
        'Returned products',
        'Refundable amount',
        'Back to the order',
        'Download the return document',
        'Select at least one product to return.',
        'The expected resolution is not one the shop offers.',
        'The comment is too long.',
        'The wished exchange is too long.',
        'Too many return requests, please try again later.',
        'The return could not be opened.',
        'The product returns feature is disabled.',
        'This order cannot be used for a return.',
        'The return window for this order has closed.',
        'A virtual product cannot be returned.',
        'The returned quantity must be positive.',
        'The returned quantity exceeds the returnable quantity of this line.',
        'The order does not exist.',
        'Unknown order product in a return line.',
        'This product line does not belong to the order.',
        'The postage of this order is already included in another return.',
        'Return %product%',
        'My orders',
        'Order N°',
        'Quantity',
        'label_colon',
    ];

    #[DataProvider('everyLocaleAndKey')]
    public function testTheCatalogTranslatesTheKey(string $locale, string $key): void
    {
        $catalog = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.'.$locale.'.yaml');

        self::assertIsArray($catalog);
        self::assertArrayHasKey($key, $catalog, \sprintf('%s has no translation of "%s".', $locale, $key));
        self::assertIsString($catalog[$key]);
        self::assertNotSame('', trim($catalog[$key]));

        if ('en_US' !== $locale) {
            self::assertNotSame($key, $catalog[$key], \sprintf('%s still carries the English sentence for "%s".', $locale, $key));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function everyLocaleAndKey(): iterable
    {
        foreach (self::LOCALES as $locale) {
            foreach (self::KEYS as $key) {
                yield $locale.' / '.$key => [$locale, $key];
            }
        }
    }
}
