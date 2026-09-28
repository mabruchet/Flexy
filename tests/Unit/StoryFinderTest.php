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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\Template\FrontTemplateChain;
use FlexyBundle\Toolkit\StoryFinder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A child template overrides a story by shipping it under the same path as its parent: the
 * toolkit must then show the child's file, whatever the two templates are called.
 */
final class StoryFinderTest extends TestCase
{
    private const FRONT_OFFICE = 'frontOffice';
    private const PARENT_TEMPLATE = 'flexy';
    private const OVERRIDDEN_STORY = 'components/Molecules/Button/toolkit.html.twig';

    private string $childTemplate;
    private string $childTemplateLink;
    private string $childTemplateDirectory;

    protected function setUp(): void
    {
        // Named after this template, and created next to its real directory: a lookup that
        // decides between templates by sorting their real paths then picks the parent. Where
        // this template is itself a link into another directory (a development checkout), the
        // child is reached through a link of its own from the front-office directory.
        $this->childTemplate = uniqid('zz-child-', false);
        $this->childTemplateLink = THELIA_TEMPLATE_DIR.self::FRONT_OFFICE.DS.$this->childTemplate;
        $this->childTemplateDirectory = \dirname(FrontTemplateChain::ownDirectory()).DS.$this->childTemplate;

        $filesystem = new Filesystem();
        $filesystem->dumpFile(
            $this->childTemplateDirectory.DS.'template.xml',
            <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                <template xmlns="http://thelia.net/schema/dic/template">
                    <descriptive locale="en">
                        <title>A child of this template</title>
                    </descriptive>
                    <parent>flexy</parent>
                    <languages>
                        <language>en_US</language>
                    </languages>
                    <version>1.0.0</version>
                    <stability>prod</stability>
                </template>
                XML,
        );

        if (!is_dir($this->childTemplateLink)) {
            $filesystem->symlink($this->childTemplateDirectory, $this->childTemplateLink);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->childTemplateLink, $this->childTemplateDirectory]);
    }

    public function testTheStoryAChildShipsReplacesTheOneOfItsParent(): void
    {
        $this->childShips(self::OVERRIDDEN_STORY);

        $story = $this->storiesBySlug($this->childTemplate)['molecules-button'] ?? null;

        self::assertNotNull($story, 'The overridden story is no longer listed.');
        self::assertSame(realpath($this->childTemplateDirectory.DS.self::OVERRIDDEN_STORY), $story['path']);
        self::assertSame('@Flexy/Molecules/Button/toolkit.html.twig', $story['twigPath']);
    }

    public function testTheStoriesTheChildDoesNotOverrideStayThoseOfItsParent(): void
    {
        $this->childShips(self::OVERRIDDEN_STORY);

        $parentStories = $this->storiesBySlug(self::PARENT_TEMPLATE);
        $childStories = $this->storiesBySlug($this->childTemplate);

        self::assertSame(array_keys($parentStories), array_keys($childStories));

        unset($parentStories['molecules-button'], $childStories['molecules-button']);

        self::assertNotSame([], $childStories);
        self::assertSame($parentStories, $childStories);
    }

    public function testAChildThatShipsNoStoryChangesNothing(): void
    {
        self::assertSame(
            $this->finderFor(self::PARENT_TEMPLATE)->groupedComponents(),
            $this->finderFor($this->childTemplate)->groupedComponents(),
        );
    }

    public function testAStoryOnlyTheChildShipsTakesItsPlaceByName(): void
    {
        $this->childShips('components/Molecules/Card/toolkit.html.twig');

        $slugs = array_column($this->finderFor($this->childTemplate)->groupedComponents()['Molecules'] ?? [], 'slug');
        $sorted = $slugs;
        sort($sorted, \SORT_STRING);

        self::assertContains('molecules-card', $slugs);
        self::assertSame($sorted, $slugs);
    }

    public function testTheCategoriesTheSidebarClosesOnComeLast(): void
    {
        $categories = array_keys($this->finderFor(self::PARENT_TEMPLATE)->groupedComponents());

        self::assertSame(['Forms', 'Layouts'], \array_slice($categories, -2));
    }

    private function childShips(string $relativePath): void
    {
        (new Filesystem())->dumpFile($this->childTemplateDirectory.DS.$relativePath, '<div>child</div>');
    }

    /**
     * @return array<string, array{twigPath: string, path: string, name: string, slug: string, status: string|null}>
     */
    private function storiesBySlug(string $frontTemplate): array
    {
        $stories = [];

        foreach ($this->finderFor($frontTemplate)->groupedComponents() as $categoryStories) {
            foreach ($categoryStories as $story) {
                $stories[$story['slug']] = $story;
            }
        }

        return $stories;
    }

    private function finderFor(string $frontTemplate): StoryFinder
    {
        return new StoryFinder(new FrontTemplateChain($frontTemplate));
    }
}
