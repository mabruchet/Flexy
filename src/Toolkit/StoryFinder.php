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
 *
 * Where a story stands follows the same rule. A template of the chain may ship STATUS_FILE, a
 * plain list of story path => status: the nearest template that names a path answers for it,
 * and ComponentStatus::of() answers for the paths none of them names. A child template says
 * where its stories — and Flexy's — stand without editing Flexy.
 */
final readonly class StoryFinder
{
    /**
     * The hyphen is deliberate: a service resource over a `components/` directory (Flexy's own,
     * or a child's that ships component classes) skips a file whose name is no class name. As
     * `statuses.php` it would be autoloaded as a class, and the container would refuse to build.
     */
    public const string STATUS_FILE = 'components/Toolkit/story-statuses.php';

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
        $declared = $this->declaredStatuses();

        foreach ($this->nearestStories() as $relativePathname => $file) {
            $relativePath = $file->getRelativePath();
            $status = $declared[$relativePath] ?? ComponentStatus::of($relativePath);

            if (ComponentStatus::HIDDEN === $status) {
                continue;
            }

            $parts = explode('/', $relativePath);
            $category = $parts[0];

            // A dangling link has no real path: the story is dropped rather than read from ''.
            $path = $file->getRealPath();

            if (false === $path) {
                continue;
            }

            $grouped[$category][] = [
                'twigPath' => '@Flexy/' . $relativePathname,
                'path' => $path,
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
     * The status of a story or a toolkit page (`Toolkit/welcome`), keyed like ComponentStatus.
     */
    public function statusOf(string $path): ?string
    {
        return $this->declaredStatuses()[$path] ?? ComponentStatus::of($path);
    }

    /**
     * Walked nearest first, so the union keeps the answer of the nearest template that names a
     * path. Read on every call rather than kept: the class is readonly, the files are small and
     * opcached, and the toolkit is a developer page.
     *
     * @return array<string, string>
     */
    private function declaredStatuses(): array
    {
        $declared = [];

        foreach ($this->templateChain->directories() as $templateDirectory) {
            $file = $templateDirectory . '/' . self::STATUS_FILE;

            if (is_file($file)) {
                $declared += self::readStatusFile($file);
            }
        }

        return $declared;
    }

    /**
     * A typo in a registry would otherwise leave a story without a status, or with a badge no
     * style knows, and nobody would notice: the file is refused whole, naming the faulty entry.
     *
     * @return array<string, string>
     */
    private static function readStatusFile(string $file): array
    {
        // A static closure: the file sees nothing of this class, only what it declares itself.
        $statuses = (static fn (): mixed => require $file)();

        if (!\is_array($statuses)) {
            throw new \InvalidArgumentException(\sprintf('%s must return an array of story path => status, it returns %s.', $file, get_debug_type($statuses)));
        }

        foreach ($statuses as $path => $status) {
            if (!\is_string($path)) {
                throw new \InvalidArgumentException(\sprintf('%s: every status is keyed by its story path under components/ ("Molecules/Button"), found the key %d.', $file, $path));
            }

            if (!\in_array($status, ComponentStatus::ALL, true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown status %s for "%s" in %s: expected one of "%s".', json_encode($status), $path, $file, implode('", "', ComponentStatus::ALL)));
            }
        }

        return $statuses;
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
