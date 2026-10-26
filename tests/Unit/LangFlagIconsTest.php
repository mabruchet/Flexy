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
use Symfony\UX\Icons\IconFactory;
use Symfony\UX\Icons\Registry\LocalSvgIconRegistry;

/**
 * The language selector asks for `circle-flags:<code>` of every language the shop has. An icon
 * the theme does not ship is fetched from api.iconify.design at the first render, and a page
 * whose network call fails answers 500. The registry used here reads the icon directory only,
 * the way ux-icons answers before it falls back to Iconify, so a missing file fails the test
 * instead of reaching the network.
 */
final class LangFlagIconsTest extends TestCase
{
    /**
     * The languages Thelia installs (`lang` rows of setup/insert.sql.tpl).
     *
     * @return iterable<string, array{string}>
     */
    public static function installedLanguages(): iterable
    {
        foreach (['fr', 'en', 'es', 'it', 'ru', 'cs', 'de', 'nl'] as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('installedLanguages')]
    public function testEveryInstalledLanguageHasItsFlagWithoutTheNetwork(string $code): void
    {
        $registry = new LocalSvgIconRegistry(new IconFactory(), \dirname(__DIR__, 2) . '/assets/icons');

        $icon = $registry->get('circle-flags:' . $code);

        self::assertNotSame('', $icon->getInnerSvg(), 'the flag has a body');
        self::assertArrayHasKey('viewBox', $icon->getAttributes(), 'the flag is a sized SVG');
    }

    /**
     * The selector renders every flag inline in the same page, and the masks of the files
     * Iconify serves can carry the same id: two of them would make the page invalid and point
     * every `url(#…)` at the first mask of the page.
     */
    public function testNoTwoFlagsShareAnId(): void
    {
        $registry = new LocalSvgIconRegistry(new IconFactory(), \dirname(__DIR__, 2) . '/assets/icons');
        $ids = [];

        foreach (self::installedLanguages() as [$code]) {
            preg_match_all('/\bid="([^"]+)"/', $registry->get('circle-flags:' . $code)->getInnerSvg(), $matches);
            array_push($ids, ...$matches[1]);
        }

        self::assertSame(array_unique($ids), $ids);
    }
}
