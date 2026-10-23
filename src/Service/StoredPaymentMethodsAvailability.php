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

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Model\CustomerPaymentMethodQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;

/**
 * Whether the account offers the page of the saved payment methods.
 *
 * It does when an active payment module keeps payment methods for its customers right
 * now, or when the signed-in customer already holds at least one, whatever its module
 * became since (switched off, or no longer offering them): the customer must be able to
 * see and remove what the shop keeps for them. Otherwise the menu would link to a page the
 * shop has nothing to put on.
 *
 * The modules are asked through the core rule the registration and the checkout apply
 * too: a module can depend on its configuration or on its provider account. The customer
 * is looked up by one bounded existence query, never by a list.
 *
 * The account menu is built twice on a page (sub-header and profile dropdown) and the
 * modules are asked at each build, so the answer is kept for the request that asked.
 * Keyed by the request and not stored as a flag: the service outlives a request in a
 * worker, and a module can be switched off between two of them.
 */
final class StoredPaymentMethodsAvailability
{
    /** @var \WeakMap<Request, bool> */
    private \WeakMap $answers;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SecurityContext $securityContext,
        private readonly CustomerPaymentMethodService $paymentMethodService,
        private readonly LoggerInterface $logger,
    ) {
        $this->answers = new \WeakMap();
    }

    public function isAvailable(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return $this->ask();
        }

        return $this->answers[$request] ??= $this->ask();
    }

    /**
     * Whether the module offers to keep new payment methods right now.
     */
    public function isOfferedBy(Module $module): bool
    {
        return $this->offers($module);
    }

    private function ask(): bool
    {
        if ($this->customerHoldsAMethod()) {
            return true;
        }

        $modules = ModuleQuery::create()
            ->filterByType(BaseModule::PAYMENT_MODULE_TYPE)
            ->filterByActivate(BaseModule::IS_ACTIVATED)
            ->find()
        ;

        foreach ($modules as $module) {
            if ($this->offers($module)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A module that fails to answer loses its own entry only: this runs on every page of
     * a signed-in customer, and one broken provider must not take the whole account down.
     */
    private function offers(Module $module): bool
    {
        try {
            return $this->paymentMethodService->offersStoredMethods($module);
        } catch (\Throwable $throwable) {
            $this->logger->error(\sprintf(
                'Payment module "%s" could not say whether it offers stored methods: %s.',
                $module->getCode(),
                $throwable::class,
            ));

            return false;
        }
    }

    private function customerHoldsAMethod(): bool
    {
        if (!$this->securityContext->hasAuthenticatedCustomerUser()) {
            return false;
        }

        $customerId = $this->securityContext->getCustomerUser()?->getId();

        return null !== $customerId && CustomerPaymentMethodQuery::create()->filterByCustomerId($customerId)->exists();
    }
}
