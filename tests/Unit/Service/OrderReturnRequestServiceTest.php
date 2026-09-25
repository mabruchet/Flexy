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

namespace FlexyBundle\Tests\Unit\Service;

use FlexyBundle\Service\OrderReturnRequestService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\ExceptionInterface as PropelExceptionInterface;
use Propel\Runtime\Propel;
use Propel\Runtime\ServiceContainer\ServiceContainerInterface;
use Propel\Runtime\ServiceContainer\StandardServiceContainer;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Service\OrderReturnHydrator;
use Thelia\Api\Service\OrderReturnStatusEmailDispatcher;
use Thelia\Domain\OrderReturn\Service\OrderReturnComposer;
use Thelia\Domain\OrderReturn\Service\OrderReturnWriteTransactionInterface;
use Thelia\Domain\OrderReturn\Service\RefundAmountCalculator;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Domain\OrderReturn\Service\ReturnRequestLimiter;
use Thelia\Model\Customer;
use Thelia\Model\Order;

/**
 * A return opened from the theme must be written in the core's shared write transaction,
 * which locks the order and its lines before the first read - never read first and locked
 * after, and never through a transaction of the theme's own.
 *
 * The eligibility checker and the hydrator are the core's own final classes, wired here for
 * real rather than doubled: PHPUnit refuses to double a final class, and their first Propel
 * read - a plain database-map lookup, no connection ever opened - is used as the probe: whether
 * it fails before or after the write transaction is entered is exactly what tells the two calls
 * apart. That read only fails deterministically against a database map Propel considers
 * uninitialized (`Propel::getServiceContainer()->getDatabaseMap()` throws as long as nothing has
 * populated it yet), which is process state, not something this suite controls by itself: a
 * component test run first in the same process, a future Unit test that boots the kernel, or a
 * random test order would each leave the shared service container in a different state, and one
 * of those states drops the read straight through to a live connection attempt instead - a
 * different Propel exception, or (with a reachable database) no exception at all. setUp()
 * installs a brand new `StandardServiceContainer` before every test method, and tearDown() puts
 * the previous one back, so the probe always starts from the same uninitialized map regardless of
 * what ran before it in the process. The two exception classes Propel actually throws along this
 * path - `PropelException` for the uninitialized map and the plain `RuntimeException` for a
 * database map that exists but names no connection - share no common ancestor other than
 * `Propel\Runtime\Exception\ExceptionInterface`, so that is what is caught, not either concrete
 * class. What this cannot show is the persist step that would follow a successful read - that
 * half of the ordering is checked by inspection, not by this suite.
 *
 * @see OrderReturnRequestService::open() the hydrate() call, the quota check and the persist()
 *      call that follows sit as the only statements of the closure passed to run() - reading the
 *      production method alongside this test is part of the proof.
 */
#[CoversClass(OrderReturnRequestService::class)]
final class OrderReturnRequestServiceTest extends TestCase
{
    private ServiceContainerInterface $previousServiceContainer;

    protected function setUp(): void
    {
        // A fresh container's database maps start uninitialized, so the probe in
        // testHydrationHappensAfterTheWriteTransactionIsEntered() fails the same way no matter
        // what an earlier test - in this class, in this suite, or in a future one sharing the
        // process - already did to Propel's static, process-wide service container.
        $this->previousServiceContainer = Propel::getServiceContainer();
        Propel::setServiceContainer(new StandardServiceContainer());
    }

    protected function tearDown(): void
    {
        Propel::setServiceContainer($this->previousServiceContainer);
    }

    public function testTheWriteTransactionReceivesTheOrderAndOnlyTheRetainedLines(): void
    {
        $transaction = new RecordingWriteTransaction($this->createStub(ConnectionInterface::class));

        try {
            $this->service($transaction)->open(
                $this->order(42),
                $this->customer(7),
                [101 => 1.5, 102 => 2.0, 103 => 0.0],
                null,
                'refund',
                null,
            );
        } catch (\Throwable) {
            // The hydrator's first read needs a booted kernel this suite does not have -
            // only the arguments handed to the transaction are asserted here.
        }

        self::assertSame(42, $transaction->capturedOrderId);
        self::assertSame([101, 102], $transaction->capturedOrderProductIds, 'A line asked with a zero quantity is not one the transaction should lock.');
    }

