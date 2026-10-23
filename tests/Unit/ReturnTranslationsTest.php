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
 * sentences below are the ones the core throws, word for word.
 */
final class ReturnTranslationsTest extends TestCase
{
    private const array LOCALES = ['en_US', 'fr_FR', 'de_DE', 'es_ES', 'it_IT'];

    private const array KEYS = [
        'My returns',
        'You have no returns yet',
        'This order has been cancelled and can no longer be returned.',
        'This order has already been refunded and can no longer be returned.',
        'This order has not been paid yet and cannot be returned.',
        'Another request is being processed on this order, please try again.',
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
