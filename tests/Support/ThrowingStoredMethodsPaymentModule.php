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

namespace FlexyBundle\Tests\Support;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Module\PaymentModuleWithStoredMethodsInterface;

/**
 * A payment module whose provider fails on an unexpected error that quotes a secret, the
 * way a client library does when its transport breaks: not a refusal the core knows.
 */
final class ThrowingStoredMethodsPaymentModule extends AbstractPaymentModule implements PaymentModuleWithStoredMethodsInterface
{
    public function destroy(?ConnectionInterface $con = null, $deleteModuleData = false): void
    {
    }

    public function pay(Order $order): ?Response
    {
        return null;
    }

    public function isValidPayment(): bool
    {
        return true;
    }

    public function supportsStoredMethods(): bool
    {
        return true;
    }

    public function payWithStoredMethod(Order $order, CustomerPaymentMethod $method): ?Response
    {
        return null;
    }

    public function forgetStoredMethod(CustomerPaymentMethod $method): void
    {
        throw new \RuntimeException('pm_secret');
    }
}