    public function testHydrationHappensAfterTheWriteTransactionIsEntered(): void
    {
        $transaction = new RecordingWriteTransaction($this->createStub(ConnectionInterface::class));

        try {
            $this->service($transaction)->open($this->order(42), $this->customer(7), [101 => 1.0], null, 'refund', null);
            self::fail('The real hydrator was expected to fail outside a booted kernel.');
        } catch (PropelExceptionInterface $exception) {
            self::assertTrue(
                $transaction->enteredRun,
                'The read failed before the write transaction was entered: hydrate() runs outside the callback.',
            );
            self::assertTrue(
                $this->traceCalls(OrderReturnHydrator::class, 'hydrate', $exception),
                'The failing read did not come from the hydrator: hydrate() may not have run at all.',
            );
        }
    }

    /**
     * The quota is spent on an accepted return, not on a customer's typo: a line that
     * fails hydration must never reach the limiter, or twenty malformed submissions on
     * the same key would close the hour for the one valid request behind them.
     */
    public function testAFailingHydrationDoesNotConsumeTheQuota(): void
    {
        $transaction = new RecordingWriteTransaction($this->createStub(ConnectionInterface::class));

        $limiter = $this->createMock(ReturnRequestLimiter::class);
        $limiter->expects(self::never())->method('allows');

        try {
            $this->service($transaction, $limiter)->open($this->order(42), $this->customer(7), [101 => 1.0], null, 'refund', null);
            self::fail('The real hydrator was expected to fail outside a booted kernel.');
        } catch (PropelExceptionInterface) {
            // The mock expectation above is the assertion: PHPUnit fails the test on
            // tearDown if allows() was called before this catch was reached.
        }
    }

    public function testAnExceptionRaisedInsideTheCallbackReachesTheCallerUnchanged(): void
    {
        $transaction = new RecordingWriteTransaction($this->createStub(ConnectionInterface::class));

        try {
            $this->service($transaction)->open($this->order(42), $this->customer(7), [101 => 1.0], null, 'refund', null);
            self::fail('expected an exception to be thrown');
        } catch (\Throwable $exception) {
            self::assertNotNull($transaction->exceptionSeenInsideRun);
            self::assertSame(
                $transaction->exceptionSeenInsideRun,
                $exception,
                'open() must let whatever the callback throws through unchanged, not catch and rethrow another exception.',
            );
        }
    }

    private function service(OrderReturnWriteTransactionInterface $transaction, ?ReturnRequestLimiter $limiter = null): OrderReturnRequestService
    {
        $eligibility = new ReturnEligibilityChecker();
        $hydrator = new OrderReturnHydrator($eligibility, new OrderReturnComposer($eligibility, new RefundAmountCalculator()));

        if (null === $limiter) {
            $limiter = $this->createMock(ReturnRequestLimiter::class);
            $limiter->method('allows')->willReturn(true);
        }

        return new OrderReturnRequestService(
            $eligibility,
            $hydrator,
            new OrderReturnStatusEmailDispatcher($this->createStub(EventDispatcherInterface::class)),
            $limiter,
            $transaction,
        );
    }

    private function order(int $id): Order
    {
        return (new Order())->setId($id);
    }

    private function customer(int $id): Customer
    {
        return (new Customer())->setId($id);
    }

    private function traceCalls(string $class, string $method, \Throwable $exception): bool
    {
        foreach ($exception->getTrace() as $frame) {
            if (($frame['class'] ?? null) === $class && ($frame['function'] ?? null) === $method) {
                return true;
            }
        }

        return false;
    }
}

final class RecordingWriteTransaction implements OrderReturnWriteTransactionInterface
{
    public ?int $capturedOrderId = null;

    /** @var list<int>|null */
    public ?array $capturedOrderProductIds = null;

    public bool $enteredRun = false;

    public ?\Throwable $exceptionSeenInsideRun = null;

    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function run(int $orderId, array $orderProductIds, callable $work): mixed
    {
        $this->capturedOrderId = $orderId;
        $this->capturedOrderProductIds = $orderProductIds;
        $this->enteredRun = true;

        try {
            return $work($this->connection);
        } catch (\Throwable $exception) {
            $this->exceptionSeenInsideRun = $exception;

            throw $exception;
        }
    }
}
