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

namespace FlexyBundle\Components\Organisms\PaymentCard;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Thelia\Domain\Cart\CartFacade;

#[AsTwigComponent]
class Base
{
    public ?array $module = null;
    public bool $checked = false;

    /**
     * The options of this module the buyer has picked, as the session holds them.
     *
     * @var list<array{group: string, values: list<string>}>
     */
    public array $optionChoices = [];

    /**
     * The stored payment method written on the cart, null when the buyer pays with a new one.
     */
    public ?int $storedMethodId = null;

    public function __construct(private readonly CartFacade $cartFacade)
    {
    }

    #[PostMount]
    public function markCheckedFromCart(): void
    {
        $this->checked = null !== $this->module
            && $this->module['id'] === $this->cartFacade->getPaymentModuleId();
    }

    /**
     * Whether an option of the core's stored-methods group is the one the cart pays with:
     * the method written on it, or, for the option of a new method that names none, no
     * method at all.
     *
     * @param array<string, mixed> $option
     */
    public function isStoredOptionPicked(array $option): bool
    {
        $methodId = $option['customerPaymentMethodId'] ?? null;

        return null === $methodId ? null === $this->storedMethodId : (int) $methodId === $this->storedMethodId;
    }

    public function isOptionPicked(string $groupCode, string $optionCode): bool
    {
        foreach ($this->optionChoices as $choice) {
            if ($choice['group'] === $groupCode) {
                return \in_array($optionCode, $choice['values'], true);
            }
        }

        return false;
    }
}
