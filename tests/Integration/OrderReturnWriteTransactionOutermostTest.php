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
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderReturnQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Thelia\Test\Trait\RecordsSqlQueries;

/**
 * The path a customer's request takes in production: nobody has a transaction open when
 * the form is submitted, so the core's write transaction is the outermost one. It reads the
 * ids of the lines of the order without a lock, opens the transaction, then locks the order
 * and its lines before anything else is read, which lets a plain read see every commit up to
 * the locks. OrderReturnWriteTransactionPathTest cannot see that path: IntegrationTestCase
 * wraps each test in a transaction, so there the core's transaction is always nested.
 *
 * Runs outside the test transaction, on rows it commits and deletes by hand.
 * Runs on an installed shop (bin/test-prepare).
 */
final class OrderReturnWriteTransactionOutermostTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    protected bool $useTransaction = false;

    private ?string $previousEnabled = null;

    /** @var list<int> */
    private array $orderIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnabled = ConfigQuery::read(ReturnEligibilityChecker::ENABLED_CONFIG_KEY);
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '1');
    }

    protected function tearDown(): void
    {
        try {
            $this->deleteTheCommittedOrders();
        } finally {
            ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, (string) $this->previousEnabled);
            ConfigQuery::resetCache();

            parent::tearDown();
        }
    }

    public function testTheOutermostTransactionReadsTheLineIdsThenLocksTheOrderAndItsLinesBeforeAnyOtherRead(): void
    {
        [$customer, $order, $lines] = $this->paidOrderWithTwoLines();
        $requested = [(int) $lines[1]->getId() => 1.0, (int) $lines[0]->getId() => 1.0];

        $statements = $this->recordSqlQueries(function () use ($order, $customer, $requested): void {
            $this->service()->open($order, $customer, $requested, null, 'refund', null);
        });

        // The line ids of the order are read first, without a lock, outside the transaction: only
        // the outermost transaction does that. Then the order, then its lines, locked before
        // anything else is read.
        self::assertGreaterThanOrEqual(4, \count($statements));
        self::assertStringContainsString('FROM `order_product` WHERE `id` IN (', $statements[0], 'The ids of the lines are read first.');
        self::assertStringNotContainsString('FOR UPDATE', $statements[0], 'And that read takes no lock.');
        self::assertStringContainsString('FROM `order` WHERE `id` = :id FOR UPDATE', $statements[1], 'Then the order is locked.');
        self::assertStringContainsString('FROM `order_product` WHERE `id` = :id FOR UPDATE', $statements[2], 'Then its first line.');
        self::assertStringContainsString('FROM `order_product` WHERE `id` = :id FOR UPDATE', $statements[3], 'Then its second line.');

        // The picture is taken after the locks, so a plain read sees every commit and none of the
        // reads that follow has to lock: a nested transaction would read the returned quantities
        // with locking reads.
        self::assertCount(
            3,
            array_filter($statements, static fn (string $statement): bool => str_contains($statement, 'FOR UPDATE')),
            'The three locks above are the only ones.',
        );
        self::assertSame(1, OrderReturnQuery::create()->filterByOrderId((int) $order->getId())->count(), 'The return is written.');
    }

    private function service(): OrderReturnRequestService
    {
        $service = $this->getService(OrderReturnRequestService::class);
        self::assertInstanceOf(OrderReturnRequestService::class, $service);

        return $service;
    }

    /**
     * @return array{Customer, Order, list<OrderProduct>}
     */
    private function paidOrderWithTwoLines(): array
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $customer = $factory->customer($factory->customerTitle(), ['email' => uniqid('outermost-').'@test.com']);
        $order = $factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);
        $this->orderIds[] = (int) $order->getId();
        $lines = [];

        foreach ([1, 2] as $position) {
            $reference = uniqid('OUTERMOST-'.$position.'-');
            $lines[] = $factory->orderProduct($order, ['quantity' => 2.0, 'productRef' => $reference, 'productSaleElementsRef' => $reference.'-PSE']);
        }

        return [$customer, $order, $lines];
    }

    /**
     * Deletes, in foreign key order, what the test committed: the returns, the order with its
     * lines and history, its cart, its addresses and its customer.
     */
    private function deleteTheCommittedOrders(): void
    {
        $connection = $this->getPropelConnection();

        foreach ($this->orderIds as $orderId) {
            $read = $connection->prepare('SELECT customer_id, cart_id, invoice_order_address_id, delivery_order_address_id FROM `order` WHERE id = :id');
            $read->execute([':id' => $orderId]);
            $order = $read->fetch(\PDO::FETCH_ASSOC);
            $read->closeCursor();

            $connection->prepare('DELETE FROM order_return_version WHERE order_id = :id')->execute([':id' => $orderId]);
            $connection->prepare('DELETE FROM order_return WHERE order_id = :id')->execute([':id' => $orderId]);
            $connection->prepare('DELETE FROM `order` WHERE id = :id')->execute([':id' => $orderId]);

            if (!\is_array($order)) {
                continue;
            }

            $connection->prepare('DELETE FROM cart WHERE id = :id')->execute([':id' => $order['cart_id']]);
            $connection->prepare('DELETE FROM order_address WHERE id IN (:invoice, :delivery)')
                ->execute([':invoice' => $order['invoice_order_address_id'], ':delivery' => $order['delivery_order_address_id']]);
            $connection->prepare('DELETE FROM customer WHERE id = :id')->execute([':id' => $order['customer_id']]);
        }

        $this->orderIds = [];
    }
}
