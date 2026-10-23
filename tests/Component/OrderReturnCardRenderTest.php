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

namespace FlexyBundle\Tests\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * The card of a return, as the order page and the list of every return both draw it.
 */
final class OrderReturnCardRenderTest extends KernelTestCase
{
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        // The URL helpers of the Twig engine read the request in progress.
        $this->requestStack = self::getContainer()->get('request_stack');
        $this->requestStack->push(Request::create('/account/orders'));
    }

    protected function tearDown(): void
    {
        $this->requestStack->pop();

        parent::tearDown();
    }

    public function testTheCardLinksTheReturnAndShowsItsStatus(): void
    {
        $html = $this->render($this->orderReturn());

        self::assertStringContainsString('href="/account/return/12"', $html);
        self::assertStringContainsString('Return RET000000000012', $html);
        self::assertStringContainsString('Requested', $html);
    }

    public function testTheDateIsIsolatedFromTheDirectionOfThePage(): void
    {
        $html = $this->render($this->orderReturn());

        self::assertMatchesRegularExpression('#<span class="paragraph-3"><bdi>[^<]+</bdi></span>#', $html);
    }

    public function testTheOrderIsLinkedOnlyWhenAskedFor(): void
    {
        $return = $this->orderReturn() + ['orderId' => 7, 'orderRef' => 'ORD000000000007'];

        self::assertStringNotContainsString('/account/order/7', $this->render($return));

        $html = $this->render($return, ['withOrder' => true]);

        self::assertStringContainsString('href="/account/order/7"', $html);
        self::assertStringContainsString('ORD000000000007', $html);
    }

    /**
     * A reference reaches the text slot of the link raw and leaves it escaped once.
     */
    public function testAReferenceIsEscapedOnceAndOnlyOnce(): void
    {
        $html = $this->render(['ref' => 'A&B<i>'] + $this->orderReturn());

        self::assertStringContainsString('Return A&amp;B&lt;i&gt;', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertStringNotContainsString('<i>', $html);
    }

    public function testAStatusWithoutATitleIsShownByItsCode(): void
    {
        $html = $this->render(['orderReturnStatus' => ['code' => 'accepted']] + $this->orderReturn());

        self::assertStringContainsString('accepted', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderReturn(): array
    {
        return [
            'id' => 12,
            'ref' => 'RET000000000012',
            'createdAt' => '2026-09-12',
            'orderReturnStatus' => ['code' => 'requested', 'i18ns' => ['title' => 'Requested']],
        ];
    }

    /**
     * @param array<string, mixed> $orderReturn
     * @param array<string, mixed> $props
     */
    private function render(array $orderReturn, array $props = []): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Organisms:OrderReturnCard:Base', ['orderReturn' => $orderReturn] + $props);
    }
}
