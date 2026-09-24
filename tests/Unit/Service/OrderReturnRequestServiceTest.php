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
use Propel\Runtime\Exception\PropelException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Service\OrderReturnHydrator;
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
 * read - a plain database-map lookup, no connection ever opened - fails deterministically in
 * this suite, which runs on the autoloader alone and never boots the kernel. That failure is
 * used as the probe: whether it happens before or after the write transaction is entered is
 * exactly what tells the two calls apart, so the assertions below read it rather than work
 * around it. What it cannot show is the persist step that would follow a successful read -
 * that half of the ordering is checked by inspection, not by this suite (see the class-level
 *
 * @see below).
 * @see OrderReturnRequestService::open() the hydrate() call and the persist() call that
 *      follows it sit as the only two statements of the closure passed to run() - reading the
 *      production method alongside this test is part of the proof.
 */
#[CoversClass(OrderReturnRequestService::class)]
final class OrderReturnRequestServiceTest extends TestCase
{
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
        } catch (PropelException $exception) {
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

    private function service(OrderReturnWriteTransactionInterface $transaction): OrderReturnRequestService
    {
        $eligibility = new ReturnEligibilityChecker();
        $hydrator = new OrderReturnHydrator($eligibility, new OrderReturnComposer($eligibility, new RefundAmountCalculator()));

        $limiter = $this->createMock(ReturnRequestLimiter::class);
        $limiter->method('allows')->willReturn(true);

        return new OrderReturnRequestService(
            $eligibility,
            $hydrator,
            $this->createStub(EventDispatcherInterface::class),
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
