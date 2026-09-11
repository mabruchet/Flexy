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

namespace FlexyBundle\Components\Molecules\CheckoutSteps;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The bar that says where in a walk of several screens the visitor stands.
 *
 * Used by the checkout, whose steps come from the shop's configuration, and by the
 * sign-up pages, whose two screens are the theme's own. Hence the two ways of naming the
 * step being shown: a checkout hands over the code of the step — it is the only thing it
 * knows about it, positions having stopped being fixed the moment a merchant could turn
 * a step off — and a page with a bar of its own hands over its position.
 */
#[AsTwigComponent]
class Base
{
    /**
     * Each step as `{code?, text, link?, translate?}`. `code` names the step for
     * {@see $current}; `translate` says whether `text` still has to go through the
     * catalogue, which a wording that came translated from the checkout configuration
     * does not.
     *
     * @var list<array<string, mixed>>
     */
    public array $steps = [];

    /** The code of the step being shown, or its position in {@see $steps} from 1. */
    public int|string|null $current = null;

    public bool $noCart = false;

    public function getCurrentPosition(): int
    {
        if (is_numeric($this->current)) {
            return max(1, (int) $this->current);
        }

        foreach ($this->steps as $position => $step) {
            if (($step['code'] ?? null) === $this->current) {
                return $position + 1;
            }
        }

        // A step the bar does not hold — the payment gateway, a failed payment, a page
        // that forgot to say — is shown standing on the first one rather than on none:
        // the bar is a landmark, and one that highlights nothing reads as broken.
        return 1;
    }
}
