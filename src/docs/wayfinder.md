# Hypervel Wayfinder

- [Introduction](#introduction)
- [Installation](#installation)
- [Generating TypeScript Definitions](#generating-typescript-definitions)
    - [Deploying With Cached Routes](#deploying-with-cached-routes)
- [Using Wayfinder](#using-wayfinder)
    - [Route Parameters](#route-parameters)
    - [Invokable Controllers](#invokable-controllers)
    - [Importing Controllers](#importing-controllers)
    - [Importing Named Routes](#importing-named-routes)
    - [Multiple Routes to the Same Action](#multiple-routes-to-the-same-action)
    - [Conventional Forms](#conventional-forms)
- [Query Parameters](#query-parameters)
- [URL Defaults](#url-defaults)
    - [Server-Side Rendering](#server-side-rendering)
- [Wayfinder and Inertia](#wayfinder-and-inertia)
- [URL Origins](#url-origins)

<a name="introduction"></a>
## Introduction

[Hypervel Wayfinder](https://github.com/hypervel/components/tree/0.4/src/wayfinder) generates fully typed TypeScript functions for your application's controllers and named routes. These functions allow your frontend to call backend endpoints without hardcoding URLs or manually keeping route parameters in sync.

<a name="installation"></a>
## Installation

To get started, install Wayfinder using the Composer package manager:

```shell
composer require hypervel/wayfinder
```

Next, install the [Wayfinder Vite plugin](https://github.com/laravel/vite-plugin-wayfinder) so your route definitions are generated during Vite builds and whenever routes or controllers change during development:

```shell
npm install --save-dev @laravel/vite-plugin-wayfinder
```

Then, add the plugin to your application's `vite.config.ts` file:

```ts
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        wayfinder(),
        // ...
    ],
});
```

<a name="generating-typescript-definitions"></a>
## Generating TypeScript Definitions

You may generate TypeScript definitions manually using the `wayfinder:generate` Artisan command:

```shell
php artisan wayfinder:generate
```

By default, Wayfinder generates `actions`, `routes`, and `wayfinder` directories within `resources/js`. You may provide a different base directory using the `--path` option:

```shell
php artisan wayfinder:generate --path=resources/js/generated
```

The `--skip-actions` and `--skip-routes` options may be used to skip controller or named-route generation:

```shell
php artisan wayfinder:generate --skip-actions
php artisan wayfinder:generate --skip-routes
```

When the Vite plugin regenerates Wayfinder output during every build, the generated directories may be added to your application's `.gitignore` file. If your deployment does not install Composer dependencies or run the generator, commit the generated files instead and regenerate them whenever your routes or controllers change.

<a name="deploying-with-cached-routes"></a>
### Deploying With Cached Routes

Wayfinder reads routes from the application's registered router. If the application boots with a route cache created by an earlier release, newly added routes will be missing from the generated output.

If your deployment runs `php artisan optimize` or `php artisan route:cache`, clear the previous route cache before building frontend assets:

```shell
php artisan route:clear
npm run build
```

The deployment may create a new route cache after the frontend build has completed.

<a name="using-wayfinder"></a>
## Using Wayfinder

Wayfinder functions return an object containing the resolved URL and default HTTP method:

```ts
import { show } from '@/actions/App/Http/Controllers/PostController';

show(1); // { url: '/posts/1', method: 'get' }
```

You may use the `url` method when you only need the URL, or call one of the HTTP methods registered for the route:

```ts
show.url(1); // '/posts/1'
show.head(1); // { url: '/posts/1', method: 'head' }
```

When a controller method uses a JavaScript reserved word such as `delete` or `import`, Wayfinder appends `Method` to the generated export name. For example, `delete` becomes `deleteMethod`.

<a name="route-parameters"></a>
### Route Parameters

Wayfinder functions accept scalar, object, and tuple arguments:

```ts
import { show, update } from '@/actions/App/Http/Controllers/PostController';

show(1);
show({ post: 1 });

update([1, 2]);
update({ post: 1, author: 2 });
update({ post: { id: 1 }, author: { id: 2 } });
```

When a route uses a custom binding key, Wayfinder accepts either the key value or an object containing that key:

```php
Route::get('/posts/{post:slug}', [PostController::class, 'show']);
```

```ts
show('my-first-post');
show({ slug: 'my-first-post' });
```

Wayfinder reads supported Eloquent casts, database column metadata, and model PHPDoc to generate the parameter's TypeScript type. Boolean path and query values are rendered as `1` and `0`, matching Hypervel's URL generator.

Optional trailing route parameters may be omitted using `undefined` or `null`. Earlier optional segments may not be skipped when a later segment is supplied, because doing so would change the segment's position:

```ts
archive({ year: 2026, month: undefined });
archive({ year: null, month: null });
```

When a route has a single optional parameter, you may also pass `null` directly:

```ts
optional(null);
```

<a name="invokable-controllers"></a>
### Invokable Controllers

Invokable controllers are exported as directly callable functions:

```ts
import StorePostController from '@/actions/App/Http/Controllers/StorePostController';

StorePostController();
```

<a name="importing-controllers"></a>
### Importing Controllers

You may import an entire generated controller and call its methods on the imported object:

```ts
import PostController from '@/actions/App/Http/Controllers/PostController';

PostController.show(1);
```

Importing named methods is preferred when you want unused controller actions to be removed from the final frontend bundle.

<a name="importing-named-routes"></a>
### Importing Named Routes

Wayfinder also generates functions for named routes:

```ts
import { show } from '@/routes/posts';

show(1); // { url: '/posts/1', method: 'get' }
```

<a name="multiple-routes-to-the-same-action"></a>
### Multiple Routes to the Same Action

When multiple URIs point to the same controller method, the generated action becomes a dictionary keyed by URI:

```php
Route::get('/clients/{client}/payments', [ClientPaymentsController::class, 'index'])
    ->name('clients.payments.index');

Route::get('/clients/{client}/payments/archive', [ClientPaymentsController::class, 'index'])
    ->name('clients.payments.archive');
```

```ts
import { index } from '@/actions/App/Http/Controllers/ClientPaymentsController';

index['/clients/{client}/payments']({ client: 1 });
```

In most cases, importing the named route provides a simpler call:

```ts
import { index } from '@/routes/clients/payments';

index({ client: 1 });
```

When the same action and URI support multiple HTTP methods, Wayfinder produces one route function with a method for each supported verb.

<a name="conventional-forms"></a>
### Conventional Forms

Pass the `--with-form` option to generate helpers for conventional HTML forms:

```shell
php artisan wayfinder:generate --with-form
```

The generated `form` method returns the attributes needed by a form element:

```tsx
import { store, update } from '@/actions/App/Http/Controllers/PostController';

const CreatePost = () => <form {...store.form()} />;
const UpdatePost = () => <form {...update.form(1)} />;
```

For actions that support multiple HTTP methods, you may select a method on the form helper:

```tsx
const UpdatePost = () => <form {...update.form.put(1)} />;
```

<a name="query-parameters"></a>
## Query Parameters

Every Wayfinder function accepts an optional final options argument. The `query` option appends query parameters to the generated URL:

```ts
show.url(1, {
    query: {
        page: 1,
        include_comments: true,
        tags: ['hypervel', 'typescript'],
    },
});
```

The `mergeQuery` option starts with the current browser query string and replaces only the provided parameter families:

```ts
show.url(1, {
    mergeQuery: {
        page: 2,
        sort: null,
    },
});
```

A value of `null` or `undefined` removes that parameter when merging.

<a name="url-defaults"></a>
## URL Defaults

Wayfinder includes scalar defaults configured through `URL::defaults()` when definitions are generated. It also reads defaults declared by route middleware. Static string, integer, float, and boolean values are embedded directly into the generated route functions.

Defaults that depend on frontend state may be configured once when your frontend application boots:

```ts
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ locale: 'en' });
```

You may provide a callback when the value should be read lazily:

```ts
setUrlDefaults(() => ({
    locale: document.documentElement.lang,
}));
```

Explicit route arguments take precedence over frontend defaults.

<a name="server-side-rendering"></a>
### Server-Side Rendering

The `setUrlDefaults` function configures a generated module and remains in effect until it is changed. Configure fixed application defaults during frontend boot, not during each server-side render.

Pass request-specific SSR values directly to each generated function so concurrent renders cannot affect one another:

```ts
show({ post: 1, locale: requestLocale });
```

<a name="wayfinder-and-inertia"></a>
## Wayfinder and Inertia

Inertia accepts a Wayfinder result anywhere it accepts a URL and HTTP method. For example, a Wayfinder function may be passed directly to `useForm`:

```ts
import { useForm } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/PostController';

const form = useForm({ title: 'My First Post' });

form.submit(store());
```

Wayfinder results may also be passed to Inertia links:

```tsx
import { Link } from '@inertiajs/react';
import { show } from '@/actions/App/Http/Controllers/PostController';

const Navigation = () => <Link href={show(1)}>View post</Link>;
```

<a name="url-origins"></a>
## URL Origins

Generated definitions use the path from your configured `app.url` value and preserve explicit route domains. Hypervel's `URL::useOrigin()` method is request-scoped and is not captured as process-global Wayfinder configuration. This keeps concurrent requests isolated while generated frontend URLs remain deterministic.
