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

namespace FlexyBundle\Service;

use FlexyBundle\Exception\TooManyReturnRequestsException;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Resource\Order as OrderResource;
use Thelia\Api\Resource\OrderProduct as OrderProductResource;
use Thelia\Api\Resource\OrderReturn as OrderReturnResource;
use Thelia\Api\Resource\OrderReturnLine as OrderReturnLineResource;
use Thelia\Api\Resource\OrderReturnReason as OrderReturnReasonResource;
use Thelia\Api\Service\OrderReturnHydrator;
use Thelia\Core\Event\OrderReturn\OrderReturnEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\OrderReturn\Exception\ReturnNotAllowedException;
use Thelia\Domain\OrderReturn\Service\OrderReturnHistoryRecorder;
use Thelia\Domain\OrderReturn\Service\OrderReturnWriteTransactionInterface;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Domain\OrderReturn\Service\ReturnRequestLimiter;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderReturn as OrderReturnModel;
use Thelia\Model\OrderReturnLine as OrderReturnLineModel;
use Thelia\Model\OrderReturnReasonQuery;

/**
 * Opens a return request on behalf of the signed-in customer.
 *
 * Nothing about what may be returned is decided here: the core owns that, and this
 * service walks the same path the front API does — the core's write transaction (rows
 * locked before anything is read), the eligibility gate and the line hydrator, the
 * request quota, then the mail and the history line once committed — so the theme cannot
 * let through what the API refuses. Only the orchestration differs: the API processor
 * reads its customer from the JWT token storage, which a front-office session does not
 * fill, so the customer is passed in.
 */
