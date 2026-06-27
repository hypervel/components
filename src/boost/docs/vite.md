# Asset Bundling (Vite)

- [Introduction](#introduction)
- [Installation & Setup](#installation)
  - [Installing Node](#installing-node)
  - [Installing Vite and the Laravel Vite Plugin](#installing-vite-and-laravel-plugin)
  - [Configuring Vite](#configuring-vite)
  - [Loading Your Scripts and Styles](#loading-your-scripts-and-styles)
- [Running Vite](#running-vite)
- [Working With JavaScript](#working-with-scripts)
  - [Aliases](#aliases)
  - [Vue](#vue)
  - [React](#react)
  - [Svelte](#svelte)
  - [Inertia](#inertia)
  - [URL Processing](#url-processing)
- [Working With Stylesheets](#working-with-stylesheets)
- [Working With Blade and Routes](#working-with-blade-and-routes)
  - [Processing Static Assets With Vite](#blade-processing-static-assets)
  - [Refreshing on Save](#blade-refreshing-on-save)
  - [Aliases](#blade-aliases)
- [Asset Prefetching](#asset-prefetching)
- [Custom Base URLs](#custom-base-urls)
- [Environment Variables](#environment-variables)
- [Disabling Vite in Tests](#disabling-vite-in-tests)
- [Server-Side Rendering (SSR)](#ssr)
- [Script and Style Tag Attributes](#script-and-style-attributes)
  - [Content Security Policy (CSP) Nonce](#content-security-policy-csp-nonce)
  - [Subresource Integrity (SRI)](#subresource-integrity-sri)
  - [Arbitrary Attributes](#arbitrary-attributes)
  - [Preload Tag Attributes](#preload-tag-attributes)
- [Advanced Customization](#advanced-customization)
  - [Dev Server Cross-Origin Resource Sharing (CORS)](#cors)
  - [Correcting Dev Server URLs](#correcting-dev-server-urls)

<a name="introduction"></a>
## Introduction

[Vite](https://vitejs.dev) is a modern frontend build tool that provides an extremely fast development environment and bundles your code for production. When building applications with Hypervel, you will typically use Vite to bundle your application's CSS and JavaScript files into production-ready assets.

Hypervel integrates seamlessly with Vite by providing a Blade directive to load your assets for development and production, and works with the `laravel-vite-plugin` NPM package.

<a name="installation"></a>
## Installation & Setup

> [!NOTE]
> The following documentation discusses how to manually install and configure the Laravel Vite plugin. However, Hypervel's [starter kits](/docs/{{version}}/starter-kits) already include all of this scaffolding and are the fastest way to get started with Hypervel and Vite.

<a name="installing-node"></a>
### Installing Node

You must ensure that Node.js 20.19+ or 22.12+ and NPM are installed before running Vite and the Laravel Vite plugin:

```shell
node -v
npm -v
```

You can easily install the latest version of Node and NPM using simple graphical installers from [the official Node website](https://nodejs.org/en/download/).

<a name="installing-vite-and-laravel-plugin"></a>
### Installing Vite and the Laravel Vite Plugin

Within a fresh installation of Hypervel, you will find a `package.json` file in the root of your application's directory structure. The default `package.json` file already includes everything you need to get started using Vite and the Laravel Vite plugin. You may install your application's frontend dependencies via NPM:

```shell
npm install
```

<a name="configuring-vite"></a>
### Configuring Vite

Vite is configured via a `vite.config.js` file in the root of your project. You are free to customize this file based on your needs, and you may also install any other plugins your application requires, such as `@vitejs/plugin-react`, `@sveltejs/vite-plugin-svelte` or `@vitejs/plugin-vue`.

The Laravel Vite plugin requires you to specify the entry points for your application. These may be JavaScript or CSS files, and include preprocessed languages such as TypeScript, JSX, TSX, and Sass.

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel([
            'resources/css/app.css',
            'resources/js/app.js',
        ]),
    ],
});
```

If you are building an SPA, including applications built using Inertia, Vite works best without CSS entry points:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel([
            'resources/css/app.css', // [tl! remove]
            'resources/js/app.js',
        ]),
    ],
});
```

Instead, you should import your CSS via JavaScript. Typically, this would be done in your application's `resources/js/app.js` file:

```js
import './bootstrap';
import '../css/app.css'; // [tl! add]
```

The Laravel Vite plugin also supports multiple entry points and advanced configuration options such as [SSR entry points](#ssr).

<a name="working-with-a-secure-development-server"></a>
#### Working With a Secure Development Server

If your local development web server is serving your application via HTTPS, you may run into issues connecting to the Vite development server. To use HTTPS with Vite, generate a trusted certificate and manually configure Vite to use the generated certificates:

```js
// ...
import fs from 'fs'; // [tl! add]

const host = 'my-app.test'; // [tl! add]

export default defineConfig({
    // ...
    server: { // [tl! add]
        host, // [tl! add]
        hmr: { host }, // [tl! add]
        https: { // [tl! add]
            key: fs.readFileSync(`/path/to/${host}.key`), // [tl! add]
            cert: fs.readFileSync(`/path/to/${host}.crt`), // [tl! add]
        }, // [tl! add]
    }, // [tl! add]
});
```

If you are unable to generate a trusted certificate for your system, you may install and configure the [@vitejs/plugin-basic-ssl plugin](https://github.com/vitejs/vite-plugin-basic-ssl). When using untrusted certificates, you will need to accept the certificate warning for Vite's development server in your browser by following the "Local" link in your console when running the `npm run dev` command.

<a name="loading-your-scripts-and-styles"></a>
### Loading Your Scripts and Styles

With your Vite entry points configured, you may now reference them in a `@vite()` Blade directive that you add to the `<head>` of your application's root template:

```blade
<!DOCTYPE html>
<head>
    {{-- ... --}}

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
```

If you're importing your CSS via JavaScript, you only need to include the JavaScript entry point:

```blade
<!DOCTYPE html>
<head>
    {{-- ... --}}

    @vite('resources/js/app.js')
</head>
```

The `@vite` directive will automatically detect the Vite development server and inject the Vite client to enable Hot Module Replacement. In build mode, the directive will load your compiled and versioned assets, including any imported CSS.

If needed, you may also specify the build path of your compiled assets when invoking the `@vite` directive:

```blade
<!doctype html>
<head>
    {{-- Given build path is relative to public path. --}}

    @vite('resources/js/app.js', 'vendor/courier/build')
</head>
```

<a name="inline-assets"></a>
#### Inline Assets

Sometimes it may be necessary to include the raw content of assets rather than linking to the versioned URL of the asset. For example, you may need to include asset content directly into your page when passing HTML content to a PDF generator. You may output the content of Vite assets using the `content` method provided by the `Vite` facade:

```blade
@use('Hypervel\Support\Facades\Vite')

<!doctype html>
<head>
    {{-- ... --}}

    <style>
        {!! Vite::content('resources/css/app.css') !!}
    </style>
    <script>
        {!! Vite::content('resources/js/app.js') !!}
    </script>
</head>
```

<a name="running-vite"></a>
## Running Vite

There are two ways you can run Vite. You may run the development server via the `dev` command, which is useful while developing locally. The development server will automatically detect changes to your files and instantly reflect them in any open browser windows.

Or, running the `build` command will version and bundle your application's assets and get them ready for you to deploy to production:

```shell
# Run the Vite development server...
npm run dev

# Build and version the assets for production...
npm run build
```

<a name="working-with-scripts"></a>
## Working With JavaScript

<a name="aliases"></a>
### Aliases

By default, the Laravel Vite plugin provides a common alias to help you hit the ground running and conveniently import your application's assets:

```js
{
    '@': '/resources/js'
}
```

You may overwrite the `'@'` alias by adding your own to the `vite.config.js` configuration file:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel(['resources/ts/app.tsx']),
    ],
    resolve: {
        alias: {
            '@': '/resources/ts',
        },
    },
});
```

<a name="vue"></a>
### Vue

If you would like to build your frontend using the [Vue](https://vuejs.org/) framework, then you will also need to install the `@vitejs/plugin-vue` plugin:

```shell
npm install --save-dev @vitejs/plugin-vue
```

You may then include the plugin in your `vite.config.js` configuration file. There are a few additional options you will need when using the Vue plugin with Hypervel:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel(['resources/js/app.js']),
        vue({
            template: {
                transformAssetUrls: {
                    // The Vue plugin will re-write asset URLs, when referenced
                    // in Single File Components, to point to the Hypervel web
                    // server. Setting this to `null` allows the Laravel Vite plugin
                    // to instead re-write asset URLs to point to the Vite
                    // server instead.
                    base: null,

                    // The Vue plugin will parse absolute URLs and treat them
                    // as absolute paths to files on disk. Setting this to
                    // `false` will leave absolute URLs un-touched so they can
                    // reference assets in the public directory as expected.
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
```

<a name="react"></a>
### React

If you would like to build your frontend using the [React](https://reactjs.org/) framework, then you will also need to install the `@vitejs/plugin-react` plugin:

```shell
npm install --save-dev @vitejs/plugin-react
```

You may then include the plugin in your `vite.config.js` configuration file:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel(['resources/js/app.jsx']),
        react(),
    ],
});
```

You will need to ensure that any files containing JSX have a `.jsx` or `.tsx` extension, remembering to update your entry point, if required, as [shown above](#configuring-vite).

You will also need to include the additional `@viteReactRefresh` Blade directive alongside your existing `@vite` directive.

```blade
@viteReactRefresh
@vite('resources/js/app.jsx')
```

The `@viteReactRefresh` directive must be called before the `@vite` directive.

> [!NOTE]
> Hypervel's [starter kits](/docs/{{version}}/starter-kits) already include the proper Hypervel, React, and Vite configuration. These starter kits offer the fastest way to get started with Hypervel, React, and Vite.

<a name="svelte"></a>
### Svelte

If you would like to build your frontend using the [Svelte](https://svelte.dev/) framework, then you will also need to install the `@sveltejs/vite-plugin-svelte` plugin:

```shell
npm install --save-dev @sveltejs/vite-plugin-svelte
```

You may then include the plugin in your `vite.config.js` configuration file.

```js
import { svelte } from '@sveltejs/vite-plugin-svelte';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/app.ts'],
      ssr: 'resources/js/ssr.ts',
      refresh: [
        'app/View/Components/**',
        'lang/**',
        'resources/lang/**',
        'resources/views/**',
        'routes/**',
      ],
    }),
    svelte(),
  ],
});
```

<a name="inertia"></a>
### Inertia

The Laravel Vite plugin provides a convenient `resolvePageComponent` function to help you resolve your Inertia page components. Below is an example of the helper in use with Vue 3; however, you may also utilize the function in other frameworks such as React or Svelte:

```js
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

createInertiaApp({
  resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },
});
```

If you are using Vite's code splitting feature with Inertia, we recommend configuring [asset prefetching](#asset-prefetching).

> [!NOTE]
> Hypervel's [starter kits](/docs/{{version}}/starter-kits) already include the proper Hypervel, Inertia, and Vite configuration. These starter kits offer the fastest way to get started with Hypervel, Inertia, and Vite.

<a name="url-processing"></a>
### URL Processing

When using Vite and referencing assets in your application's HTML, CSS, or JS, there are a couple of caveats to consider. First, if you reference assets with an absolute path, Vite will not include the asset in the build; therefore, you should ensure that the asset is available in your public directory. You should avoid using absolute paths when using a [dedicated CSS entrypoint](#configuring-vite) because, during development, browsers will try to load these paths from the Vite development server, where the CSS is hosted, rather than from your public directory.

When referencing relative asset paths, you should remember that the paths are relative to the file where they are referenced. Any assets referenced via a relative path will be re-written, versioned, and bundled by Vite.

Consider the following project structure:

```text
public/
  taylor.png
resources/
  js/
    Pages/
      Welcome.vue
  images/
    abigail.png
```

The following example demonstrates how Vite will treat relative and absolute URLs:

```html
<!-- This asset is not handled by Vite and will not be included in the build -->
<img src="/taylor.png">

<!-- This asset will be re-written, versioned, and bundled by Vite -->
<img src="../../images/abigail.png">
```

<a name="working-with-stylesheets"></a>
## Working With Stylesheets

> [!NOTE]
> Hypervel's application skeleton and [starter kits](/docs/{{version}}/starter-kits) already include the proper Tailwind and Vite configuration. Or, if you would like to use Tailwind and Hypervel without using one of our starter kits, check out [Tailwind's installation guide for Vite](https://tailwindcss.com/docs/installation/using-vite).

Hypervel's application skeleton already includes Tailwind and a properly configured `vite.config.js` file. So, you only need to start the Vite development server or run the `dev` Composer command, which will start both the Hypervel and Vite development servers:

```shell
composer run dev
```

Your application's CSS may be placed within the `resources/css/app.css` file.

<a name="working-with-fonts"></a>
## Working With Fonts

The Laravel Vite plugin can serve optimized, self-hosted fonts for your application. When fonts are configured, the plugin resolves the requested font files, emits them as Vite assets, generates font CSS, and writes a font manifest that may be consumed by Blade's [`@fonts` directive](/docs/{{version}}/blade#fonts).

To configure fonts, import one or more provider helpers from `laravel-vite-plugin/fonts` and add them to the Laravel plugin's `fonts` option:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { google } from 'laravel-vite-plugin/fonts';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            fonts: [
                google('Inter', {
                    alias: 'sans',
                    weights: [400, 500, 600, 700],
                    styles: ['normal', 'italic'],
                    subsets: ['latin'],
                    display: 'swap',
                    preload: [
                        { weight: 400 },
                        { weight: 700 },
                    ],
                    fallbacks: ['system-ui', 'sans-serif'],
                }),
            ],
        }),
    ],
});
```

In this example, the `Inter` font will be available through the `sans` alias. The plugin will generate a `--font-sans` CSS variable and a `.font-sans` utility class that applies the generated font stack.

<a name="font-providers"></a>
### Font Providers

The Laravel Vite plugin includes provider helpers for Google Fonts, Bunny Fonts, Fontsource, and local fonts:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny, fontsource, google, local } from 'laravel-vite-plugin/fonts';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            fonts: [
                google('Inter', { alias: 'sans' }),
                bunny('Figtree', { alias: 'body' }),
                fontsource('JetBrains Mono', { alias: 'mono' }),
                local('Brand Sans', {
                    alias: 'brand',
                    src: 'resources/fonts/brand-sans',
                }),
            ],
        }),
    ],
});
```

The `fontsource` provider reads fonts from an installed Fontsource package. By default, the package name is derived from the font family, such as `@fontsource/jetbrains-mono`. If your application uses a different package name, you may specify it using the `package` option.

<a name="local-fonts"></a>
### Local Fonts

When using local fonts, the `src` option may point to a single font file, a directory, or a glob pattern. The plugin will discover supported font files and infer their weight and style from their filenames:

```js
local('Brand Sans', {
    alias: 'brand',
    src: 'resources/fonts/brand-sans/*.woff2',
})
```

If you need full control over the available variants, you may define them explicitly using the `variants` option:

```js
local('Brand Sans', {
    alias: 'brand',
    variants: [
        { src: 'resources/fonts/BrandSans-Regular.woff2', weight: 400 },
        { src: 'resources/fonts/BrandSans-Italic.woff2', weight: 400, style: 'italic' },
        { src: ['resources/fonts/BrandSans-Bold.woff2', 'resources/fonts/BrandSans-Bold.ttf'], weight: 700 },
    ],
})
```

<a name="font-options"></a>
### Font Options

Depending on the provider, font definitions may accept several options that allow you to customize the generated font CSS:

<div class="content-list" markdown="1">

- `alias` defines the name used by Blade's `@fonts` directive and defaults to a slug of the font family.
- `variable` defines the generated CSS variable and defaults to `--font-{alias}`.
- `weights` defines the remote or Fontsource font weights that should be resolved and defaults to `[400]`.
- `styles` defines the remote or Fontsource font styles that should be resolved and defaults to `['normal']`.
- `subsets` defines the remote or Fontsource font subsets that should be resolved and defaults to `['latin']`.
- `display` defines the `font-display` value and defaults to `swap`.
- `preload` controls which WOFF2 font variants should be preloaded. This option may be `true`, `false`, or an array of `{ weight, style }` selectors.
- `fallbacks` defines additional fallback fonts that should be appended to the generated font stack.
- `optimizedFallbacks` attempts to generate metric-adjusted fallback font faces using the optional `fontaine` package and defaults to `true`.

</div>

<a name="working-with-blade-and-routes"></a>
## Working With Blade and Routes

<a name="blade-processing-static-assets"></a>
### Processing Static Assets With Vite

When referencing assets in your JavaScript or CSS, Vite automatically processes and versions them. In addition, when building Blade based applications, Vite can also process and version static assets that you reference solely in Blade templates.

However, in order to accomplish this, you need to make Vite aware of your assets by specifying them in the plugin's `assets` option. For example, if you want to process and version all images stored in `resources/images` and all fonts stored in `resources/fonts`, you should add the following to your Vite configuration:

```js
laravel({
    input: 'resources/js/app.js',
    assets: ['resources/images/**', 'resources/fonts/**'],
})
```

These assets will now be processed by Vite when running `npm run build`. You can then reference these assets in Blade templates using the `Vite::asset` method, which will return the versioned URL for a given asset:

```blade
<img src="{{ Vite::asset('resources/images/logo.png') }}">
```

> [!NOTE]
> Prior to version 3 of the Laravel Vite plugin, static assets had to be imported in your application's entry point using `import.meta.glob`. The `assets` option was introduced due to changes in Vite 8.

<a name="blade-refreshing-on-save"></a>
### Refreshing on Save

When your application is built using traditional server-side rendering with Blade, Vite can improve your development workflow by automatically refreshing the browser when you make changes to view files in your application. To get started, specify the Hypervel paths that should trigger a full page refresh:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // ...
            refresh: [
                'app/View/Components/**',
                'lang/**',
                'resources/lang/**',
                'resources/views/**',
                'routes/**',
            ],
        }),
    ],
});
```

With this configuration, saving files in the following directories will trigger the browser to perform a full page refresh while you are running `npm run dev`:

- `app/View/Components/**`
- `lang/**`
- `resources/lang/**`
- `resources/views/**`
- `routes/**`

If these default paths do not suit your needs, you can specify your own list of paths to watch:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // ...
            refresh: ['resources/views/**'],
        }),
    ],
});
```

Under the hood, the Laravel Vite plugin uses the [vite-plugin-full-reload](https://github.com/ElMassimo/vite-plugin-full-reload) package, which offers some advanced configuration options to fine-tune this feature's behavior. If you need this level of customization, you may provide a `config` definition:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // ...
            refresh: [{
                paths: ['path/to/watch/**'],
                config: { delay: 300 }
            }],
        }),
    ],
});
```

<a name="blade-aliases"></a>
### Aliases

It is common in JavaScript applications to [create aliases](#aliases) to regularly referenced directories. But, you may also create aliases to use in Blade by using the `macro` method on the `Hypervel\Support\Facades\Vite` class. Typically, "macros" should be defined within the `boot` method of a [service provider](/docs/{{version}}/providers):

```php
/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Vite::macro('image', fn (string $asset) => $this->asset("resources/images/{$asset}"));
}
```

Once a macro has been defined, it can be invoked within your templates. For example, we can use the `image` macro defined above to reference an asset located at `resources/images/logo.png`:

```blade
<img src="{{ Vite::image('logo.png') }}" alt="Hypervel Logo">
```

<a name="asset-prefetching"></a>
## Asset Prefetching

When building an SPA using Vite's code splitting feature, required assets are fetched on each page navigation. This behavior can lead to delayed UI rendering. If this is a problem for your frontend framework of choice, Hypervel offers the ability to eagerly prefetch your application's JavaScript and CSS assets on initial page load.

You can instruct Hypervel to eagerly prefetch your assets by invoking the `Vite::prefetch` method in the `boot` method of a [service provider](/docs/{{version}}/providers):

```php
<?php

namespace App\Providers;

use Hypervel\Support\Facades\Vite;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ...
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
```

In the example above, assets will be prefetched with a maximum of `3` concurrent downloads on each page load. You can modify the concurrency to suit your application's needs or specify no concurrency limit if the application should download all assets at once:

```php
/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Vite::prefetch();
}
```

By default, prefetching will begin when the [page _load_ event](https://developer.mozilla.org/en-US/docs/Web/API/Window/load_event) fires. If you would like to customize when prefetching begins, you may specify an event that Vite will listen for:

```php
/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Vite::prefetch(event: 'vite:prefetch');
}
```

Given the code above, prefetching will now begin when you manually dispatch the `vite:prefetch` event on the `window` object. For example, you could have prefetching begin three seconds after the page loads:

```html
<script>
    addEventListener('load', () => setTimeout(() => {
        dispatchEvent(new Event('vite:prefetch'))
    }, 3000))
</script>
```

You may also choose the prefetching strategy explicitly using the `useWaterfallPrefetching` or `useAggressivePrefetching` methods:

```php
use Hypervel\Support\Facades\Vite;

Vite::useWaterfallPrefetching(concurrency: 3);

Vite::useAggressivePrefetching();
```

<a name="custom-base-urls"></a>
## Custom Base URLs

If your Vite compiled assets are deployed to a domain separate from your application, such as via a CDN, you must specify the `ASSET_URL` environment variable within your application's `.env` file:

```env
ASSET_URL=https://cdn.example.com
```

After configuring the asset URL, all re-written URLs to your assets will be prefixed with the configured value:

```text
https://cdn.example.com/build/assets/app.9dce8d17.js
```

Remember that [absolute URLs are not re-written by Vite](#url-processing), so they will not be prefixed.

<a name="environment-variables"></a>
## Environment Variables

You may inject environment variables into your JavaScript by prefixing them with `VITE_` in your application's `.env` file:

```env
VITE_SENTRY_DSN_PUBLIC=http://example.com
```

You may access injected environment variables via the `import.meta.env` object:

```js
import.meta.env.VITE_SENTRY_DSN_PUBLIC
```

<a name="disabling-vite-in-tests"></a>
## Disabling Vite in Tests

Hypervel's Vite integration will attempt to resolve your assets while running your tests, which requires you to either run the Vite development server or build your assets.

If you would prefer to mock Vite during testing, you may call the `withoutVite` method, which is available for any tests that extend Hypervel's `TestCase` class:

```php tab=Pest
test('without vite example', function () {
    $this->withoutVite();

    // ...
});
```

```php tab=PHPUnit
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_without_vite_example(): void
    {
        $this->withoutVite();

        // ...
    }
}
```

If you would like to disable Vite for all tests, you may call the `withoutVite` method from the `setUp` method on your base `TestCase` class:

```php
<?php

namespace Tests;

use Hypervel\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void // [tl! add:start]
    {
        parent::setUp();

        $this->withoutVite();
    } // [tl! add:end]
}
```

<a name="ssr"></a>
## Server-Side Rendering (SSR)

The Laravel Vite plugin makes it painless to set up server-side rendering with Vite. To get started, create an SSR entry point at `resources/js/ssr.js` and specify the entry point by passing a configuration option to the Laravel Vite plugin:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            ssr: 'resources/js/ssr.js',
        }),
    ],
});
```

To ensure you don't forget to rebuild the SSR entry point, we recommend augmenting the "build" script in your application's `package.json` to create your SSR build:

```json
"scripts": {
     "dev": "vite",
     "build": "vite build" // [tl! remove]
     "build": "vite build && vite build --ssr" // [tl! add]
}
```

Then, to build and start the SSR server, you may run the following commands:

```shell
npm run build
node bootstrap/ssr/ssr.js
```

If you are using [SSR with Inertia](https://inertiajs.com/server-side-rendering), you may instead use the `inertia:start-ssr` Artisan command to start the SSR server:

```shell
php artisan inertia:start-ssr
```

> [!NOTE]
> Hypervel's [starter kits](/docs/{{version}}/starter-kits) already include the proper Hypervel, Inertia SSR, and Vite configuration. These starter kits offer the fastest way to get started with Hypervel, Inertia SSR, and Vite.

<a name="script-and-style-attributes"></a>
## Script and Style Tag Attributes

<a name="content-security-policy-csp-nonce"></a>
### Content Security Policy (CSP) Nonce

If you wish to include a [nonce attribute](https://developer.mozilla.org/en-US/docs/Web/HTML/Global_attributes/nonce) on your script and style tags as part of your [Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP), you may generate or specify a nonce using the `useCspNonce` method within a custom [middleware](/docs/{{version}}/middleware):

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class AddContentSecurityPolicyHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Hypervel\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        return $next($request)->withHeaders([
            'Content-Security-Policy' => "script-src 'nonce-".Vite::cspNonce()."'",
        ]);
    }
}
```

After invoking the `useCspNonce` method, Hypervel will automatically include the `nonce` attributes on all generated script and style tags.

If you need to specify the nonce elsewhere, you may retrieve it using the `cspNonce` method:

```blade
{{ Vite::cspNonce() }}
```

If you already have a nonce that you would like to instruct Hypervel to use, you may pass the nonce to the `useCspNonce` method:

```php
Vite::useCspNonce($nonce);
```

<a name="subresource-integrity-sri"></a>
### Subresource Integrity (SRI)

If your Vite manifest includes `integrity` hashes for your assets, Hypervel will automatically add the `integrity` attribute on any script and style tags it generates in order to enforce [Subresource Integrity](https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity). By default, Vite does not include the `integrity` hash in its manifest, but you may enable it by installing the [vite-plugin-manifest-sri](https://www.npmjs.com/package/vite-plugin-manifest-sri) NPM plugin:

```shell
npm install --save-dev vite-plugin-manifest-sri
```

You may then enable this plugin in your `vite.config.js` file:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import manifestSRI from 'vite-plugin-manifest-sri';// [tl! add]

export default defineConfig({
    plugins: [
        laravel({
            // ...
        }),
        manifestSRI(),// [tl! add]
    ],
});
```

If required, you may also customize the manifest key where the integrity hash can be found. This should be configured while your application is booting, such as from a [service provider](/docs/{{version}}/providers):

```php
use Hypervel\Support\Facades\Vite;

Vite::useIntegrityKey('custom-integrity-key');
```

If you would like to disable this auto-detection completely, you may pass `false` to the `useIntegrityKey` method:

```php
Vite::useIntegrityKey(false);
```

<a name="arbitrary-attributes"></a>
### Arbitrary Attributes

If you need to include additional attributes on your script and style tags, such as the [data-turbo-track](https://turbo.hotwired.dev/handbook/drive#reloading-when-assets-change) attribute, you may specify them via the `useScriptTagAttributes` and `useStyleTagAttributes` methods. Typically, these methods should be invoked from a [service provider](/docs/{{version}}/providers):

```php
use Hypervel\Support\Facades\Vite;

Vite::useScriptTagAttributes([
    'data-turbo-track' => 'reload', // Specify a value for the attribute...
    'async' => true, // Specify an attribute without a value...
    'integrity' => false, // Exclude an attribute that would otherwise be included...
]);

Vite::useStyleTagAttributes([
    'data-turbo-track' => 'reload',
]);
```

If you need to conditionally add attributes, you may pass a callback that will receive the asset source path, its URL, its manifest chunk, and the entire manifest:

```php
use Hypervel\Support\Facades\Vite;

Vite::useScriptTagAttributes(fn (string $src, string $url, array|null $chunk, array|null $manifest) => [
    'data-turbo-track' => $src === 'resources/js/app.js' ? 'reload' : false,
]);

Vite::useStyleTagAttributes(fn (string $src, string $url, array|null $chunk, array|null $manifest) => [
    'data-turbo-track' => $chunk && $chunk['isEntry'] ? 'reload' : false,
]);
```

> [!WARNING]
> The `$chunk` and `$manifest` arguments will be `null` while the Vite development server is running.

<a name="preload-tag-attributes"></a>
### Preload Tag Attributes

You may customize the attributes that Hypervel adds to preload tags using the `usePreloadTagAttributes` method. This method accepts an array or a callback that receives the asset source path, its URL, its manifest chunk, and the entire manifest:

```php
use Hypervel\Support\Facades\Vite;

Vite::usePreloadTagAttributes(fn (string $src, string $url, array|null $chunk, array|null $manifest) => [
    'data-turbo-track' => $src === 'resources/js/app.js' ? 'reload' : false,
]);
```

If you return `false` from the callback, the preload tag will not be rendered:

```php
use Hypervel\Support\Facades\Vite;

Vite::usePreloadTagAttributes(fn (string $src, string $url) => $src === 'resources/js/app.js'
    ? false
    : []);
```

<a name="advanced-customization"></a>
## Advanced Customization

Out of the box, Hypervel's Vite integration uses sensible conventions that should work for the majority of applications; however, sometimes you may need to customize Vite's behavior.

Methods that configure the hot file, build directory, manifest filename, generated tag attributes, or asset path resolver configure the shared Vite instance and should be invoked while your application is booting, such as from a [service provider](/docs/{{version}}/providers):

```php
use Hypervel\Support\Facades\Vite;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Vite::useHotFile(storage_path('vite.hot')) // Customize the "hot" file...
        ->useBuildDirectory('bundle') // Customize the build directory...
        ->useManifestFilename('assets.json') // Customize the manifest filename...
        ->createAssetPathsUsing(function (string $path, ?bool $secure) { // Customize the backend path generation for built assets...
            return "https://cdn.example.com/{$path}";
        });
}
```

Once your shared configuration has been registered, you may specify entry points in Blade using the `@vite` directive or by rendering the `Vite` facade directly:

```blade
{{ Vite::withEntryPoints(['resources/js/app.js']) }}
```

If you need to add entry points to the current render without replacing the existing ones, you may use the `mergeEntryPoints` method:

```blade
{{ Vite::mergeEntryPoints(['resources/js/dashboard.js']) }}
```

Within the `vite.config.js` file, you should then specify the same configuration:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            hotFile: 'storage/vite.hot', // Customize the "hot" file...
            buildDirectory: 'bundle', // Customize the build directory...
            input: ['resources/js/app.js'], // Specify the entry points...
        }),
    ],
    build: {
      manifest: 'assets.json', // Customize the manifest filename...
    },
});
```

<a name="cors"></a>
### Dev Server Cross-Origin Resource Sharing (CORS)

If you are experiencing Cross-Origin Resource Sharing (CORS) issues in the browser while fetching assets from the Vite dev server, you may need to grant your custom origin access to the dev server. Vite combined with the Laravel Vite plugin allows the following origins without any additional configuration:

- `::1`
- `127.0.0.1`
- `localhost`
- `*.test`
- `*.localhost`
- `APP_URL` in the project's `.env`

The easiest way to allow a custom origin for your project is to ensure that your application's `APP_URL` environment variable matches the origin you are visiting in your browser. For example, if you are visiting `https://my-app.hypervel`, you should update your `.env` to match:

```env
APP_URL=https://my-app.hypervel
```

If you need more fine-grained control over the origins, such as supporting multiple origins, you should utilize [Vite's comprehensive and flexible built-in CORS server configuration](https://vite.dev/config/server-options.html#server-cors). For example, you may specify multiple origins in the `server.cors.origin` configuration option in the project's `vite.config.js` file:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: [
                'app/View/Components/**',
                'lang/**',
                'resources/lang/**',
                'resources/views/**',
                'routes/**',
            ],
        }),
    ],
    server: {  // [tl! add]
        cors: {  // [tl! add]
            origin: [  // [tl! add]
                'https://backend.hypervel',  // [tl! add]
                'http://admin.hypervel:8566',  // [tl! add]
            ],  // [tl! add]
        },  // [tl! add]
    },  // [tl! add]
});
```

You may also include regex patterns, which can be helpful if you would like to allow all origins for a given top-level domain, such as `*.hypervel`:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: [
                'app/View/Components/**',
                'lang/**',
                'resources/lang/**',
                'resources/views/**',
                'routes/**',
            ],
        }),
    ],
    server: {  // [tl! add]
        cors: {  // [tl! add]
            origin: [ // [tl! add]
                // Supports: SCHEME://DOMAIN.hypervel[:PORT] [tl! add]
                /^https?:\/\/.*\.hypervel(:\d+)?$/, //[tl! add]
            ], // [tl! add]
        }, // [tl! add]
    }, // [tl! add]
});
```

<a name="correcting-dev-server-urls"></a>
### Correcting Dev Server URLs

Some plugins within the Vite ecosystem assume that URLs which begin with a forward-slash will always point to the Vite dev server. However, due to the nature of the Hypervel integration, this is not the case.

For example, the `vite-imagetools` plugin outputs URLs like the following while Vite is serving your assets:

```html
<img src="/@imagetools/f0b2f404b13f052c604e632f2fb60381bf61a520">
```

The `vite-imagetools` plugin is expecting that the output URL will be intercepted by Vite and the plugin may then handle all URLs that start with `/@imagetools`. If you are using plugins that are expecting this behavior, you will need to manually correct the URLs. You can do this in your `vite.config.js` file by using the `transformOnServe` option.

In this particular example, we will prepend the dev server URL to all occurrences of `/@imagetools` within the generated code:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { imagetools } from 'vite-imagetools';

export default defineConfig({
    plugins: [
        laravel({
            // ...
            transformOnServe: (code, devServerUrl) => code.replaceAll('/@imagetools', devServerUrl+'/@imagetools'),
        }),
        imagetools(),
    ],
});
```

Now, while Vite is serving assets, it will output URLs that point to the Vite dev server:

```html
- <img src="/@imagetools/f0b2f404b13f052c604e632f2fb60381bf61a520"><!-- [tl! remove] -->
+ <img src="http://[::1]:5173/@imagetools/f0b2f404b13f052c604e632f2fb60381bf61a520"><!-- [tl! add] -->
```
