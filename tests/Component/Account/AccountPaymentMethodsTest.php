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

namespace FlexyBundle\Tests\Component\Account;

use FlexyBundle\Tests\Support\ThrowingStoredMethodsPaymentModule;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouterInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\CustomerPaymentMethodQuery;
use Thelia\Model\Map\CustomerPaymentMethodTableMap;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;
use Thelia\Tests\Support\Payment\StoredMethodsPaymentModule;
use Thelia\Tests\Support\Payment\UsesStoredMethodsPaymentModule;

/**
 * The "My payment methods" page of the customer account, from a browser's point of view.
 *
 * What the theme owes the core: the entry of the menu and the page exist only while an
 * active payment module keeps payment methods; the list holds the signed-in customer's
 * methods and nobody else's; both writes go through the core service, behind a CSRF token,
 * and a method of another customer reads as one that does not exist.
 */
final class AccountPaymentMethodsTest extends WebIntegrationTestCase
{
    use UsesStoredMethodsPaymentModule;

    private const string MENU_LINK = 'a[href="/account/payment-methods"]';

    private FixtureFactory $factory;

    private CustomerSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        StoredMethodsPaymentModule::reset();
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
        $this->uninstallTheStoredMethodsPaymentModule(static::getContainer());
        static::getContainer()->set('module.'.ThrowingStoredMethodsPaymentModule::getModuleCode(), null);

