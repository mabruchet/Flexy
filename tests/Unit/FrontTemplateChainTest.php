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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Whoever reads a file of the theme walks this chain: a template listed twice would have its
 * stories and icons listed twice, and this template missing from the end would leave a lookup
 * with nowhere to land.
 */
final class FrontTemplateChainTest extends TestCase
{
    private const FRONT_OFFICE = 'frontOffice';
    private const PARENT_TEMPLATE = 'flexy';

    private string $childTemplate;
    private string $childTemplateDirectory;
    private string $parentTemplateDirectory;

    /** @var list<string> */
    private array $extraTemplateDirectories = [];

    /** @var list<string> */
    private array $links = [];

    protected function setUp(): void
    {
        $this->childTemplate = uniqid('flexy-child-', false);
        $this->childTemplateDirectory = THELIA_TEMPLATE_DIR.self::FRONT_OFFICE.DS.$this->childTemplate;
        $this->parentTemplateDirectory = THELIA_TEMPLATE_DIR.self::FRONT_OFFICE.DS.self::PARENT_TEMPLATE;

        $this->dumpTemplateDescriptor($this->childTemplateDirectory, self::PARENT_TEMPLATE);
    }

    protected function tearDown(): void
    {
        // Filesystem::remove() unlinks a symlink without following it: the template it points
        // to stays in place.
        (new Filesystem())->remove([...$this->links, $this->childTemplateDirectory, ...$this->extraTemplateDirectories]);
    }

    public function testTheChainOfAChildIsTheChildThenThisTemplate(): void
    {
        self::assertSame(
            [$this->childTemplateDirectory, $this->parentTemplateDirectory],
            (new FrontTemplateChain($this->childTemplate))->directories(),
        );
    }

    public function testTheNearestTemplateThatShipsAFileAnswers(): void
    {
        (new Filesystem())->dumpFile($this->childTemplateDirectory.'/assets/styles/variables.css', ':root {}');

        $chain = new FrontTemplateChain($this->childTemplate);

        self::assertSame($this->childTemplateDirectory, $chain->nearest('assets/styles/variables.css', is_file(...)));
        self::assertSame($this->parentTemplateDirectory, $chain->nearest('importmap.php', is_file(...)));
        self::assertNull($chain->nearest('nothing-ships-this.txt', is_file(...)));
    }

    public function testWithoutAnActiveTemplateTheChainIsThisTemplateAlone(): void
    {
        self::assertSame([FrontTemplateChain::ownDirectory()], (new FrontTemplateChain(''))->directories());
    }

    public function testATemplateThatInheritsFromNothingIsClosedByThisTemplate(): void
    {
        $standaloneTemplate = uniqid('standalone-', false);
        $standaloneDirectory = THELIA_TEMPLATE_DIR.self::FRONT_OFFICE.DS.$standaloneTemplate;
        $this->extraTemplateDirectories[] = $standaloneDirectory;
        $this->dumpTemplateDescriptor($standaloneDirectory, null);

        $chain = new FrontTemplateChain($standaloneTemplate);

        self::assertSame([$standaloneDirectory], $chain->inherited());
        self::assertSame([$standaloneDirectory, FrontTemplateChain::ownDirectory()], $chain->directories());
        self::assertNull($chain->nearestInherited('importmap.php', is_file(...)));
        self::assertSame(FrontTemplateChain::ownDirectory(), $chain->nearest('importmap.php', is_file(...)));
    }

    public function testThisTemplateIsRecognisedThroughASymlink(): void
    {
        $link = THELIA_TEMPLATE_DIR.self::FRONT_OFFICE.DS.uniqid('link-', false);
        $this->links[] = $link;

        self::assertTrue(symlink(FrontTemplateChain::ownDirectory(), $link));

        self::assertTrue(FrontTemplateChain::isOwn($link));
        self::assertCount(
            1,
            (new FrontTemplateChain(basename($link)))->directories(),
            'This template, reached through a symlink, would close its own chain a second time.',
        );
    }

    private function dumpTemplateDescriptor(string $templateDirectory, ?string $parent): void
    {
        $parentElement = null === $parent ? '' : '<parent>'.$parent.'</parent>';

        (new Filesystem())->dumpFile(
            $templateDirectory.DS.'template.xml',
            <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                <template xmlns="http://thelia.net/schema/dic/template">
                    <descriptive locale="en">
                        <title>A template of the chain</title>
                    </descriptive>
                    {$parentElement}
                    <languages>
                        <language>en_US</language>
                    </languages>
                    <version>1.0.0</version>
                    <stability>prod</stability>
                </template>
                XML,
        );
    }
}
