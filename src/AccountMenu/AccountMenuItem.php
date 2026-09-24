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

namespace FlexyBundle\AccountMenu;

/**
 * One entry of the customer account navigation, listed by ascending position. The
 * position has no default: an entry has to say where it goes, or it would land before
 * "My profile" by accident.
 *
 * Until 1.0 the menu was handed out as arrays; read-only array access keeps a child
 * theme reading `item['slug']` working during the transition. It is deprecated: read
 * the properties.
 *
 * @implements \ArrayAccess<string, int|string>
 */
final readonly class AccountMenuItem implements \ArrayAccess
{
    public function __construct(
        public string $slug,
        public string $text,
        public string $href,
        public int $position,
    ) {
    }

    public function offsetExists(mixed $offset): bool
    {
        return \in_array($offset, ['slug', 'text', 'href', 'position'], true);
    }

    public function offsetGet(mixed $offset): int|string
    {
        return match ($offset) {
            'slug' => $this->slug,
            'text' => $this->text,
            'href' => $this->href,
            'position' => $this->position,
            default => throw new \OutOfBoundsException(\sprintf('An account menu item has no %s.', var_export($offset, true))),
        };
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('An account menu item is read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('An account menu item is read-only.');
    }
}
