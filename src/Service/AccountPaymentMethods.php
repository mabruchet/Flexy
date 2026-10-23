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

use Thelia\Domain\Localization\Service\LangService;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\Module;

/**
 * The payment methods of the account page, as the template reads them.
 *
 * Read through the core service, scoped to the customer it is given, and not through the
 * account API: the service reads the availability of the module of each method, which the
 * page needs to tell a method that can still pay from one that cannot, and a front page
 * carries no token for the API anyway (the customer lives in the Thelia session). Only what
 * a customer recognizes a method by is handed over: never the reference the provider keeps it under.
 */
final readonly class AccountPaymentMethods
{
    public function __construct(
        private CustomerPaymentMethodService $paymentMethodService,
        private LangService $langService,
        private StoredPaymentMethodsAvailability $availability,
    ) {
    }

    /**
     * The default method first, then the most recent.
     *
     * @return list<array{id: int, label: string, paymentModuleTitle: string, expiresAt: ?string, expired: bool, isDefault: bool, usable: bool}>
     */
    public function of(Customer $customer): array
    {
        $locale = $this->langService->getLocale();
        $today = new \DateTimeImmutable('today');
        $methods = [];
        $usableModules = [];

        foreach ($this->paymentMethodService->listFor($customer) as $method) {
            $module = $method->getPaymentModule();
            $usableModules[$module->getId()] ??= $this->moduleCanPay($module);

            $methods[] = [
                'id' => (int) $method->getId(),
                'label' => (string) $method->getLabel(),
                'paymentModuleTitle' => (string) ($module->setLocale($locale)->getTitle() ?: $module->getCode()),
                'expiresAt' => $method->getExpiresAt('Y-m-d'),
                'expired' => $method->isExpiredOn($today),
                'isDefault' => true === $method->getIsDefault(),
                // A default on a method the checkout will never offer would mislead.
                'usable' => $usableModules[$module->getId()] && !$method->isExpiredOn($today),
            ];
        }

        return $methods;
    }

    /**
     * Whether the checkout would offer the method: its module is active and offers stored
     * methods, and the method has not expired. The page offers the default choice only for
     * such a method, and the write refuses any other.
     */
    public function isUsable(CustomerPaymentMethod $method): bool
    {
        return $this->moduleCanPay($method->getPaymentModule()) && !$method->isExpiredOn(new \DateTimeImmutable('today'));
    }

    private function moduleCanPay(Module $module): bool
    {
        return (bool) $module->getActivate() && $this->availability->isOfferedBy($module);
    }
}
