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

/**
 * The payment methods of the account page, as the template reads them.
 *
 * Read through the core service, scoped to the customer it is given: the account API
 * cannot be asked for them from here. Its ownership extension scopes the endpoint to the
 * token of the firewall, and a front page carries none (the customer lives in the Thelia
 * session), so an internal read answers an empty list whoever asks. Only what a customer
 * recognizes a method by is handed over: never the reference the provider keeps it under.
 */
final readonly class AccountPaymentMethods
{
    public function __construct(
        private CustomerPaymentMethodService $paymentMethodService,
        private LangService $langService,
    ) {
    }

    /**
     * The default method first, then the most recent.
     *
     * @return list<array{id: int, label: string, paymentModuleTitle: string, expiresAt: ?string, isDefault: bool}>
     */
    public function of(Customer $customer): array
    {
        $locale = $this->langService->getLocale();
        $methods = [];

        foreach ($this->paymentMethodService->listFor($customer) as $method) {
            $module = $method->getPaymentModule();

            $methods[] = [
                'id' => (int) $method->getId(),
                'label' => (string) $method->getLabel(),
                'paymentModuleTitle' => (string) ($module->setLocale($locale)->getTitle() ?: $module->getCode()),
                'expiresAt' => $method->getExpiresAt('Y-m-d'),
                'isDefault' => true === $method->getIsDefault(),
            ];
        }

        return $methods;
    }
}
