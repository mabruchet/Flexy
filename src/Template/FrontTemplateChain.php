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

namespace FlexyBundle\Template;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateService;

/**
 * The directory of the active front template, then the directories of the templates it
 * inherits from, nearest first, this template last. A child template ships only what it
 * overrides: whoever reads a file of the theme (a story, an icon, a stylesheet, a page to
 * extend) walks the chain and takes the nearest answer.
 *
 * The chain is read off the template.xml descriptors, so it can be built while the container
 * is compiled as well as at runtime.
 */
final class FrontTemplateChain
{
    /** @var list<string>|null */
    private ?array $directories = null;

    public function __construct(
        #[Autowire('%thelia_front_template%')]
        private readonly string $frontTemplate,
    ) {
    }

    /**
     * Nearest first. This template closes the chain even when the active template does not
     * inherit from it, so that a lookup always has somewhere to land.
     *
     * @return list<string>
     */
    public function directories(): array
    {
        if (null !== $this->directories) {
            return $this->directories;
        }

        $chain = '' === $this->frontTemplate
            ? []
            : TemplateService::getTemplateChainAbsolutePath(TemplateDefinition::FRONT_OFFICE_SUBDIR, $this->frontTemplate);

        $own = self::ownDirectory();

        if (!\in_array($own, array_map(self::realPath(...), $chain), true)) {
            $chain[] = $own;
        }

        return $this->directories = array_values(array_unique($chain));
    }

    /**
     * The nearest template of the chain that ships the given file or directory, or null.
     */
    public function nearest(string $relativePath, callable $exists): ?string
    {
        foreach ($this->directories() as $directory) {
            if ($exists($directory . '/' . ltrim($relativePath, '/'))) {
                return $directory;
            }
        }

        return null;
    }

    /**
     * Whether the directory is the one of this template, through any symlink.
     */
    public static function isOwn(string $directory): bool
    {
        return self::realPath($directory) === self::ownDirectory();
    }

    public static function ownDirectory(): string
    {
        return self::realPath(\dirname(__DIR__, 2));
    }

    private static function realPath(string $path): string
    {
        return realpath($path) ?: $path;
    }
}
