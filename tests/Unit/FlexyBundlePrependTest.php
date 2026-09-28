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

use FlexyBundle\DependencyInjection\Compiler\TwigComponentDebugDirectoryPass;
use FlexyBundle\FlexyBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\UX\Icons\DependencyInjection\UXIconsExtension;
use Symfony\UX\Icons\Registry\LocalSvgIconRegistry;

/**
 * A shop may run a template that declares this one as its parent and ships almost nothing of
 * its own. Every path this bundle prepends then has to be looked up in the whole chain: the
 * entry stylesheet, the importmap and its vendor assets, the Stimulus controllers and the
 * icons all live in the parent, and pointing them at the active template alone leaves the
 * shop with a container that cannot even be compiled.
 */
final class FlexyBundlePrependTest extends TestCase
{
    private const FRONT_OFFICE = 'frontOffice';
    private const PARENT_TEMPLATE = 'flexy';
    private const EXTENSIONS_THE_BUNDLE_CONFIGURES = [
        'stimulus',
        'framework',
        'liip_imagine',
        'tales_from_a_dev_twig_extra_tailwind',
    ];

    private string $childTemplate;
    private string $childTemplateDirectory;
    private string $parentTemplateDirectory;

    /** @var list<string> */
    private array $extraTemplateDirectories = [];