        parent::tearDown();
    }

    public function testTheMenuOffersNoPaymentMethodsWithoutAModuleThatKeepsThem(): void
    {
        $this->signedInCustomer();

        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::MENU_LINK), 'No link to a page the shop has nothing to put on.');
    }

    public function testTheMenuOffersNoPaymentMethodsWhenTheModuleStoppedKeepingThem(): void
    {
        $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        StoredMethodsPaymentModule::$offersStoredMethods = false;
        $this->signedInCustomer();

        $crawler = $this->client->request('GET', '/account');

        self::assertCount(0, $crawler->filter(self::MENU_LINK));
    }

    public function testTheMenuOffersPaymentMethodsInTheSubHeaderAndTheProfileDropdown(): void
    {
        $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $this->signedInCustomer();

        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(2, $crawler->filter(self::MENU_LINK)->count(), 'Sub-header and header profile both list it.');
        self::assertStringContainsString('My payment methods', $crawler->filter('.Subheader-links')->text());
    }

    public function testTheAnswerOfOneRequestIsNotKeptForTheNext(): void
    {
        $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $this->signedInCustomer();

        $first = $this->client->request('GET', '/account');
        StoredMethodsPaymentModule::$offersStoredMethods = false;
        $second = $this->client->request('GET', '/account');

        self::assertGreaterThanOrEqual(1, $first->filter(self::MENU_LINK)->count());
        self::assertCount(0, $second->filter(self::MENU_LINK), 'A worker serves many requests: a module switched off in between is no longer offered.');
    }

    public function testACustomerHoldingAMethodKeepsThePageWhenItsModuleStoppedOfferingThem(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        StoredMethodsPaymentModule::$offersStoredMethods = false;
        $customer = $this->signedInCustomer();
        $this->methodOf($customer, $module, 'Visa ending 4242', null, true);
        $this->methodOf($customer, $module, 'Mastercard ending 1111');

        $menu = $this->client->request('GET', '/account');
        self::assertGreaterThanOrEqual(2, $menu->filter(self::MENU_LINK)->count());

        $page = $this->client->request('GET', '/account/payment-methods');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $page->filter('[data-testid="payment-method"]'));
        self::assertCount(0, $page->filter('[data-modal="confirmDefaultPaymentMethod"]'), 'A method nothing can pay with any more is not offered as the default.');
        self::assertCount(2, $page->filter('[data-modal="confirmDeletePaymentMethod"]'), 'Deleting stays possible.');
    }

    public function testAMethodOfAModuleThatStoppedOfferingThemCanStillBeDeleted(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        StoredMethodsPaymentModule::$offersStoredMethods = false;
        $method = $this->methodOf($this->signedInCustomer(), $module, 'Visa ending 4242');

        $this->client->request('POST', $this->url('account_payment_method_delete', ['methodId' => $method->getId()]), ['_token' => $this->token()]);

        self::assertResponseRedirects('/account/payment-methods?delete_success=1');
        self::assertFalse($this->stillStored($method));
        self::assertSame([(int) $method->getId()], StoredMethodsPaymentModule::$forgottenMethodIds, 'The provider is still told: the core asks any module that implements the interface.');
    }

    public function testACustomerHoldingAMethodOfADeactivatedModuleKeepsThePageAndCanDeleteIt(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $method = $this->methodOf($customer, $module, 'Visa ending 4242', null, true);
        $this->methodOf($customer, $module, 'Mastercard ending 1111');
        $module->setActivate(0)->save($this->getPropelConnection());

        $menu = $this->client->request('GET', '/account');
        self::assertGreaterThanOrEqual(1, $menu->filter(self::MENU_LINK)->count());

        $page = $this->client->request('GET', '/account/payment-methods');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Visa ending 4242', $page->text());
        self::assertCount(0, $page->filter('[data-modal="confirmDefaultPaymentMethod"]'), 'A method of a deactivated module is not offered as the default.');

        $this->client->request('POST', $this->url('account_payment_method_delete', ['methodId' => $method->getId()]), ['_token' => $this->token()]);

        self::assertResponseRedirects('/account/payment-methods?delete_success=1');
        self::assertFalse($this->stillStored($method));
    }

    public function testAMethodOfAnotherCustomerOpensNothingToACustomerWithNone(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        StoredMethodsPaymentModule::$offersStoredMethods = false;
        $this->methodOf($this->factory->customer($this->factory->customerTitle()), $module, 'Theirs');
        $this->signedInCustomer();

        $menu = $this->client->request('GET', '/account');
        self::assertCount(0, $menu->filter(self::MENU_LINK));

        $this->client->request('GET', '/account/payment-methods');
        self::assertResponseStatusCodeSame(404);
    }

    public function testThePageIsNotFoundWithoutAModuleThatKeepsPaymentMethods(): void
    {
        $this->signedInCustomer();

        $this->client->request('GET', '/account/payment-methods');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAVisitorIsSentToTheLoginPage(): void
    {
        $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());

        $this->client->request('GET', '/account/payment-methods');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAVisitorCannotWrite(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $theirs = $this->methodOf($this->factory->customer($this->factory->customerTitle()), $module, 'Not for a visitor');

        foreach (['delete', 'default'] as $action) {
            $this->client->request('POST', $this->url('account_payment_method_'.$action, ['methodId' => $theirs->getId()]), ['_token' => 'forged']);

            self::assertResponseRedirects();
            self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        }

        self::assertTrue($this->stillStored($theirs));
        self::assertSame([], StoredMethodsPaymentModule::$forgottenMethodIds);
    }

    public function testTheListHoldsTheMethodsOfTheSignedInCustomerOnly(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $this->methodOf($customer, $module, 'Visa ending 4242', '2031-12-31', true);
        $this->methodOf($customer, $module, 'Mastercard ending 1111');
        $this->methodOf($this->factory->customer($this->factory->customerTitle()), $module, 'Amex ending 0005');

        $crawler = $this->client->request('GET', '/account/payment-methods');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('[data-testid="payment-method"]')->each(static fn (Crawler $card): string => $card->text());
        self::assertCount(2, $text);
        self::assertStringContainsString('Visa ending 4242', $text[0]);
        self::assertStringContainsString('Mastercard ending 1111', $text[1]);
        self::assertStringNotContainsString('Amex ending 0005', (string) $this->client->getResponse()->getContent());
    }

    public function testACardShowsTheModuleTheExpiryAndWhichOneIsTheDefault(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $this->methodOf($customer, $module, 'Visa ending 4242', '2031-12-31', true);
        $this->methodOf($customer, $module, 'Never expires');

        $crawler = $this->client->request('GET', '/account/payment-methods');

        $cards = $crawler->filter('[data-testid="payment-method"]');
        self::assertCount(2, $cards);
        self::assertStringContainsString('12/2031', $cards->eq(0)->text());
        self::assertCount(1, $cards->eq(0)->filter('.Favorite--selected'), 'The default method is marked.');
        self::assertStringNotContainsString('Expires', $cards->eq(1)->text(), 'A method with no known expiry shows none.');
        self::assertCount(0, $cards->eq(1)->filter('.Favorite--selected'));
    }

    public function testAnExpiredMethodIsToldSo(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $this->methodOf($this->signedInCustomer(), $module, 'Old card', '2020-01-31');

        $crawler = $this->client->request('GET', '/account/payment-methods');

        self::assertStringContainsString('Expired (valid until 01/2020)', $crawler->filter('[data-testid="payment-method"]')->text());
    }

    public function testACustomerWithoutAMethodSeesAMessage(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $this->signedInCustomer();
        $this->methodOf($this->factory->customer($this->factory->customerTitle()), $module, 'Someone else');

        $crawler = $this->client->request('GET', '/account/payment-methods');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="payment-method"]'));
        self::assertStringContainsString('You have no saved payment method yet', $crawler->filter('[data-testid="payment-methods-empty"]')->text());
    }

    public function testADeletedMethodIsForgottenByTheProviderAndDropped(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $keep = $this->methodOf($customer, $module, 'Keep me', null, true);
        $drop = $this->methodOf($customer, $module, 'Drop me');

        $this->client->request('POST', $this->url('account_payment_method_delete', ['methodId' => $drop->getId()]), ['_token' => $this->token()]);

        self::assertResponseRedirects('/account/payment-methods?delete_success=1');
        self::assertSame([(int) $drop->getId()], StoredMethodsPaymentModule::$forgottenMethodIds);
        self::assertFalse($this->stillStored($drop));
        self::assertTrue($this->stillStored($keep));

        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Your payment method has been deleted', $crawler->text());
    }

    public function testAMethodTheProviderRefusesToForgetIsKeptAndTheCustomerIsTold(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $method = $this->methodOf($customer, $module, 'Stuck with it');
        StoredMethodsPaymentModule::$providerRefusesToForget = true;
        StoredMethodsPaymentModule::$refusalReason = 'No such payment_method: pm_secret';

        $this->client->request('POST', $this->url('account_payment_method_delete', ['methodId' => $method->getId()]), ['_token' => $this->token()]);

        self::assertResponseRedirects('/account/payment-methods?refused=1');
        self::assertTrue($this->stillStored($method), 'The shop keeps what the provider keeps.');

        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('refused to delete this payment method', $crawler->text());
        self::assertStringNotContainsString('pm_secret', (string) $this->client->getResponse()->getContent(), 'What the provider said is never shown.');
        self::assertCount(1, $crawler->filter('[data-testid="payment-method"]'));
    }

    public function testAMethodOfAnotherCustomerCannotBeDeletedNorTheProviderAsked(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $this->methodOf($this->signedInCustomer(), $module, 'Mine');
        $theirs = $this->methodOf($this->factory->customer($this->factory->customerTitle()), $module, 'Theirs');
        $token = $this->token();

        $this->client->request('POST', $this->url('account_payment_method_delete', ['methodId' => $theirs->getId()]), ['_token' => $token]);

        self::assertResponseRedirects('/account/payment-methods?error=1', null, 'A stranger\'s method answers like a missing one.');
        self::assertTrue($this->stillStored($theirs));
        self::assertSame([], StoredMethodsPaymentModule::$forgottenMethodIds, 'The provider was not asked about a stranger\'s method.');
    }

    public function testAMissingMethodAnswersLikeAnotherCustomersOne(): void
    {
        $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $this->signedInCustomer();
        $token = $this->token();

        $this->client->request('POST', $this->url('account_payment_method_delete', ['methodId' => 987654321]), ['_token' => $token]);

        self::assertResponseRedirects('/account/payment-methods?error=1', null, 'A double click on a deletion lands on the list, not on an error page.');
        self::assertSame([], StoredMethodsPaymentModule::$forgottenMethodIds);

        $this->client->request('POST', $this->url('account_payment_method_default', ['methodId' => 987654321]), ['_token' => $token]);

        self::assertResponseRedirects('/account/payment-methods?error=1');
    }

    public function testADefaultIsChosenAndTheFormerOneIsLeft(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $former = $this->methodOf($customer, $module, 'Former default', null, true);
        $chosen = $this->methodOf($customer, $module, 'New default');

        $this->client->request('POST', $this->url('account_payment_method_default', ['methodId' => $chosen->getId()]), ['_token' => $this->token()]);

        self::assertResponseRedirects('/account/payment-methods?default_success=1');
        self::assertTrue($this->reread($chosen)->getIsDefault());
        self::assertFalse($this->reread($former)->getIsDefault(), 'A customer has one default method.');

        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Your default payment method has been updated', $crawler->text());
        self::assertStringContainsString('New default', $crawler->filter('[data-testid="payment-method"]')->first()->text(), 'The default comes first.');
    }

    public function testTheDefaultOfAnotherCustomerCannotBeChanged(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $this->methodOf($this->signedInCustomer(), $module, 'Mine', null, true);
        $stranger = $this->factory->customer($this->factory->customerTitle());
        $theirDefault = $this->methodOf($stranger, $module, 'Their default', null, true);
        $theirOther = $this->methodOf($stranger, $module, 'Their other');
        $token = $this->token();

        $this->client->request('POST', $this->url('account_payment_method_default', ['methodId' => $theirOther->getId()]), ['_token' => $token]);

        self::assertResponseRedirects('/account/payment-methods?error=1');
        self::assertTrue($this->reread($theirDefault)->getIsDefault());
        self::assertFalse($this->reread($theirOther)->getIsDefault());
    }

    public function testAnExpiredMethodIsNotOfferedAsTheDefaultNorAcceptedAsOne(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $current = $this->methodOf($customer, $module, 'Current default', null, true);
        $expired = $this->methodOf($customer, $module, 'Old card', '2020-01-31');

        $page = $this->client->request('GET', '/account/payment-methods');

        self::assertCount(0, $page->filter('[data-modal="confirmDefaultPaymentMethod"]'), 'A card that expired cannot be chosen.');
        self::assertCount(2, $page->filter('[data-modal="confirmDeletePaymentMethod"]'));

        $this->client->request('POST', $this->url('account_payment_method_default', ['methodId' => $expired->getId()]), ['_token' => $this->token()]);

        self::assertResponseRedirects('/account/payment-methods?error=1');
        self::assertFalse($this->reread($expired)->getIsDefault());
        self::assertTrue($this->reread($current)->getIsDefault());
    }

    public function testAMethodOfAModuleThatCannotPayAnyMoreIsNotAcceptedAsTheDefault(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $current = $this->methodOf($customer, $module, 'Current default', null, true);
        $other = $this->methodOf($customer, $module, 'Other');
        $token = $this->token();

        StoredMethodsPaymentModule::$offersStoredMethods = false;
        $this->client->request('POST', $this->url('account_payment_method_default', ['methodId' => $other->getId()]), ['_token' => $token]);
        self::assertResponseRedirects('/account/payment-methods?error=1', null, 'The module no longer offers stored methods.');

        StoredMethodsPaymentModule::$offersStoredMethods = true;
        $module->setActivate(0)->save($this->getPropelConnection());
        $this->client->request('POST', $this->url('account_payment_method_default', ['methodId' => $other->getId()]), ['_token' => $token]);
        self::assertResponseRedirects('/account/payment-methods?error=1', null, 'The module is deactivated.');

        self::assertFalse($this->reread($other)->getIsDefault());
        self::assertTrue($this->reread($current)->getIsDefault());
    }

    public function testAProviderFailureThatQuotesASecretIsNeverShown(): void
    {
        $module = $this->installTheThrowingModule();
        $method = $this->methodOf($this->signedInCustomer(), $module, 'Stuck with it');
        $token = $this->token();

        $this->client->request('POST', $this->url('account_payment_method_delete', ['methodId' => $method->getId()]), ['_token' => $token]);

        self::assertResponseRedirects('/account/payment-methods?refused=1');
        self::assertStringNotContainsString('pm_secret', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->stillStored($method), 'The provider did not forget it: the shop keeps it.');

        $crawler = $this->client->followRedirect();

        self::assertStringNotContainsString('pm_secret', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('[data-testid="payment-method"]'));
    }

    public function testAFailureAfterTheDeletionThatQuotesASecretEndsOnTheListWithAnError(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $this->methodOf($customer, $module, 'Current default', null, true);
        $other = $this->methodOf($customer, $module, 'Other');
        $token = $this->token();
        $failing = static function (): never {
            throw new \RuntimeException('pm_secret');
        };
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        $dispatcher->addListener(TheliaEvents::CUSTOMER_PAYMENT_METHOD_DELETE, $failing);

        try {
            $this->client->request('POST', $this->url('account_payment_method_delete', ['methodId' => $other->getId()]), ['_token' => $token]);
        } finally {
            $dispatcher->removeListener(TheliaEvents::CUSTOMER_PAYMENT_METHOD_DELETE, $failing);
        }

        self::assertResponseRedirects('/account/payment-methods?error=1');
        self::assertStringNotContainsString('pm_secret', (string) $this->client->getResponse()->getContent());
    }

    public function testTheButtonsOfACardNameTheCardAndTheDefaultOneIsPressed(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $this->methodOf($customer, $module, 'Visa ending 4242', null, true);
        $this->methodOf($customer, $module, 'Mastercard ending 1111');

        $cards = $this->client->request('GET', '/account/payment-methods')->filter('[data-testid="payment-method"]');

        self::assertCount(2, $cards);

        foreach ($cards as $card) {
            $card = new Crawler($card);
            $titleId = (string) $card->filter('.AddressCard-title')->attr('id');
            self::assertNotSame('', $titleId);
            self::assertStringContainsString($titleId, (string) $card->filter('[data-modal="confirmDeletePaymentMethod"]')->attr('aria-describedby'));
            self::assertSame($titleId, $card->filter('.Favorite')->attr('aria-describedby'));
        }

        self::assertSame('true', $cards->eq(0)->filter('.Favorite')->attr('aria-pressed'));
        self::assertSame('false', $cards->eq(1)->filter('.Favorite')->attr('aria-pressed'));
        self::assertSame('true', $cards->eq(0)->filter('.Favorite')->attr('aria-disabled'), 'The default method has no action: it says so.');
        self::assertNull($cards->eq(1)->filter('.Favorite')->attr('aria-disabled'));
        self::assertNotSame($cards->eq(0)->filter('.AddressCard-title')->attr('id'), $cards->eq(1)->filter('.AddressCard-title')->attr('id'));
    }

    public function testBothWritesRefuseAMissingOrForgedToken(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $first = $this->methodOf($customer, $module, 'First', null, true);
        $second = $this->methodOf($customer, $module, 'Second');

        $this->client->request('POST', $this->url('account_payment_method_delete', ['methodId' => $second->getId()]), ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $this->url('account_payment_method_default', ['methodId' => $second->getId()]));
        self::assertResponseStatusCodeSame(403);

        self::assertTrue($this->stillStored($second));
        self::assertFalse($this->reread($second)->getIsDefault());
        self::assertTrue($this->reread($first)->getIsDefault());
        self::assertSame([], StoredMethodsPaymentModule::$forgottenMethodIds);
    }

    public function testNothingChangesByAGetRequest(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $method = $this->methodOf($this->signedInCustomer(), $module, 'Mine');

        $this->client->request('GET', $this->url('account_payment_method_delete', ['methodId' => $method->getId()]));

        self::assertResponseStatusCodeSame(405);
        self::assertTrue($this->stillStored($method));
    }

    public function testTheConfirmationDialogsPostToTheMethodsOwnUrlWithAToken(): void
    {
        $module = $this->installTheStoredMethodsPaymentModule(static::getContainer(), $this->getPropelConnection());
        $customer = $this->signedInCustomer();
        $method = $this->methodOf($customer, $module, 'Mine');
        $this->methodOf($customer, $module, 'Other', null, true);

        $crawler = $this->client->request('GET', '/account/payment-methods');

        $delete = $crawler->filter('[data-modal="confirmDeletePaymentMethod"]')->reduce(
            static fn (Crawler $trigger): bool => str_contains((string) $trigger->attr('data-confirm'), '/'.$method->getId().'/'),
        );
        self::assertCount(1, $delete);
        self::assertSame('/account/payment-methods/'.$method->getId().'/delete', $delete->attr('data-confirm'));
        self::assertNotSame('', $crawler->filter('#confirmDeletePaymentMethod input[name="_token"]')->attr('value'));
        self::assertNotSame('', $crawler->filter('#confirmDefaultPaymentMethod input[name="_token"]')->attr('value'));
        self::assertSame('/account/payment-methods/'.$method->getId().'/default', $crawler->filter('[data-modal="confirmDefaultPaymentMethod"]')->attr('data-confirm'));
        self::assertCount(1, $crawler->filter('[data-modal="confirmDefaultPaymentMethod"]'), 'The default method has nothing to confirm.');
    }

    private function installTheThrowingModule(): Module
    {
        $connection = $this->getPropelConnection();
        $code = ThrowingStoredMethodsPaymentModule::getModuleCode();
        $module = ModuleQuery::create()->findOneByCode($code, $connection);

        if (!$module instanceof Module) {
            $module = (new Module())
                ->setCode($code)
                ->setFullNamespace(ThrowingStoredMethodsPaymentModule::class)
                ->setVersion('1.0.0')
                ->setType(BaseModule::PAYMENT_MODULE_TYPE)
                ->setCategory('payment')
                ->setActivate(BaseModule::IS_ACTIVATED);
            $module->save($connection);
        }

        $instance = new ThrowingStoredMethodsPaymentModule();
        $instance->setContainer(static::getContainer());
        static::getContainer()->set('module.'.$code, $instance);

        return $module;
    }

    private function signedInCustomer(): Customer
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $this->injector->setCustomer($customer);

        return $customer;
    }

    private function methodOf(Customer $customer, Module $module, string $label, ?string $expiresAt = null, bool $default = false): CustomerPaymentMethod
    {
        return $this->storedMethodRow($customer, $module, $label, $expiresAt, $default, $this->getPropelConnection());
    }

    private function stillStored(CustomerPaymentMethod $method): bool
    {
        CustomerPaymentMethodTableMap::clearInstancePool();

        return CustomerPaymentMethodQuery::create()->filterById($method->getId())->exists($this->getPropelConnection());
    }

    private function reread(CustomerPaymentMethod $method): CustomerPaymentMethod
    {
        CustomerPaymentMethodTableMap::clearInstancePool();

        return CustomerPaymentMethodQuery::create()->findPk($method->getId(), $this->getPropelConnection()) ?? self::fail('The method went away.');
    }

    /**
     * The token the page gives the customer, read from the confirmation dialog of the page.
     */
    private function token(): string
    {
        $crawler = $this->client->request('GET', '/account/payment-methods');

        return (string) $crawler->filter('#confirmDeletePaymentMethod input[name="_token"]')->attr('value');
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function url(string $route, array $parameters = []): string
    {
        return $this->getService(RouterInterface::class)->generate($route, $parameters);
    }
}
