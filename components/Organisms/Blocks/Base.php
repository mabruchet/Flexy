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

namespace FlexyBundle\Components\Organisms\Blocks;

use FlexyBundle\Module\ActiveModules;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Core\Content\BlockRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;

#[AsTwigComponent]
class Base
{
    public ?string $id = null;
    public ?string $slug = null;
    public ?string $itemType = null;
    public ?string $itemId = null;
    public int $visible = 1;

    public array $blocks = [];

    public function __construct(
        private readonly BlockRendererInterface $blockRenderer,
        private readonly RequestStack $requestStack,
        private readonly ActiveModules $activeModules,
    ) {
    }

    public function mount(?string $id = null, ?string $slug = null, ?string $itemType = null, ?string $itemId = null, int $visible = 1): void
    {
        $this->id = $id;
        $this->slug = $slug;
        $this->itemType = $itemType;
        $this->itemId = $itemId;
        $this->visible = $visible;

        // The blocks live in the tables of the TheliaBlocks module: a shop that keeps it inactive
        // has neither the tables nor the generated models, and the page has to render without.
        // ModuleQuery::getActivated() is cached for the request: the guard costs one query per page,
        // and spares the renderer a call that would find nothing.
        if (!$this->activeModules->has('TheliaBlocks')) {
            return;
        }

        $session = $this->requestStack->getCurrentRequest()?->getSession();
        $locale = $session instanceof Session ? $session->getLang()?->getLocale() : null;

        $this->blocks = $this->blockRenderer->findAndRenderBlocks([
            'id' => $this->id,
            'slug' => $this->slug,
            'item_type' => $this->itemType,
            'item_id' => $this->itemId,
            'visible' => $this->visible,
            'locale' => $locale,
        ]);
    }
}