    protected function setUp(): void
    {
        $this->childTemplate = uniqid('flexy-child-', false);
        $this->childTemplateDirectory = THELIA_TEMPLATE_DIR.self::FRONT_OFFICE.DS.$this->childTemplate;
        $this->parentTemplateDirectory = THELIA_TEMPLATE_DIR.self::FRONT_OFFICE.DS.self::PARENT_TEMPLATE;

        $this->dumpTemplateDescriptor($this->childTemplateDirectory, self::PARENT_TEMPLATE);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->childTemplateDirectory, ...$this->extraTemplateDirectories]);
    }

    public function testTheEntryStylesheetOfTheParentIsUsedWhenTheChildShipsNone(): void
    {
        $builder = $this->prependFor($this->childTemplate);

        self::assertSame(
            $this->parentTemplateDirectory.'/assets/styles/app.css',
            $this->configOf($builder, 'symfonycasts_tailwind')['input_css'],
        );
    }

    public function testTheImportmapAndTheVendorAssetsOfTheParentAreUsedWhenTheChildShipsNone(): void
    {
        $assetMapper = $this->configOf($this->prependFor($this->childTemplate), 'framework')['asset_mapper'];

        self::assertSame($this->parentTemplateDirectory.'/importmap.php', $assetMapper['importmap_path']);
        self::assertSame($this->parentTemplateDirectory.'/assets/vendor', $assetMapper['vendor_dir']);
    }

    public function testTheIconsOfTheParentAreUsedWhenTheChildShipsNone(): void
    {
        self::assertSame(
            $this->parentTemplateDirectory.'/assets/icons',
            $this->configOf($this->prependFor($this->childTemplate), 'ux_icons')['icon_dir'],
        );
    }

    /**
     * ux-icons applies its own configured attributes over the ones an SVG file carries
     * (precedence: file < configuration < invocation) and defaults that configuration to
     * `fill: currentColor`. Left at its default it repaints every icon the theme ships, so the
     * bundle has to blank it. Reading back the prepended array would not catch the key being
     * dropped: what decides the rendering is the configuration ux-icons ends up with once its
     * own definition tree has filled in every default, which is what is asserted here.
     */
    public function testNoAttributeIsAppliedOverTheOnesAnIconFileDeclares(): void
    {
        self::assertSame(
            [],
            $this->uxIconsConfigFor(self::PARENT_TEMPLATE)['default_icon_attributes'],
            'ux-icons would repaint the fill of every icon of the template.',
        );
    }

    /** The same, for a template that inherits its icons instead of shipping them. */
    public function testAChildInheritsBothTheIconsOfItsParentAndTheirAttributes(): void
    {
        $uxIcons = $this->uxIconsConfigFor($this->childTemplate);

        self::assertSame($this->parentTemplateDirectory.'/assets/icons', $uxIcons['icon_dir']);
        self::assertSame([], $uxIcons['default_icon_attributes']);
    }

    public function testTheStimulusControllersOfTheParentAreRegisteredForTheChild(): void
    {
        $stimulus = $this->configOf($this->prependFor($this->childTemplate), 'stimulus');

        self::assertContains($this->parentTemplateDirectory.'/assets/controllers', $stimulus['controller_paths']);
        self::assertContains($this->parentTemplateDirectory.'/components', $stimulus['controller_paths']);
        self::assertSame(
            $this->parentTemplateDirectory.'/assets/controllers.json',
            $stimulus['controllers_json'],
        );
    }

    public function testOnlyDirectoriesThatExistAreDeclaredToTheAssetMapper(): void
    {
        $assetMapper = $this->configOf($this->prependFor($this->childTemplate), 'framework')['asset_mapper'];

        foreach ($assetMapper['paths'] as $path) {
            self::assertDirectoryExists($path);
        }

        self::assertContains($this->parentTemplateDirectory.'/assets', $assetMapper['paths']);
    }

    public function testTheComponentsOfTheParentAnswerForTheChild(): void
    {
        $paths = $this->configOf($this->prependFor($this->childTemplate), 'twig')['paths'];

        self::assertSame('Flexy', $paths[$this->parentTemplateDirectory.'/components'] ?? null);
        self::assertSame('FlexyForm', $paths[$this->parentTemplateDirectory.'/form'] ?? null);
    }

    public function testEachTemplateOfTheChainIsRegisteredUnderItsOwnTwigNamespace(): void
    {
        $paths = $this->configOf($this->prependFor($this->childTemplate), 'twig')['paths'];

        self::assertSame('theme_flexy', $paths[$this->parentTemplateDirectory] ?? null);
        self::assertSame(FlexyBundle::templateNamespace($this->childTemplateDirectory), $paths[$this->childTemplateDirectory] ?? null);
    }

    public function testATemplateNamespaceOnlyCarriesCharactersATwigNameAccepts(): void
    {
        self::assertSame('theme_my_shop_2', FlexyBundle::templateNamespace('/templates/frontOffice/my-shop.2'));
    }

    public function testAnonymousComponentsAreLookedUpThroughTheComponentNamespace(): void
    {
        self::assertSame(
            '@Flexy',
            $this->configOf($this->prependFor($this->childTemplate), 'twig_component')['anonymous_template_directory'],
            'A filesystem path would be the nearest components/ alone: a child that ships one component would lose every anonymous component of its parent.',
        );
    }

    public function testTheIconDirectoryStaysTheOneOfThisTemplateWhenTheChildShipsIcons(): void
    {
        (new Filesystem())->dumpFile($this->childTemplateDirectory.'/assets/icons/cart.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        self::assertSame(
            $this->parentTemplateDirectory.'/assets/icons',
            $this->uxIconsConfigFor($this->childTemplate)['icon_dir'],
        );
    }

    public function testTheIconsOfTheChildAreRegisteredBeforeTheOnesOfThisTemplate(): void
    {
        (new Filesystem())->dumpFile($this->childTemplateDirectory.'/assets/icons/cart.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $builder = $this->loadFor($this->childTemplate);
        $definition = $builder->getDefinition('flexy.icon_registry.'.$this->childTemplate);

        self::assertSame($this->childTemplateDirectory.'/assets/icons', $definition->getArgument(1));

        $priority = $definition->getTag('ux_icons.registry')[0]['priority'] ?? null;

        self::assertIsInt($priority);
        self::assertGreaterThan(10, $priority, 'ux-icons registers its own local registry at 10; the child must answer before it.');
    }

    public function testAChildWithoutIconsRegistersNoIconRegistry(): void
    {
        self::assertFalse($this->loadFor($this->childTemplate)->hasDefinition('flexy.icon_registry.'.$this->childTemplate));
    }

    public function testTheTranslationCataloguesOfTheChildAreDeclaredAfterTheOnesOfThisTemplate(): void
    {
        (new Filesystem())->dumpFile($this->childTemplateDirectory.'/translations/messages.fr_FR.yaml', "Cart: Panier\n");

        $translator = $this->configOf($this->prependFor($this->childTemplate), 'framework')['translator'] ?? null;

        // The framework registers a bundle's translations/ on its own, before the configured
        // paths; only the child is declared, and a key it repeats replaces the one of this template.
        self::assertSame([$this->childTemplateDirectory.'/translations'], $translator['paths'] ?? null);
    }

    public function testNoTranslationPathIsDeclaredWhenTheChildShipsNoCatalogue(): void
    {
        $framework = $this->configOf($this->prependFor($this->childTemplate), 'framework');

        self::assertArrayNotHasKey('translator', $framework);
    }

    public function testATemplateThatInheritsFromNothingKeepsItsOwnDirectories(): void
    {
        $assetMapper = $this->configOf($this->prependFor(self::PARENT_TEMPLATE), 'framework')['asset_mapper'];

        self::assertSame($this->parentTemplateDirectory.'/importmap.php', $assetMapper['importmap_path']);
        self::assertNotContains($this->childTemplateDirectory.'/assets', $assetMapper['paths']);
    }

    public function testATemplateThatInheritsFromNothingReadsItsOwnIcons(): void
    {
        self::assertSame(
            $this->parentTemplateDirectory.'/assets/icons',
            $this->configOf($this->prependFor(self::PARENT_TEMPLATE), 'ux_icons')['icon_dir'],
        );
    }

    public function testATemplateThatInheritsFromNothingRegistersItsRootUnderItsNamespace(): void
    {
        $paths = $this->configOf($this->prependFor(self::PARENT_TEMPLATE), 'twig')['paths'];

        self::assertSame('theme_flexy', $paths[$this->parentTemplateDirectory] ?? null);
    }

    public function testATemplateThatInheritsFromNothingDeclaresNoTranslationPath(): void
    {
        // This template's translations/ is registered by the framework as a bundle's.
        self::assertArrayNotHasKey('translator', $this->configOf($this->prependFor(self::PARENT_TEMPLATE), 'framework'));
    }

    public function testThisTemplateRegistersNoIconRegistryForItself(): void
    {
        // Its icons are the configured icon_dir, read by the registry ux-icons declares itself.
        self::assertFalse($this->loadFor(self::PARENT_TEMPLATE)->hasDefinition('flexy.icon_registry.'.self::PARENT_TEMPLATE));
    }

    /**
     * A template that does not inherit from this one keeps its own Stimulus setup: the
     * controllers of this template are not registered for it, and its controllers.json is looked
     * up where it would ship one, not in this template.
     */
    public function testATemplateThatDoesNotInheritFromThisOneRegistersNoneOfItsControllers(): void
    {
        $standaloneTemplate = $this->createTemplate(uniqid('standalone-', false), null);

        $stimulus = $this->configOf($this->prependFor($standaloneTemplate), 'stimulus');

        // It ships no controller directory of its own, and none of this template is added.
        self::assertSame([], $stimulus['controller_paths']);
        self::assertStringNotContainsString(self::PARENT_TEMPLATE.'/assets/controllers.json', $stimulus['controllers_json']);
    }

    public function testTwoTemplatesOfTheChainCannotShareATwigNamespace(): void
    {
        // `collide-<id>` and `collide_<id>` both read `theme_collide_<id>`.
        $suffix = uniqid('', false);
        $this->createTemplate('collide_'.$suffix, self::PARENT_TEMPLATE);
        $child = $this->createTemplate('collide-'.$suffix, 'collide_'.$suffix);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('@theme_collide_'.$suffix);

        $this->prependFor($child);
    }

    /**
     * The registry is declared with internal classes and a private service id of ux-icons:
     * compiling it against the real extension is what catches a release that changes them.
     */
    public function testTheIconRegistryOfTheChildCompilesAgainstUxIcons(): void
    {
        (new Filesystem())->dumpFile($this->childTemplateDirectory.'/assets/icons/cart.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $builder = $this->loadFor($this->childTemplate);
        $registryId = 'flexy.icon_registry.'.$this->childTemplate;

        // The rest of the bundle's services need the Thelia kernel, which is not booted here.
        foreach (array_keys($builder->getDefinitions()) as $id) {
            if (str_starts_with($id, 'FlexyBundle\\')) {
                $builder->removeDefinition($id);
            }
        }

        $builder->setParameter('kernel.bundles', []);
        // ux-icons derives its cache pool from the framework's, which is not loaded here.
        $builder->register('cache.system', ArrayAdapter::class)->setAbstract(true);
        $builder->registerExtension(new UXIconsExtension());
        $builder->loadFromExtension('ux_icons', ['icon_dir' => $this->parentTemplateDirectory.'/assets/icons']);
        $builder->getDefinition($registryId)->setPublic(true);

        $builder->compile();

        self::assertInstanceOf(LocalSvgIconRegistry::class, $builder->get($registryId));
    }

    public function testDebugTwigComponentIsGivenTheComponentDirectoryOfTheParentWhenTheChildShipsNone(): void
    {
        self::assertSame(
            'frontOffice/'.self::PARENT_TEMPLATE.'/components',
            $this->debugCommandAnonymousDirectoryFor($this->childTemplate, '@Flexy'),
        );
    }

    public function testDebugTwigComponentIsGivenTheComponentDirectoryOfTheChildWhenItShipsOne(): void
    {
        (new Filesystem())->mkdir($this->childTemplateDirectory.'/components');

        self::assertSame(
            'frontOffice/'.$this->childTemplate.'/components',
            $this->debugCommandAnonymousDirectoryFor($this->childTemplate, '@Flexy'),
        );
    }

    public function testDebugTwigComponentKeepsAFilesystemPathTheProjectSets(): void
    {
        self::assertSame('components', $this->debugCommandAnonymousDirectoryFor($this->childTemplate, 'components'));
    }

    /**
     * The argument the debug command is built with once the container is compiled with the
     * passes this bundle adds, the command registered as ux-twig-component registers it.
     */
    private function debugCommandAnonymousDirectoryFor(string $frontTemplate, string $anonymousDirectory): mixed
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('thelia_front_template', $frontTemplate);
        $builder->setParameter('kernel.project_dir', \dirname(rtrim(THELIA_TEMPLATE_DIR, '/')));
        $builder->setParameter('twig.default_path', '%kernel.project_dir%/'.basename(rtrim(THELIA_TEMPLATE_DIR, '/')));
        $builder->register(TwigComponentDebugDirectoryPass::DEBUG_COMMAND, \stdClass::class)
            ->setPublic(true)
            ->setArguments(['%twig.default_path%', null, null, [], $anonymousDirectory]);

        (new FlexyBundle())->build($builder);
        $builder->compile();

        return $builder->getDefinition(TwigComponentDebugDirectoryPass::DEBUG_COMMAND)
            ->getArgument(TwigComponentDebugDirectoryPass::ANONYMOUS_DIRECTORY_ARGUMENT);
    }

    /**
     * A template of the front office, removed after the test.
     */
    private function createTemplate(string $name, ?string $parent): string
    {
        $directory = THELIA_TEMPLATE_DIR.self::FRONT_OFFICE.DS.$name;
        $this->extraTemplateDirectories[] = $directory;
        $this->dumpTemplateDescriptor($directory, $parent);

        return $name;
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

    /**
     * @return array<string, mixed>
     */
    private function configOf(ContainerBuilder $builder, string $extension): array
    {
        $configs = $builder->getExtensionConfig($extension);

        self::assertNotSame([], $configs, 'Nothing was prepended for "'.$extension.'".');

        return $configs[0];
    }

    /**
     * The configuration ux-icons really runs on: everything the bundle prepended for it, merged
     * and completed by the extension's own definition tree.
     *
     * @return array<string, mixed>
     */
    private function uxIconsConfigFor(string $frontTemplate): array
    {
        return (new Processor())->processConfiguration(
            new UXIconsExtension(),
            $this->prependFor($frontTemplate)->getExtensionConfig('ux_icons'),
        );
    }

    private function loadFor(string $frontTemplate): ContainerBuilder
    {
        $builder = $this->builderFor($frontTemplate);

        (new FlexyBundle())->loadExtension([], $this->configuratorFor($builder), $builder);

        return $builder;
    }

    private function prependFor(string $frontTemplate): ContainerBuilder
    {
        $builder = $this->builderFor($frontTemplate);

        (new FlexyBundle())->prependExtension($this->configuratorFor($builder), $builder);

        return $builder;
    }

    private function builderFor(string $frontTemplate): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('thelia_front_template', $frontTemplate);
        $builder->setParameter('kernel.bundles_metadata', [
            'FrameworkBundle' => [
                'path' => \dirname((string) (new \ReflectionClass(FrameworkBundle::class))->getFileName()),
            ],
        ]);

        // The bundle prepends the Stimulus configuration only when the bundle is installed,
        // and it imports its own config/packages/, which names extensions of its own: the
        // container has to answer for all of them, and nothing here loads any of them.
        foreach (self::EXTENSIONS_THE_BUNDLE_CONFIGURES as $alias) {
            $builder->registerExtension(new class($alias) extends Extension {
                public function __construct(private readonly string $alias)
                {
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }
            });
        }

        return $builder;
    }

    private function configuratorFor(ContainerBuilder $builder): ContainerConfigurator
    {
        $configDirectory = \dirname(__DIR__, 2).'/config';
        $fileLocator = new FileLocator($configDirectory);

        $phpLoader = new PhpFileLoader($builder, $fileLocator);
        $phpLoader->setResolver(new LoaderResolver([
            new YamlFileLoader($builder, $fileLocator),
            $phpLoader,
            new GlobFileLoader($builder, $fileLocator),
            new DirectoryLoader($builder, $fileLocator),
        ]));

        $instanceof = [];

        return new ContainerConfigurator(
            $builder,
            $phpLoader,
            $instanceof,
            $configDirectory.'/services.php',
            $configDirectory.'/services.php',
        );
    }
}
