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

namespace FlexyBundle\DTO;

/**
 * What the instant payment means of a cart (gift card, credit note, wallet) pay of it, as
 * the payment step shows it.
 *
 * A read of the core's own composition, never a second computation of it: the amounts
 * are the ones the order would be written with, and a composition the core would refuse
 * at the placement comes back as a refusal here, with the code the core gave it.
 */
final readonly class PaymentPartsView
{
    /**
     * @param list<array{moduleId: int, title: string, amount: string}> $parts           every instant means of the shop, in settlement order; the amount is "0" for one that applies nothing
     * @param string|null                                               $balance         what the payment module still has to charge, a decimal string; null when no composition could be made
     * @param int|null                                                  $primaryModuleId the means that carries the order's payment module when the parts cover it whole
     * @param string|null                                               $refusal         a PaymentCompositionRefusedException reason, null when the composition holds
     */
    public function __construct(
        public array $parts,
        public bool $active,
        public ?string $balance,
        public bool $coversTheOrder,
        public ?int $primaryModuleId,
        public ?string $refusal,
    ) {
    }
}
