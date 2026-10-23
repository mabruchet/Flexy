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

namespace FlexyBundle\Tests\Component\Account;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;
use Thelia\Tests\Support\Payment\StoredMethodsPaymentModule;
use Thelia\Tests\Support\Payment\UsesStoredMethodsPaymentModule;

/**
 * The account navigation as a signed-in customer's browser gets it: the entries of the
 * providers the theme ships, in the order the theme has always shown them, the same in the
 * sub-header and in the profile dropdown.
 */
final class AccountMenuTest extends WebIntegrationTestCase
{
    use UsesStoredMethodsPaymentModule;

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->injector->setCustomer($this->factory->customer($this->factory->customerTitle()));

        StoredMethodsPaymentModule::reset();
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
        $this->uninstallTheStoredMethodsPaymentModule(static::getContainer());

        parent::tearDown();
    }

    public function testTheShippedEntriesKeepTheirOrder(): void
    {
        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['/account', '/account/orders', '/account/addresses', '/account/purchase-lists', '/account/quick-order'],
            $this->subheaderLinks($crawler),
        );
    }

    public function testThePaymentMethodsEntryComesAfterTheAddresses(): void
    {
        $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());

        $crawler = $this->client->request('GET', '/account');

        self::assertSame(
            ['/account', '/account/orders', '/account/addresses', '/account/payment-methods', '/account/purchase-lists', '/account/quick-order'],
            $this->subheaderLinks($crawler),
        );
    }

    public function testBothMenusAreNavigationsLabelledForAssistiveTechnology(): void
    {
        $crawler = $this->client->request('GET', '/account');

        self::assertCount(1, $crawler->filter('.Subheader--account nav[aria-label="My account"] .Subheader-links'));
        self::assertCount(1, $crawler->filter('.HeaderProfile nav#HeaderProfile-dropdown.HeaderProfile-dropdown[aria-label="My account menu"]'), 'The landmark is the hidden element, not a wrapper around it.');
        self::assertCount(0, $crawler->filter('.HeaderProfile nav > ul.HeaderProfile-dropdown'));
        self::assertCount(1, $crawler->filter('nav[aria-label="My account"]'), 'Only the sub-header.');
        self::assertCount(2, $crawler->filter('nav[aria-label="My account menu"]'), 'Dropdown and mobile drawer.');
    }

    public function testTheEntryOfThePageBeingShownIsTheCurrentOneInBothMenus(): void
    {
        $crawler = $this->client->request('GET', '/account/orders');

        self::assertResponseIsSuccessful();
        self::assertSame(['/account/orders'], $this->currentLinks($crawler, '.Subheader-links'));
        self::assertSame(['/account/orders'], $this->currentLinks($crawler, '#HeaderProfile-dropdown'));
        self::assertSame(['/account/orders'], $this->currentLinks($crawler, '.MobileDrawer'));
    }

    public function testTheCurrentEntryFollowsThePageAndIgnoresTheQueryString(): void
    {
        $crawler = $this->client->request('GET', '/account/orders?page=2');

        self::assertSame(['/account/orders'], $this->currentLinks($crawler, '#HeaderProfile-dropdown'));

        $crawler = $this->client->request('GET', '/account');

        self::assertSame(['/account'], $this->currentLinks($crawler, '.Subheader-links'));
        self::assertSame(['/account'], $this->currentLinks($crawler, '#HeaderProfile-dropdown'));
    }

    /**
     * @return list<string>
     */
    private function currentLinks(Crawler $crawler, string $menu): array
    {
        return $crawler->filter($menu.' a[aria-current="page"]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
    }

    /**
     * @return list<string>
     */
    private function subheaderLinks(Crawler $crawler): array
    {
        return $crawler->filter('.Subheader-links a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
    }
}
