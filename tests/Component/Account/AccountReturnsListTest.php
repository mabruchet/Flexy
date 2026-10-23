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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * "My returns", from a browser's point of view: the customer's own returns and nobody
 * else's, newest first, a page at a time, and a page that exists only while the shop
 * offers returns.
 */
final class AccountReturnsListTest extends WebIntegrationTestCase
{
    private const string CARD = '[data-testid="order-return"]';

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    private ?string $previousEnabled = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->previousEnabled = ConfigQuery::read(ReturnEligibilityChecker::ENABLED_CONFIG_KEY);
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '1');
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, (string) $this->previousEnabled);

        parent::tearDown();
    }

    public function testTheListHoldsTheReturnsOfTheSignedInCustomerAndNobodyElses(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();
        $myFirst = $this->returnOf($mine, '-3 days');
        $mySecond = $this->returnOf($mine, '-1 days');
        $theirReturn = $this->returnOf($theirs, '-2 days');
        $this->injector->setCustomer($mine);

        $crawler = $this->client->request('GET', '/account/returns');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter(self::CARD));
        self::assertStringNotContainsString((string) $theirReturn->getRef(), $crawler->text(), 'The list is filtered by the customer: it is the only barrier.');
        self::assertSame(
            ['/account/return/'.$mySecond->getId(), '/account/return/'.$myFirst->getId()],
            $this->returnLinks($crawler),
            'Newest first, each card linking the detail page of its return.',
        );
    }

    public function testEachCardLinksItsOrderAndShowsItsStatus(): void
    {
        $customer = $this->customer();
        $return = $this->returnOf($customer, '-1 days');
        $this->injector->setCustomer($customer);

        $crawler = $this->client->request('GET', '/account/returns');

        self::assertCount(1, $crawler->filter('a[href="/account/order/'.$return->getOrderId().'"]'));
        self::assertStringContainsString('Requested', $crawler->filter(self::CARD)->text());
    }

    public function testTheListIsPaginated(): void
    {
        $customer = $this->customer();

        for ($day = 1; $day <= 11; ++$day) {
            $this->returnOf($customer, \sprintf('-%d days', $day));
        }

        $this->injector->setCustomer($customer);

        $first = $this->client->request('GET', '/account/returns');
        self::assertCount(10, $first->filter(self::CARD));
        self::assertCount(1, $first->filter('.Pagination'));

        $second = $this->client->request('GET', '/account/returns?page=2');
        self::assertCount(1, $second->filter(self::CARD));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesThatAreNotUsable(): iterable
    {
        yield 'past what an integer holds' => ['page=99999999999999999999'];
        yield 'the largest integer' => ['page=9223372036854775807'];
        yield 'text' => ['page=abc'];
        yield 'a list' => ['page[]=1'];
    }

    #[DataProvider('pagesThatAreNotUsable')]
    public function testThePageAsksForWhateverPageTheQueryStringHolds(string $query): void
    {
        $customer = $this->customer();
        $this->returnOf($customer, '-1 days');
        $this->injector->setCustomer($customer);

        $crawler = $this->client->request('GET', '/account/returns?'.$query);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::CARD));
    }

    public function testACustomerWithoutReturnsGetsTheEmptyState(): void
    {
        $this->returnOf($this->customer(), '-1 days');
        $this->injector->setCustomer($this->customer());

        $crawler = $this->client->request('GET', '/account/returns');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::CARD));
        self::assertStringContainsString('You have no returns yet', $crawler->text());
    }

    public function testTheMenuOffersTheEntryRightAfterTheOrdersWhileReturnsAreOn(): void
    {
        $this->injector->setCustomer($this->customer());

        $crawler = $this->client->request('GET', '/account');

        self::assertSame(
            ['/account', '/account/orders', '/account/returns', '/account/addresses', '/account/purchase-lists', '/account/quick-order'],
            $crawler->filter('.Subheader-links a')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        self::assertCount(1, $crawler->filter('#HeaderProfile-dropdown a[href="/account/returns"]'));
    }

    public function testTheMenuAndThePageAreGoneWhenReturnsAreOff(): void
    {
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '0');
        $this->injector->setCustomer($this->customer());

        $crawler = $this->client->request('GET', '/account');
        self::assertCount(0, $crawler->filter('a[href="/account/returns"]'));

        $this->client->request('GET', '/account/returns');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheEntryIsTheCurrentOneOnItsOwnPage(): void
    {
        $this->injector->setCustomer($this->customer());

        $crawler = $this->client->request('GET', '/account/returns');

        self::assertSame(['/account/returns'], $crawler->filter('.Subheader-links a[aria-current="page"]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame(['/account/returns'], $crawler->filter('#HeaderProfile-dropdown a[aria-current="page"]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testAVisitorIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/account/returns');

        self::assertResponseRedirects();
        self::assertStringContainsString('/customer/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @return list<string>
     */
    private function returnLinks(Crawler $crawler): array
    {
        return $crawler->filter(self::CARD.' a[href^="/account/return/"]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
    }

    private function customer(): Customer
    {
        return $this->factory->customer($this->factory->customerTitle());
    }

    private function returnOf(Customer $customer, string $openedAt): OrderReturn
    {
        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);
        $status = OrderReturnStatusQuery::create()->findOneByCode(OrderReturnStatus::CODE_REQUESTED);

        $return = (new OrderReturn())
            ->setOrder($order)
            ->setCustomer($customer)
            ->setOrderReturnStatus($status)
            ->setCreatedAt(new \DateTime($openedAt));
        $return->save($this->getPropelConnection());

        return $return;
    }
}
