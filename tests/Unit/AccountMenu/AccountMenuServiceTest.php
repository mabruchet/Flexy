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

namespace FlexyBundle\Tests\Unit\AccountMenu;

use FlexyBundle\AccountMenu\AccountMenuItem;
use FlexyBundle\AccountMenu\AccountMenuItemProviderInterface;
use FlexyBundle\Service\AccountMenuService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The account navigation gathers the entries of every provider and lists them by
 * position. It is drawn in the header of every page, so a provider that misbehaves
 * loses its own entries and nothing more.
 */
final class AccountMenuServiceTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testEntriesOfAllProvidersAreListedByPosition(): void
    {
        $service = $this->service(
            $this->provider(new AccountMenuItem('late', 'Late', '/late', 300)),
            $this->provider(),
            $this->provider(new AccountMenuItem('first', 'First', '/first', 100), new AccountMenuItem('middle', 'Middle', '/middle', 250)),
        );

        self::assertSame(['first', 'middle', 'late'], $this->slugs($service));
    }

    public function testEntriesSharingAPositionKeepTheOrderOfTheirProviders(): void
    {
        $service = $this->service(
            $this->provider(new AccountMenuItem('a', 'A', '/a', 200)),
            $this->provider(new AccountMenuItem('b', 'B', '/b', 200), new AccountMenuItem('c', 'C', '/c', 100)),
        );

        self::assertSame(['c', 'a', 'b'], $this->slugs($service));
    }

    public function testASlugGivenTwiceKeepsItsFirstEntry(): void
    {
        $service = $this->service(
            $this->provider(new AccountMenuItem('orders', 'My orders', '/orders', 200)),
            $this->provider(new AccountMenuItem('orders', 'Hijacked', '/elsewhere', 100)),
        );

        $items = $service->getItems();

        self::assertCount(1, $items);
        self::assertSame('/orders', $items[0]->href);
        self::assertSame(['warning'], $this->logger->levels);
    }

    public function testAnEntryOfTheWrongTypeIsLeftOut(): void
    {
        $service = $this->service($this->provider(
            new AccountMenuItem('orders', 'My orders', '/orders', 200),
            ['slug' => 'legacy', 'text' => 'Legacy', 'href' => '/legacy'],
        ));

        self::assertSame(['orders'], $this->slugs($service));
        self::assertSame(['warning'], $this->logger->levels);
    }

    public function testAFailingProviderLosesItsOwnEntriesOnly(): void
    {
        $failing = new class implements AccountMenuItemProviderInterface {
            public function getItems(): iterable
            {
                throw new \RuntimeException('The module behind this entry is broken.');
            }
        };

        $service = $this->service($failing, $this->provider(new AccountMenuItem('orders', 'My orders', '/orders', 200)));

        self::assertSame(['orders'], $this->slugs($service));
        self::assertSame(['error'], $this->logger->levels);
    }

    public function testTheMenuIsBuiltOncePerRequestAndAgainForTheNext(): void
    {
        $provider = new class implements AccountMenuItemProviderInterface {
            public int $calls = 0;

            public function getItems(): iterable
            {
                ++$this->calls;

                return [new AccountMenuItem('orders', 'My orders', '/orders', 200)];
            }
        };

        $requestStack = new RequestStack();
        $service = new AccountMenuService([$provider], $this->logger, $requestStack);

        $requestStack->push(Request::create('/account'));
        $service->getItems();
        $service->getItems();
        self::assertSame(1, $provider->calls, 'The subheader and the dropdown share one build.');

        $requestStack->pop();
        $requestStack->push(Request::create('/account'));
        $service->getItems();
        self::assertSame(2, $provider->calls, 'The next request, maybe another customer, gets its own menu.');
    }

    /**
     * A child theme written against the array shape of the menu keeps reading it.
     */
    public function testAnEntryReadsAsAReadOnlyArray(): void
    {
        $item = new AccountMenuItem('orders', 'My orders', '/orders', 200);

        self::assertSame('orders', $item['slug']);
        self::assertSame('/orders', $item['href']);
        self::assertTrue(isset($item['text']));
        self::assertFalse(isset($item['icon']));

        $this->expectException(\LogicException::class);
        $item['slug'] = 'changed';
    }

    private function service(AccountMenuItemProviderInterface ...$providers): AccountMenuService
    {
        return new AccountMenuService($providers, $this->logger, new RequestStack());
    }

    /**
     * @return list<string>
     */
    private function slugs(AccountMenuService $service): array
    {
        return array_map(static fn (AccountMenuItem $item): string => $item->slug, $service->getItems());
    }

    private function provider(mixed ...$items): AccountMenuItemProviderInterface
    {
        return new readonly class(array_values($items)) implements AccountMenuItemProviderInterface {
            /**
             * @param list<mixed> $items
             */
            public function __construct(private array $items)
            {
            }

            public function getItems(): iterable
            {
                return $this->items;
            }
        };
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $levels = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->levels[] = (string) $level;
    }
}