final readonly class OrderReturnRequestService
{
    /**
     * The customer comment is free text stored in a LONGVARCHAR column and printed
     * back on the tracking page and in the back office. Bounding it here keeps a
     * pasted novel out of the database and out of every screen showing it.
     */
    public const MAX_COMMENT_LENGTH = 2000;

    public function __construct(
        private ReturnEligibilityChecker $eligibility,
        private OrderReturnHydrator $hydrator,
        private EventDispatcherInterface $eventDispatcher,
        private OrderReturnWriteTransactionInterface $transaction,
        private ReturnRequestLimiter $limiter,
        private OrderReturnAttemptLimiter $attemptLimiter,
        private OrderReturnHistoryRecorder $historyRecorder,
    ) {
    }

    /**
     * How much of each line of the order may still be returned, keyed by order
     * product id. The quantity the form offers comes from here and nowhere else:
     * a number typed into the browser is re-read from this same source on submit.
     *
     * @return array<int, float>
     */
    public function returnableQuantities(Order $order): array
    {
        $quantities = [];

        foreach ($this->eligibility->returnableLines($order) as $line) {
            $quantities[(int) $line['order_product']->getId()] = $line['remaining'];
        }

        return $quantities;
    }

    /**
     * @param array<int, float> $requestedLines quantity to return, keyed by order product id
     *
     * @throws ReturnNotAllowedException      when the request breaks a return rule, or a lock conflict
     *                                        with another request on the order made the database give up
     *                                        (ReturnRequestConflictException: nothing was written)
     * @throws TooManyReturnRequestsException when the caller asked too often
     */
    public function open(
        Order $order,
        Customer $customer,
        array $requestedLines,
        ?int $reasonId,
        string $resolution,
        ?string $comment,
        ?string $expectedExchange = null,
    ): OrderReturnModel {
        $requestedLines = array_filter($requestedLines, static fn (float $quantity): bool => $quantity > 0);

        if ([] === $requestedLines) {
            throw new ReturnNotAllowedException('Select at least one product to return.');
        }

        if (!\in_array($resolution, OrderReturnModel::RESOLUTIONS, true)) {
            throw new ReturnNotAllowedException('The expected resolution is not one the shop offers.');
        }

        $comment = null === $comment ? null : trim($comment);

        if (null !== $comment && mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw new ReturnNotAllowedException('The comment is too long.');
        }

        // Kept on an exchange only, as the core does: a wish left in a form switched to a
        // refund is not refused for a field the customer no longer sees.
        $expectedExchange = OrderReturnModel::RESOLUTION_EXCHANGE === $resolution ? trim((string) $expectedExchange) : '';
        $expectedExchange = '' === $expectedExchange ? null : $expectedExchange;

        if (null !== $expectedExchange && mb_strlen($expectedExchange) > self::maxWishLength()) {
            throw new ReturnNotAllowedException('The wished exchange is too long.');
        }

        // Spent before anything reads the database, accepted or not: the transaction below
        // takes row locks, and the quota of accepted returns does not see a refused request.
        if (!$this->attemptLimiter->allows($customer)) {
            throw new TooManyReturnRequestsException('Too many return requests, please try again later.');
        }

        // A foreign order is refused before any lock, like the core's front processor does:
        // naming somebody else's order must not let a caller hold it, even for the length of
        // a refusal. The controller already hands over the customer's own order.
        if ((int) $order->getCustomerId() !== (int) $customer->getId()) {
            throw new ReturnNotAllowedException(ReturnEligibilityChecker::ORDER_NOT_USABLE_MESSAGE);
        }

        $resource = $this->buildResource($order, $requestedLines, $this->visibleReasonId($reasonId), $resolution, $comment, $expectedExchange);

        [$orderId, $orderProductIds] = OrderReturnHydrator::rowsToLock($resource);

        // Reading how much of a line is still returnable and writing the return that
        // consumes it are one step, or two requests arriving together are both allowed
        // the same last unit. The core's transaction locks the order, then its lines by
        // increasing id, before anything is read, and turns a deadlock or a stale row (1213,
        // 1020) into a refusal the customer can retry: a ReturnRequestConflictException is
        // a ReturnNotAllowedException, which the controller already shows.
        $model = $this->transaction->run($orderId, $orderProductIds, function (ConnectionInterface $connection) use ($resource, $order, $customer): OrderReturnModel {
            $this->hydrator->hydrate($resource, $customer, false);

            // The quota counts the returns a customer opens, not the mistakes they make
            // filling the form in: consumed only once hydration has accepted the request,
            // exactly where the core's own OrderReturnFrontCreateProcessor spends it. A form
            // submitted with an ineligible line never reaches this check, so it costs nothing
            // towards the quota an accepted request spends.
            //
            // Known limit: the token is not given back when the transaction is rolled back
            // after this point, by a deadlock (1213, "please try again") for instance. The
            // limiter has no per-token refund, and resetting the bucket would erase the
            // customer's whole count, so a customer who retries pays that one request twice.
            if (!$this->limiter->allows($customer)) {
                throw new TooManyReturnRequestsException('Too many return requests, please try again later.');
            }

            return $this->persist($resource, $order, $customer, $connection);
        });

        // Announced once the return is committed, never from inside the transaction: a mail
        // is not something a rollback takes back, and a line written inside it would go with
        // one. The line that opens the return on the history of its order comes first, so the
        // timeline shows the gesture before the mail that follows it, as the core's API
        // processors do. ORDER_RETURN_CREATE is not dispatched here, since the return is
        // already written: its listener would write that line and send the mail a second time.
        $this->historyRecorder->recordOpened($model);
        $this->eventDispatcher->dispatch(new OrderReturnEvent($model), TheliaEvents::ORDER_RETURN_SEND_STATUS_EMAIL);

        return $model;
    }

    /**
     * @param array<int, float> $requestedLines
     */
    private function buildResource(
        Order $order,
        array $requestedLines,
        ?int $reasonId,
        string $resolution,
        ?string $comment,
        ?string $expectedExchange,
    ): OrderReturnResource {
        $lines = [];

        foreach ($requestedLines as $orderProductId => $quantity) {
            $lines[] = (new OrderReturnLineResource())
                ->setOrderProduct((new OrderProductResource())->setId($orderProductId))
                ->setQuantity($quantity);
        }

        $resource = (new OrderReturnResource())
            ->setOrder((new OrderResource())->setId((int) $order->getId()))
            ->setOrderReturnLines($lines)
            ->setExpectedResolution($resolution)
            ->setCustomerComment($comment);

        if (null !== $expectedExchange && self::coreKeepsTheWish()) {
            $resource->setExpectedExchange($expectedExchange);
        }

        if (null !== $reasonId) {
            $resource->setOrderReturnReason((new OrderReturnReasonResource())->setId($reasonId));
        }

        return $resource;
    }

    /**
     * A reason the merchant has retired is still a row the browser can name. The
     * front only ever offers the visible ones, so an id that is not one of them is
     * dropped rather than recorded.
     */
    private function visibleReasonId(?int $reasonId): ?int
    {
        if (null === $reasonId) {
            return null;
        }

        $reason = OrderReturnReasonQuery::create()
            ->filterByVisible(true)
            ->findPk($reasonId);

        return null === $reason ? null : (int) $reason->getId();
    }

    /**
     * Writes the return the hydrator has just filled in.
     *
     * The core's own persist processor is not reachable from here: it reads the raw
     * request body and decodes it as JSON, which a browser form post is not. So the
     * mapping is spelled out — and it is only a mapping. Every value below was decided
     * by the core: the status, the owner, the reason snapshot, the refundable amounts
     * and the sale element to restock all come off the hydrated resource.
     */
    private function persist(
        OrderReturnResource $resource,
        Order $order,
        Customer $customer,
        ConnectionInterface $connection,
    ): OrderReturnModel {
        $model = (new OrderReturnModel())
            ->setOrderId((int) $order->getId())
            ->setCustomerId((int) $customer->getId())
            ->setStatusId($resource->getOrderReturnStatus()?->getId())
            ->setReasonId($resource->getOrderReturnReason()?->getId())
            ->setReasonTitle($resource->getReasonTitle())
            ->setExpectedResolution($resource->getExpectedResolution())
            ->setCustomerComment($resource->getCustomerComment())
            // A customer never asks for the postage back through this form: the refund of
            // the shipping costs is a merchant decision, taken in the back office.
            ->setIncludePostage(false)
            ->setCreatedByAdmin(false);

        if (self::coreKeepsTheWish()) {
            $model->setExpectedExchange($resource->getExpectedExchange());
        }

        // DECIMAL columns take a string in the native Propel models: handing them a float
        // is how a rounded amount gets written.
        $model->setRefundAmount($this->decimal($resource->getRefundAmount()));

        foreach ($resource->getOrderReturnLines() as $lineResource) {
            $model->addOrderReturnLine(
                (new OrderReturnLineModel())
                    ->setOrderProductId($lineResource->getOrderProduct()->getId())
                    ->setProductSaleElementsId($lineResource->getProductSaleElementsId())
                    ->setQuantity($lineResource->getQuantity())
                    ->setRefundAmount($this->decimal($lineResource->getRefundAmount())),
            );
        }

        $model->save($connection);

        return $model;
    }

    private function decimal(?float $amount): string
    {
        return number_format($amount ?? 0.0, 6, '.', '');
    }

    /**
     * The wished exchange has its own field from thelia/thelia#4218 on: a core without it
     * still opens the return, the wish is left out.
     */
    private static function coreKeepsTheWish(): bool
    {
        return method_exists(OrderReturnModel::class, 'setExpectedExchange');
    }

    /**
     * The core bounds the wish and checks it again in the hydrator: reading its bound
     * keeps a wish it would refuse from being refused only after the request quota is
     * used. A core without the field has no bound of its own.
     */
    private static function maxWishLength(): int
    {
        return \defined(OrderReturnResource::class.'::MAX_EXPECTED_EXCHANGE_LENGTH')
            ? OrderReturnResource::MAX_EXPECTED_EXCHANGE_LENGTH
            : self::MAX_COMMENT_LENGTH;
    }
}
