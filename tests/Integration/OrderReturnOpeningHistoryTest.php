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

namespace FlexyBundle\Tests\Integration;

use FlexyBundle\Service\OrderReturnRequestService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\OrderReturn\OrderReturnEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Order\Enum\OrderHistoryEventType;
use Thelia\Domain\OrderReturn\Exception\ReturnNotAllowedException;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderHistoryQuery;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * A return opened from the account form leaves the line that opens it on the history of its
 * order, once, and before the customer is mailed: the merchant reads one timeline, and the
 * API processors of the core write the same line. Runs on an installed shop (bin/test-prepare).
 */
final class OrderReturnOpeningHistoryTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '1');
    }

    protected function tearDown(): void
    {
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testAnOpenedReturnWritesOneOpeningLineOnItsOrder(): void
    {
        [$customer, $order, $orderProduct] = $this->paidOrder();

        $return = $this->service()->open($order, $customer, [(int) $orderProduct->getId() => 1.0], null, 'refund', 'Too small.');

        self::assertSame(1, $this->openingLines($order), 'The return opened from the account form is on the history of its order, once.');

        $line = OrderHistoryQuery::create()
            ->filterByOrderId((int) $order->getId())
            ->filterByEventType(OrderHistoryEventType::RETURN_OPENED->value)
            ->findOne();
        self::assertNotNull($line);
        self::assertStringContainsString((string) $return->getRef(), (string) $line->getPayload(), 'The line names the return it opens.');
    }

    public function testTheLineIsWrittenBeforeTheCustomerIsMailed(): void
    {
        [$customer, $order, $orderProduct] = $this->paidOrder();
        $linesWhenMailed = null;

        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            TheliaEvents::ORDER_RETURN_SEND_STATUS_EMAIL,
            function (OrderReturnEvent $event) use ($order, &$linesWhenMailed): void {
                $linesWhenMailed = $this->openingLines($order);
            },
            1024,
        );

        $this->service()->open($order, $customer, [(int) $orderProduct->getId() => 1.0], null, 'refund', null);

        self::assertSame(1, $linesWhenMailed, 'The timeline shows the gesture before the mail that follows it.');
    }

    public function testARefusedRequestWritesNoLine(): void
    {
        [$customer, $order, $orderProduct] = $this->paidOrder();

        try {
            $this->service()->open($order, $customer, [(int) $orderProduct->getId() => 5.0], null, 'refund', null);
            self::fail('More than the ordered quantity was expected to be refused.');
        } catch (ReturnNotAllowedException) {
            self::assertSame(0, $this->openingLines($order));
        }
    }

    private function openingLines(Order $order): int
    {
        return OrderHistoryQuery::create()
            ->filterByOrderId((int) $order->getId())
            ->filterByEventType(OrderHistoryEventType::RETURN_OPENED->value)
            ->count();
    }

    private function service(): OrderReturnRequestService
    {
        $service = $this->getService(OrderReturnRequestService::class);
        self::assertInstanceOf(OrderReturnRequestService::class, $service);

        return $service;
    }

    /**
     * @return array{Customer, Order, OrderProduct}
     */
    private function paidOrder(): array
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());
        $pse = $this->factory->productSaleElement($product, ['quantity' => 10]);
        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);

        $orderProduct = (new OrderProduct())
            ->setOrderId((int) $order->getId())
            ->setProductRef((string) $product->getRef())
            ->setProductSaleElementsRef((string) $pse->getRef())
            ->setProductSaleElementsId((int) $pse->getId())
            ->setTitle('A returnable product')
            ->setQuantity(2.0)
            ->setPrice('10.000000')
            ->setPromoPrice('10.000000')
            ->setWasNew(1)
            ->setWasInPromo(0)
            ->setVirtual(0);
        $orderProduct->save($this->getPropelConnection());

        return [$customer, $order, $orderProduct];
    }
}
