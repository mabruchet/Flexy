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

namespace FlexyBundle\Tests\Component\Checkout;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Api\Bridge\Propel\Event\DeliveryModuleOptionEvent;
use Thelia\Api\Resource\DeliveryModuleOption;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Area;
use Thelia\Model\AreaDeliveryModule;
use Thelia\Model\CountryArea;
use Thelia\Model\ModuleQuery;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;

/**
 * The delivery step shows the days a parcel is expected between when something fills the two
 * dates of a delivery option, and says nothing otherwise: the card reads the option, whichever
 * module wrote it.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class DeliveryEstimateRenderTest extends IntegrationTestCase
{
    /** @var list<callable> */
    private array $listeners = [];

    protected function tearDown(): void
    {
        foreach ($this->listeners as $listener) {
            $this->dispatcher()->removeListener(TheliaEvents::MODULE_DELIVERY_GET_OPTIONS, $listener);
        }
        $this->listeners = [];

        parent::tearDown();
    }

    public function testACarrierWhoseOptionCarriesTwoDaysShowsThemOnItsCard(): void
    {
        $this->carriersSay('2030-01-10', '2030-01-12');
        $this->cartWithACarrier();

        $estimate = $this->estimates();

        self::assertCount(1, $estimate);
        self::assertStringContainsString('Delivery scheduled between', $estimate->text());
        self::assertStringContainsString('Thursday 10 January', $estimate->text());
        self::assertStringContainsString('Saturday 12 January', $estimate->text());
    }

    public function testTheSameDayTwiceIsShownOnce(): void
    {
        $this->carriersSay('2030-01-10', '2030-01-10');
        $this->cartWithACarrier();

        $estimate = $this->estimates();

        self::assertCount(1, $estimate);
        self::assertSame('Delivery scheduled on Thursday 10 January', trim((string) preg_replace('/\s+/', ' ', $estimate->text())));
    }

    public function testACarrierWithoutDatesShowsNoEstimate(): void
    {
        $this->cartWithACarrier();

        self::assertCount(0, $this->estimates());
    }

    public function testAnEstimateWhoseLastDayComesFirstIsNotShown(): void
    {
        $this->carriersSay('2030-01-12', '2030-01-10');
        $this->cartWithACarrier();

        self::assertCount(0, $this->estimates());
    }

    private function carriersSay(string $minimum, string $maximum): void
    {
        // After the carriers, as a module of delivery delays would: it dates the options they built.
        $listener = static function (DeliveryModuleOptionEvent $event) use ($minimum, $maximum): void {
            foreach ($event->getDeliveryModuleOptions() as $option) {
                $option->setMinimumDeliveryDate($minimum)->setMaximumDeliveryDate($maximum);
            }
        };
        $this->dispatcher()->addListener(TheliaEvents::MODULE_DELIVERY_GET_OPTIONS, $listener, -255);
        $this->listeners[] = $listener;
    }

    private function cartWithACarrier(): void
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle());
        $country = $factory->country();
        $factory->address($customer, $country)->setIsDefault(1)->save($this->getPropelConnection());
        $carrier = ModuleQuery::create()->filterByCode('VirtualProductDelivery')->filterByActivate(1)->findOne()
            ?? self::fail('No active VirtualProductDelivery module: run bin/test-prepare.');

        // The country of the address sits in a shipping zone the carrier serves.
        $area = (new Area())->setName('Delivery estimate test area');
        $area->save($this->getPropelConnection());
        (new CountryArea())->setAreaId($area->getId())->setCountryId($country->getId())->save($this->getPropelConnection());
        (new AreaDeliveryModule())->setAreaId($area->getId())->setDeliveryModuleId($carrier->getId())->save($this->getPropelConnection());

        // The virtual carrier builds no option of its own: the test gives it one, as a carrier module does.
        $carrierId = $carrier->getId();
        $offer = static function (DeliveryModuleOptionEvent $event) use ($carrierId): void {
            if ($event->getModule()->getId() === $carrierId) {
                $event->appendDeliveryModuleOptions(
                    (new DeliveryModuleOption())->setCode('estimate-test')->setValid(true)->setTitle('Estimate test')->setDescription('')->setImage('')->setPostage(0.0)->setPostageTax(0.0)->setPostageUntaxed(0.0),
                );
            }
        };
        $this->dispatcher()->addListener(TheliaEvents::MODULE_DELIVERY_GET_OPTIONS, $offer, 0);
        $this->listeners[] = $offer;

        $cart = $factory->cart($customer);
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['baseQuantity' => 10]);
        $product->setVirtual(1)->save($this->getPropelConnection());
        $factory->cartItem($cart, $product);

        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);
        $session->setSessionCart($cart);
    }

    private function estimates(): Crawler
    {
        $twig = $this->getService('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->createTemplate('{{ component("Organisms:Delivery:Base") }}', 'delivery-step.html.twig')->render();
        $crawler = new Crawler($html);

        self::assertGreaterThan(0, $crawler->filter('.DeliveryMode')->count(), 'The step offers a carrier to the cart.');

        return $crawler->filter('[data-testid="delivery-estimate"]');
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return $this->getService(EventDispatcherInterface::class);
    }
}
