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

namespace FlexyBundle\Twig;

use FlexyBundle\Template\FrontTemplateChain;
use Symfony\Component\Finder\Finder;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Inventories what the theme ships, for the toolkit boards. Both functions group by
 * subdirectory, files at the root landing under an empty key.
 */
class ThemeAssetsExtension extends AbstractExtension
{
    public function __construct(
        private readonly FrontTemplateChain $templateChain,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('theme_icons', [$this, 'themeIcons']),
            new TwigFunction('theme_images', [$this, 'themeImages']),
        ];
    }

    /**
     * Icon names in the form ux_icon() takes: `name` at the root, `subdir:name` below it.
     * Reads the icon directories of the whole template chain, not the bundle's configured icon_dir.
     *
     * @return array<string, list<string>>
     */
    public function themeIcons(): array
    {
        return $this->inventory(
            'icons',
            '*.svg',
            static fn (string $namespace, string $relativePathname): string => '' === $namespace
                ? basename($relativePathname, '.svg')
                : $namespace . ':' . basename($relativePathname, '.svg'),
        );
    }

    /**
     * Image paths relative to the image directory, ready for asset().
     *
     * @return array<string, list<string>>
     */
    public function themeImages(): array
    {
        return $this->inventory(
            'images',
            null,
            static fn (string $namespace, string $relativePathname): string => $relativePathname,
        );
    }

    /**
     * @param callable(string, string): string $format
     *
     * @return array<string, list<string>>
     */
    private function inventory(string $directory, ?string $name, callable $format): array
    {
        $grouped = [];
        $seen = [];

        // Every template of the chain, nearest first: a file a child ships under the name of
        // one of its parent's replaces it, and the parent's other files stay listed.
        foreach ($this->templateChain->directories() as $templateDirectory) {
            $root = $templateDirectory . '/assets/' . $directory;

            if (!is_dir($root)) {
                continue;
            }

            $finder = (new Finder())->files()->in($root)->sortByName();

            if (null !== $name) {
                $finder->name($name);
            }

            foreach ($finder as $file) {
                $relativePathname = str_replace('\\', '/', $file->getRelativePathname());

                if (isset($seen[$relativePathname])) {
                    continue;
                }

                $seen[$relativePathname] = true;
                $namespace = str_replace('\\', '/', $file->getRelativePath());
                $grouped[$namespace][] = $format($namespace, $relativePathname);
            }
        }

        foreach ($grouped as &$names) {
            sort($names);
        }

        ksort($grouped);

        return $grouped;
    }
}
