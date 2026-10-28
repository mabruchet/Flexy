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
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Order\Enum\OrderHistoryEventType;
use Thelia\Domain\OrderReturn\Exception\ReturnNotAllowedException;
use Thelia\Domain\OrderReturn\Exception\ReturnRequestConflictException;
use Thelia\Domain\OrderReturn\Service\OrderReturnWriteTransactionInterface;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderHistoryQuery;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderReturnQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Thelia\Test\Trait\RecordsSqlQueries;

/**
 * A return opened from the account form is written the way the core's front processor
 * writes one: in the core's write transaction, the order and its lines locked before
 * anything is read, a lock conflict answered as a refusal, and the customer mailed once
 * through the core's dispatcher. Runs on an installed shop (bin/test-prepare).
 */
final class OrderReturnWriteTransactionPathTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private ?string $previousEnabled = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnabled = ConfigQuery::read(ReturnEligibilityChecker::ENABLED_CONFIG_KEY);
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '1');
    }

    protected function tearDown(): void
    {
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, (string) $this->previousEnabled);

        parent::tearDown();
    }

    public function testTheReturnIsWrittenInsideTheCoreTransactionNamingTheOrderAndItsLines(): void
    {
        [$customer, $order, $lines] = $this->paidOrderWithTwoLines();
        $spy = $this->spyOnTheCoreTransaction();

        $this->service($spy)->open($order, $customer, [(int) $lines[1]->getId() => 1.0, (int) $lines[0]->getId() => 1.0], null, 'refund', null);

        self::assertCount(1, $spy->calls, 'Opening a return runs exactly one core write transaction.');
        self::assertSame((int) $order->getId(), $spy->calls[0]['orderId']);
        self::assertEqualsCanonicalizing(
            [(int) $lines[0]->getId(), (int) $lines[1]->getId()],
            $spy->calls[0]['orderProductIds'],
            'The transaction is told which lines the request is about, so it can lock them first.',
        );
        self::assertSame(
            1,
            OrderReturnQuery::create()->filterByOrderId((int) $order->getId())->count(),
            'The return is written by the work run in that transaction.',
        );
    }

    public function testTheRowsAreLockedBeforeAnythingAboutTheReturnIsRead(): void
    {
        [$customer, $order, $lines] = $this->paidOrderWithTwoLines();
        $requested = [(int) $lines[1]->getId() => 1.0, (int) $lines[0]->getId() => 1.0];

        $statements = $this->recordSqlQueries(function () use ($order, $customer, $requested): void {
            $this->service()->open($order, $customer, $requested, null, 'refund', null);
        });

        $locks = array_keys(array_filter($statements, static fn (string $statement): bool => str_contains($statement, 'FOR UPDATE')));
        self::assertGreaterThanOrEqual(3, \count($locks), 'The order and its two lines are locked.');

        $firstThree = array_map(static fn (int $index): string => $statements[$index], \array_slice($locks, 0, 3));
        // The statements are logged with their placeholders: the ids' ascending order is
        // the core transaction's own, proven by its tests, and not read back from here.
        self::assertStringContainsString('FROM `order` WHERE', $firstThree[0], 'The order is locked first.');
        self::assertStringContainsString('FROM `order_product` WHERE', $firstThree[1], 'Then its lines.');
        self::assertStringContainsString('FROM `order_product` WHERE', $firstThree[2]);

        // Under REPEATABLE READ the first plain read fixes what the transaction sees: nothing
        // that belongs to the return may be read before the last lock is held. The only read
        // allowed earlier is the plain, lock-free one of the line ids, outside the transaction.
        foreach ($statements as $index => $statement) {
            if ($index >= $locks[2]) {
                break;
            }

            self::assertFalse(
                str_contains($statement, 'FROM `order_return') || str_contains($statement, 'FROM `order_history')
                || (str_contains($statement, 'FROM `order`') && !str_contains($statement, 'FOR UPDATE')),
                'Read before the rows were locked: '.$statement,
            );
        }
    }

    public function testALockConflictIsARefusalThatWritesNothingAndMailsNobody(): void
    {
        [$customer, $order, $lines] = $this->paidOrderWithTwoLines();
        $mails = 0;
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            TheliaEvents::ORDER_RETURN_SEND_STATUS_EMAIL,
            static function () use (&$mails): void {
                ++$mails;
            },
            1024,
        );
        $conflict = new class implements OrderReturnWriteTransactionInterface {
            public function run(int $orderId, array $orderProductIds, callable $work): mixed
            {
                throw new ReturnRequestConflictException(ReturnRequestConflictException::MESSAGE);
            }

            public function readsSeeCommits(): bool
            {
                return false;
            }
        };

        try {
            $this->service($conflict)->open($order, $customer, [(int) $lines[0]->getId() => 1.0], null, 'refund', null);
            self::fail('A conflict was expected to be refused.');
        } catch (ReturnNotAllowedException $exception) {
            self::assertInstanceOf(ReturnRequestConflictException::class, $exception);
        }

        self::assertFalse(OrderReturnQuery::create()->filterByOrderId((int) $order->getId())->exists());
        self::assertSame(0, OrderHistoryQuery::create()->filterByOrderId((int) $order->getId())->filterByEventType(OrderHistoryEventType::RETURN_OPENED->value)->count());
        self::assertSame(0, $mails, 'Nobody is told about a return that was not written.');
    }

    public function testAnotherCustomersOrderIsRefusedBeforeAnyRowIsLocked(): void
    {
        [, $order, $lines] = $this->paidOrderWithTwoLines();
        $intruder = $this->factory()->customer($this->factory()->customerTitle());
        $spy = $this->spyOnTheCoreTransaction();

        $statements = $this->recordSqlQueries(function () use ($spy, $order, $intruder, $lines): void {
            try {
                $this->service($spy)->open($order, $intruder, [(int) $lines[0]->getId() => 1.0], null, 'refund', null);
                self::fail('The order of somebody else was expected to be refused.');
            } catch (ReturnNotAllowedException) {
            }
        });

        self::assertSame([], $spy->calls, 'No transaction is opened on the order of somebody else.');
        self::assertSame([], array_filter($statements, static fn (string $statement): bool => str_contains($statement, 'FOR UPDATE')), 'And no row of it is locked.');
    }

    private function service(?OrderReturnWriteTransactionInterface $transaction = null): OrderReturnRequestService
    {
        if (null === $transaction) {
            $service = $this->getService(OrderReturnRequestService::class);
            self::assertInstanceOf(OrderReturnRequestService::class, $service);

            return $service;
        }

        $reflection = new \ReflectionClass(OrderReturnRequestService::class);
        $container = self::getContainer();
        $real = $container->get(OrderReturnRequestService::class);
        $arguments = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $arguments[] = 'transaction' === $parameter->getName()
                ? $transaction
                : $reflection->getProperty($parameter->getName())->getValue($real);
        }

        return new OrderReturnRequestService(...$arguments);
    }

    private function spyOnTheCoreTransaction(): object
    {
        return new class(self::getContainer()->get(OrderReturnWriteTransactionInterface::class)) implements OrderReturnWriteTransactionInterface {
            /** @var list<array{orderId: int, orderProductIds: list<int>}> */
            public array $calls = [];

            public function __construct(private readonly OrderReturnWriteTransactionInterface $inner)
            {
            }

            public function run(int $orderId, array $orderProductIds, callable $work): mixed
            {
                $this->calls[] = ['orderId' => $orderId, 'orderProductIds' => $orderProductIds];

                return $this->inner->run($orderId, $orderProductIds, static fn (ConnectionInterface $connection): mixed => $work($connection));
            }

            public function readsSeeCommits(): bool
            {
                return $this->inner->readsSeeCommits();
            }
        };
    }

    /**
     * @return array{Customer, Order, list<OrderProduct>}
     */
    private function paidOrderWithTwoLines(): array
    {
        $factory = $this->factory();
        $customer = $factory->customer($factory->customerTitle());
        $order = $factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);
        $lines = [];

        foreach ([1, 2] as $position) {
            $reference = 'WRITE-TX-'.$order->getId().'-'.$position;
            $line = (new OrderProduct())
                ->setOrderId((int) $order->getId())
                ->setProductRef($reference)
                ->setProductSaleElementsRef($reference.'-PSE')
                ->setTitle('A chair to send back')
                ->setQuantity(2.0)
                ->setPrice('100.000000')
                ->setPromoPrice('0.000000')
                ->setWasNew(0)
                ->setWasInPromo(0)
                ->setVirtual(0);
            $line->save($this->getPropelConnection());
            $lines[] = $line;
        }

        return [$customer, $order, $lines];
    }

    private function factory(): FixtureFactory
    {
        return new FixtureFactory($this->getPropelConnection());
    }
}
