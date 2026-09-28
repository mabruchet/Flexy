# Flexy

Front-office template for [Thelia 3](https://thelia.net), built on Twig, Symfony UX and Tailwind CSS v4.

## Requirements

- Thelia **3.0.0** or later
- PHP **8.3+**

Assets are served through Symfony **AssetMapper** — there is no Node build step, no bundler and no `dist/` directory. Tailwind is compiled by `symfonycasts/tailwind-bundle`, which downloads a standalone binary on first use.

## Installation

```bash
composer require thelia/flexy
```

`thelia/installer` places the template under `templates/frontOffice/flexy`. Activate it from the back office, or set it in `.env`:

```dotenv
ACTIVE_FRONT_TEMPLATE=flexy
```

## Layout

| Path | Contents |
|---|---|
| `*.html.twig` | Pages, at the root. `config/views.yaml` lists the ones a controller renders, which are not reachable by name |
| `components/` | Twig components, grouped by responsibility: `Atoms`, `Molecules`, `Organisms`, `Layouts`, `Forms`, `Fields` |
| `src/` | PHP: controllers, services, DTOs, Twig extensions, form types (`FlexyBundle\` namespace) |
| `assets/` | Styles, icons, images, Stimulus controllers |
| `form/` | Form theme — applied explicitly per template, never registered globally |
| `translations/` | `messages.en_US.yaml`, `messages.fr_FR.yaml` |
| `docs/` | Notes on the parts whose behaviour the code alone does not explain |

A component owns its template, its styles and its behaviour in a single directory. `Base.php` holds the data, `Base.html.twig` the markup, `Base.css` the styles, `base_controller.js` the interactions.

## Child templates

A template of the shop that declares `<parent>flexy</parent>` in its `template.xml` ships only
what it overrides, and Flexy answers for the rest:

| It ships | What happens |
|---|---|
| A root page (`product.html.twig`) | Replaces Flexy's. To extend one instead of copying it, `{% extends '@theme_flexy/base.html.twig' %}`: every template of the chain is registered under `@theme_<name>`. `base.html.twig` exposes the `favicons` and `fonts` blocks for the two things a shop always replaces |
| A component directory (`components/Molecules/Button/`) | Replaces Flexy's, anonymous components included; the other components of Flexy stay available |
| `assets/styles/app.css` | Becomes the Tailwind entry point. Import Flexy's (`@import "../../../flexy/assets/styles/app.css"`), which carries its `@source` list, then add your own sources and a `@theme` block: a token declared there replaces Flexy's |
| `assets/icons/*.svg` | Added to Flexy's icons; a file of the same name replaces it everywhere `ux_icon()` asks for that name |
| `translations/messages.<locale>.yaml` | Loaded after Flexy's: a key it repeats replaces Flexy's |
| An `importmap.php`, Stimulus controllers | Its own; without them, Flexy's serve |

`/toolkit` lists the stories of the whole chain, the child's first, and previews them at the
breakpoints of the nearest `variables.css`.

The project has one setting to check: `twig_component.anonymous_template_directory` must be
`'@Flexy'` (`config/packages/twig_component.yaml`). The thelia-project skeleton sets a filesystem
path there, which is the nearest `components/` directory alone: a child that ships one component
would then lose every anonymous component of Flexy.

## Extending it

The template declares `theme_hook()` extension points across its pages — `layout.head.top`, `product.bottom`, `cart.top` and others. A module answers one by implementing `Thelia\Core\Hook\Theme\ThemeHookInterface`; the tag priority drives the rendering order.

The SEOne module already answers `layout.head.top` and `layout.head.bottom`, which is where the title, description, canonical, hreflang and structured data come from.

## Deploying

Check that your web server serves `.webmanifest` as `application/manifest+json`. Once the
assets are compiled the manifest is a static file, so its media type comes from the server
and nothing in the theme can set it. Whether a given server maps that extension depends on
its own table, so verify rather than assume:

```bash
curl -sI https://example.com/assets/.../site.webmanifest | grep -i content-type
```

If it answers anything else, map the extension in the server configuration. With nginx, for
instance:

```nginx
types { application/manifest+json  webmanifest; }
```

## Development

```bash
ddev composer cs-diff          # coding standards, dry run
ddev composer cs               # and fix them
ddev composer phpstan-flexy    # static analysis
ddev composer test:http-flexy  # HTTP smoke tests
```

Run test suites through the `composer test:*` scripts only. Calling `vendor/bin/phpunit` directly resolves to the development database instead of the test one.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
