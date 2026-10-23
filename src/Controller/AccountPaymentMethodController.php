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

namespace FlexyBundle\Controller;

use FlexyBundle\Service\AccountPaymentMethods;
use FlexyBundle\Service\StoredPaymentMethodsAvailability;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\Payment\Exception\CustomerPaymentMethodException;
use Thelia\Domain\Payment\Exception\PaymentMethodNotOwnedException;
use Thelia\Domain\Payment\Exception\StoredMethodRefusedException;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;

/**
 * The payment methods a customer asked the shop to keep, in the customer account.
 *
 * The list is the customer's own, read through the core service (see AccountPaymentMethods
 * for why the account API is not used). The two writes go through the core service and not
 * through a model, which asks the provider before it forgets a method and keeps the default
 * unique. Each is a POST carrying a CSRF token and answered by a redirect, like the
 * address book. A method of another customer answers as one that does not exist.
 *
 * The page answers 404 when no active payment module keeps payment methods, the same
 * rule that leaves its link out of the account menu.
 */
#[Route('/account/payment-methods', name: 'account_payment_method')]
class AccountPaymentMethodController extends FlexyController
{
    public const ACTION_TOKEN_ID = 'payment_method_action';

    #[Route('', name: 's', methods: ['GET'])]
    public function index(AccountPaymentMethods $accountPaymentMethods, StoredPaymentMethodsAvailability $availability): Response
    {
        $customer = $this->signedInCustomer();

        if (!$availability->isOffered()) {
            throw new NotFoundHttpException();
        }

        return $this->render('account-payment-methods', ['paymentMethods' => $accountPaymentMethods->of($customer)]);
    }

    #[Route('/{methodId}/delete', name: '_delete', requirements: ['methodId' => '\d+'], methods: ['POST'])]
    public function delete(
        CustomerPaymentMethodService $paymentMethodService,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
        int $methodId,
    ): RedirectResponse {
        $customer = $this->signedInCustomer();
        $this->checkToken($csrfTokenManager, $request);
        $method = $this->ownedMethod($paymentMethodService, $customer, $methodId);

        try {
            $paymentMethodService->forget($customer, $method);
        } catch (StoredMethodRefusedException) {
            // What the provider said is for the logs of the core, never for the page.
            return $this->generateRedirect($this->generateUrl('account_payment_methods', ['refused' => true]));
        } catch (PaymentMethodNotOwnedException $exception) {
            throw new NotFoundHttpException('No such payment method.', $exception);
        } catch (CustomerPaymentMethodException $exception) {
            $this->logger->error(\sprintf('Error during payment method deletion process: %s.', $exception::class));

            return $this->generateRedirect($this->generateUrl('account_payment_methods', ['error' => true]));
        }

        return $this->generateRedirect($this->generateUrl('account_payment_methods', ['delete_success' => true]));
    }

    #[Route('/{methodId}/default', name: '_default', requirements: ['methodId' => '\d+'], methods: ['POST'])]
    public function makeDefault(
        CustomerPaymentMethodService $paymentMethodService,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
        int $methodId,
    ): RedirectResponse {
        $customer = $this->signedInCustomer();
        $this->checkToken($csrfTokenManager, $request);
        $method = $this->ownedMethod($paymentMethodService, $customer, $methodId);

        try {
            $paymentMethodService->setDefault($customer, $method);
        } catch (PaymentMethodNotOwnedException $exception) {
            throw new NotFoundHttpException('No such payment method.', $exception);
        } catch (CustomerPaymentMethodException $exception) {
            $this->logger->error(\sprintf('Error during default payment method process: %s.', $exception::class));

            return $this->generateRedirect($this->generateUrl('account_payment_methods', ['error' => true]));
        }

        return $this->generateRedirect($this->generateUrl('account_payment_methods', ['default_success' => true]));
    }

    private function signedInCustomer(): Customer
    {
        $this->checkAuth();
        $customer = $this->getSecurityContext()->getCustomerUser();

        if (!$customer instanceof Customer) {
            throw new AccessDeniedHttpException();
        }

        return $customer;
    }

    private function checkToken(CsrfTokenManagerInterface $csrfTokenManager, Request $request): void
    {
        if (!$csrfTokenManager->isTokenValid(new CsrfToken(self::ACTION_TOKEN_ID, (string) $request->request->get('_token')))) {
            throw new AccessDeniedHttpException();
        }
    }

    private function ownedMethod(CustomerPaymentMethodService $paymentMethodService, Customer $customer, int $methodId): CustomerPaymentMethod
    {
        return $paymentMethodService->findOwned($customer, $methodId)
            ?? throw new NotFoundHttpException('No such payment method.');
    }
}
