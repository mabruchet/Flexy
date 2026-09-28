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

namespace FlexyBundle\DependencyInjection\Compiler;

use FlexyBundle\Template\FrontTemplateChain;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

/**
 * This bundle sets `twig_component.anonymous_template_directory` to the `@Flexy` namespace, so
 * that anonymous components are looked up through the whole template chain. The component
 * finder resolves it through the Twig loader; `debug:twig-component` does not: it appends the
 * setting to `twig.default_path` and hands the result to a Finder, which throws on the
 * `templates/@Flexy` directory that does not exist (ux-twig-component 2.36,
 * TwigComponentDebugCommand::findAnonymousComponents()).
 *
 * The command is given the nearest `components/` directory of the chain instead, relative to
 * `twig.default_path` as it expects. It lists the anonymous components of that template under
 * their own name; the ones a child inherits from its parents only appear under the
 * `theme_<name>:` prefix the command derives from the Twig namespace of each template.
 */
final class TwigComponentDebugDirectoryPass implements CompilerPassInterface
{
    public const DEBUG_COMMAND = 'ux.twig_component.command.debug';

    /** The `?string $anonymousDirectory` argument of TwigComponentDebugCommand::__construct(). */
    public const ANONYMOUS_DIRECTORY_ARGUMENT = 4;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::DEBUG_COMMAND) || !$container->hasParameter('twig.default_path')) {
            return;
        }

        $definition = $container->getDefinition(self::DEBUG_COMMAND);
        $anonymousDirectory = $definition->getArguments()[self::ANONYMOUS_DIRECTORY_ARGUMENT] ?? null;

        // Only a namespace is out of the command's reach; a filesystem path a project sets
        // there is what the command already reads.
        if (!\is_string($anonymousDirectory) || !str_starts_with($anonymousDirectory, '@')) {
            return;
        }

        $parameters = $container->getParameterBag();
        $frontTemplate = $container->hasParameter('thelia_front_template')
            ? $parameters->resolveValue($container->getParameter('thelia_front_template'))
            : '';
        $twigDefaultPath = $parameters->unescapeValue($parameters->resolveValue($container->getParameter('twig.default_path')));

        if (!\is_string($frontTemplate) || !\is_string($twigDefaultPath)) {
            return;
        }

        $componentsDirectory = (new FrontTemplateChain($frontTemplate))->nearest('components', is_dir(...));

        if (null === $componentsDirectory) {
            return;
        }

        $definition->replaceArgument(
            self::ANONYMOUS_DIRECTORY_ARGUMENT,
            rtrim((new Filesystem())->makePathRelative($componentsDirectory . '/components', $twigDefaultPath), '/'),
        );
    }
}
