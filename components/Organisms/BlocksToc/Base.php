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

namespace FlexyBundle\Components\Organisms\BlocksToc;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\ModuleQuery;
use TheliaBlocks\Model\BlockGroupQuery;

#[AsTwigComponent]
class Base
{
    public ?string $itemType = null;
    public ?string $itemId = null;

    /** When set, only blockTitle blocks with a level <= this value are included. Neutral titles (no level) are excluded when a max level is set. */
    public ?int $maxLevel = null;

    public array $titles = [];

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function mount(?string $itemType = null, ?string $itemId = null, ?int $maxLevel = null): void
    {
        $this->itemType = $itemType;
        $this->itemId = $itemId;
        $this->maxLevel = $maxLevel;

        // The blocks live in the tables of the TheliaBlocks module: a shop that keeps it inactive
        // has neither the tables nor the generated models, and the page has to render without.
        if (!self::theliaBlocksIsActive()) {
            return;
        }

        $session = $this->requestStack->getCurrentRequest()?->getSession();
        $locale = $session instanceof Session ? $session->getLang()?->getLocale() : null;

        $search = BlockGroupQuery::create()
            ->filterByVisible(1)
            ->useItemBlockGroupQuery()
                ->filterByItemType($this->itemType)
                ->filterByItemId((int) $this->itemId)
            ->endUse();

        foreach ($search->find() as $blockGroup) {
            if ($locale !== null) {
                $blockGroup->setLocale($locale);
            }

            $jsonContent = $blockGroup->getJsonContent();

            if ($jsonContent === null) {
                continue;
            }

            try {
                $blocks = json_decode($jsonContent, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!\is_array($blocks)) {
                continue;
            }

            foreach ($blocks as $block) {
                if (
                    !isset($block['id'], $block['type']['id'], $block['data']['text'])
                    || $block['type']['id'] !== 'blockTitle'
                    || $block['data']['text'] === ''
                ) {
                    continue;
                }

                $level = isset($block['data']['level']) ? (int) $block['data']['level'] : null;

                if ($this->maxLevel !== null && ($level === null || $level > $this->maxLevel)) {
                    continue;
                }

                $this->titles[] = [
                    'id' => $block['id'],
                    'text' => strip_tags((string) $block['data']['text']),
                ];
            }
        }
    }

    private static function theliaBlocksIsActive(): bool
    {
        foreach (ModuleQuery::getActivated() as $module) {
            if ('TheliaBlocks' === $module->getCode()) {
                return true;
            }
        }

        return false;
    }
}
