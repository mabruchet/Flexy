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

namespace FlexyBundle\Toolkit;

use FlexyBundle\Template\FrontTemplateChain;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The component stories of the whole template chain, by category. A story the active template
 * ships under the path of one of its parent's replaces it: same slug, same status key, the
 * nearest file.
 */
final readonly class StoryFinder
{
    private const string STORY_FILE = 'toolkit.html.twig';

    /** Categories that close the sidebar whatever their name, in this order. */
    private const array TRAILING_CATEGORIES = ['Forms', 'Layouts'];

    public function __construct(
        private FrontTemplateChain $templateChain,
    ) {
    }

    /**
     * @return array<string, list<array{twigPath: string, path: string, name: string, slug: string, status: string|null}>>
     */
    public function groupedComponents(): array
    {
        $grouped = [];

        foreach ($this->nearestStories() as $relativePathname => $file) {
            $relativePath = $file->getRelativePath();
            $status = ComponentStatus::of($relativePath);

            if (ComponentStatus::HIDDEN === $status) {
                continue;
            }

            $parts = explode('/', $relativePath);
            $category = $parts[0];

            $grouped[$category][] = [
                'twigPath' => '@Flexy/' . $relativePathname,
                'path' => $file->getPathname(),
                'name' => \count($parts) > 1 ? implode(' / ', \array_slice($parts, 1)) : $category,
                'slug' => strtolower(implode('-', $parts)),
                'status' => $status,
            ];
        }

        // The order is set here rather than read off the walk: the walk goes template by
        // template, so a story a child adds would otherwise land after every story of its parent.
        ksort($grouped, \SORT_STRING);

        foreach ($grouped as &$stories) {
            usort($stories, static fn (array $left, array $right): int => strcmp($left['slug'], $right['slug']));
        }
        unset($stories);

        foreach (self::TRAILING_CATEGORIES as $category) {
            if (isset($grouped[$category])) {
                $trailing = $grouped[$category];
                unset($grouped[$category]);
                $grouped[$category] = $trailing;
            }
        }

        return $grouped;
    }

    /**
     * One Finder per template, walked in the order of the chain: the first file seen for a
     * path is the nearest one. A single Finder over every directory would not do - sorting it
     * sorts the real paths of all of them together, and which template wins a shared path
     * would depend on how the template directories are named.
     *
     * @return array<string, SplFileInfo>
     */
    private function nearestStories(): array
    {
        $stories = [];

        foreach ($this->templateChain->directories() as $templateDirectory) {
            $componentDirectory = $templateDirectory . '/components';

            if (!is_dir($componentDirectory)) {
                continue;
            }

            $finder = (new Finder())->files()->name(self::STORY_FILE)->in($componentDirectory);

            foreach ($finder as $file) {
                $stories[$file->getRelativePathname()] ??= $file;
            }
        }

        return $stories;
    }
}
