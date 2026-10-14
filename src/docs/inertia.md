# Inertia

This guide covers Inertia 3 with Hypervel. Hypervel's [React starter kit](/docs/{{version}}/starter-kits) includes Inertia and is the fastest way to get started. You may also install Inertia into an existing application using the instructions below.

Version labels refer to the Inertia client-side libraries. Features marked with a minimum version require updating your application's client-side adapter to that version or later.

- [How It Works](#how-it-works)
    - [Use the Tools You Love](#how-it-works-use-the-tools-you-love)
    - [Intercepting Requests](#how-it-works-intercepting-requests)
- [Server-Side Setup](#server-side-setup)
    - [Installation](#server-side-setup-installation)
    - [Blade Directives](#server-side-setup-blade-directives)
- [Client-Side Setup](#client-side-setup)
    - [Prerequisites](#client-side-setup-prerequisites)
    - [Installation](#client-side-setup-installation)
    - [Customizing the App](#client-side-setup-customizing-the-app)
    - [AI Assistants and IDE Integrations](#client-side-setup-ai-assistants-and-ide-integrations)
    - [Manual Setup](#client-side-setup-manual-setup)
    - [Configuring Defaults](#client-side-setup-configuring-defaults)
    - [Defining a Root Element](#client-side-setup-defining-a-root-element)
    - [Content Security Policy](#client-side-setup-content-security-policy)
    - [HTTP Client](#client-side-setup-http-client)
- [Pages](#pages)
    - [Creating Pages](#pages-creating-pages)
    - [Transforming Component Names](#pages-transforming-component-names)
    - [Layouts](#pages-layouts)
- [Responses](#responses)
    - [Creating Responses](#responses-creating-responses)
    - [Properties](#responses-properties)
    - [`ProvidesInertiaProperty` Interface](#responses-providesinertiaproperty-interface)
    - [`ProvidesInertiaProperties` Interface](#responses-providesinertiaproperties-interface)
    - [Root Template Data](#responses-root-template-data)
    - [Maximum Response Size](#responses-maximum-response-size)
- [Redirects](#redirects)
    - [303 Response Code](#redirects-303-response-code)
    - [Preserving Fragments](#redirects-preserving-fragments)
    - [External Redirects](#redirects-external-redirects)
    - [Previous URL](#redirects-previous-url)
- [Routing](#routing)
    - [Defining Routes](#routing-defining-routes)
    - [Shorthand Routes](#routing-shorthand-routes)
    - [Generating URLs](#routing-generating-urls)
    - [Customizing the Page URL](#routing-customizing-the-page-url)
- [Title & Meta](#title-and-meta)
    - [Head Component](#title-and-meta-head-component)
    - [Title Shorthand](#title-and-meta-title-shorthand)
    - [Title Callback](#title-and-meta-title-callback)
    - [Multiple Head Instances](#title-and-meta-multiple-head-instances)
    - [Head Extension](#title-and-meta-head-extension)
    - [Server-Side Head Elements](#title-and-meta-server-side-head-elements)
- [Links](#links)
    - [Creating Links](#links-creating-links)
    - [Method](#links-method)
    - [Wayfinder](#links-wayfinder)
    - [Data](#links-data)
    - [Custom Headers](#links-custom-headers)
    - [Browser History](#links-browser-history)
    - [State Preservation](#links-state-preservation)
    - [Scroll Preservation](#links-scroll-preservation)
    - [Partial Reloads](#links-partial-reloads)
    - [View Transitions](#links-view-transitions)
    - [Active States](#links-active-states)
    - [Data Loading Attribute](#links-data-loading-attribute)
- [Manual Visits](#manual-visits)
    - [Method](#manual-visits-method)
    - [Wayfinder](#manual-visits-wayfinder)
    - [Data](#manual-visits-data)
    - [Custom Headers](#manual-visits-custom-headers)
    - [Global Visit Options](#manual-visits-global-visit-options)
    - [File Uploads](#manual-visits-file-uploads)
    - [Browser History](#manual-visits-browser-history)
    - [Client-Side Visits](#manual-visits-client-side-visits)
    - [State Preservation](#manual-visits-state-preservation)
    - [Scroll Preservation](#manual-visits-scroll-preservation)
    - [Partial Reloads](#manual-visits-partial-reloads)
    - [View Transitions](#manual-visits-view-transitions)
    - [Visit Cancellation](#manual-visits-visit-cancellation)
    - [Event Callbacks](#manual-visits-event-callbacks)
- [Instant Visits](#instant-visits)
    - [Basic Usage](#instant-visits-basic-usage)
    - [Shared Props](#instant-visits-shared-props)
    - [Page Props](#instant-visits-page-props)
    - [Disabling Shared Prop Keys](#instant-visits-disabling-shared-prop-keys)
- [Forms](#forms)
    - [Form Component](#forms-form-component)
    - [Form Helper](#forms-form-helper)
    - [Server-Side Responses](#forms-server-side-responses)
    - [Server-Side Validation](#forms-server-side-validation)
    - [Manual Form Submissions](#forms-manual-form-submissions)
    - [File Uploads](#forms-file-uploads)
    - [Optimistic Updates](#forms-optimistic-updates)
    - [Non-Inertia Submissions](#forms-non-inertia-submissions)
- [HTTP Requests](#http-requests)
    - [Basic Usage](#http-requests-basic-usage)
    - [Submitting Data](#http-requests-submitting-data)
    - [Multiple Requests](#http-requests-multiple-requests)
    - [Reactive State](#http-requests-reactive-state)
    - [Validation Errors](#http-requests-validation-errors)
    - [Displaying All Errors](#http-requests-displaying-all-errors)
    - [File Uploads](#http-requests-file-uploads)
    - [Canceling Requests](#http-requests-canceling-requests)
    - [Optimistic Updates](#http-requests-optimistic-updates)
    - [Event Callbacks](#http-requests-event-callbacks)
    - [Precognition](#http-requests-precognition)
    - [History State](#http-requests-history-state)
- [Optimistic Updates](#optimistic-updates)
    - [Router Visits](#optimistic-updates-router-visits)
    - [Form Component](#optimistic-updates-form-component)
    - [Form Helper](#optimistic-updates-form-helper)
    - [HTTP Requests](#optimistic-updates-http-requests)
    - [How It Works](#optimistic-updates-how-it-works)
    - [Inline Option](#optimistic-updates-inline-option)
- [File Uploads](#file-uploads)
    - [`FormData` Conversion](#file-uploads-formdata-conversion)
    - [File Upload Example](#file-uploads-file-upload-example)
    - [Multipart Limitations](#file-uploads-multipart-limitations)
- [Validation](#validation)
    - [How It Works](#validation-how-it-works)
    - [Sharing Errors](#validation-sharing-errors)
    - [Displaying Errors](#validation-displaying-errors)
    - [Repopulating Input](#validation-repopulating-input)
    - [Error Bags](#validation-error-bags)
- [Layouts](#layouts)
    - [Creating Layouts](#layouts-creating-layouts)
    - [Persistent Layouts](#layouts-persistent-layouts)
    - [Default Layouts](#layouts-default-layouts)
    - [Layout Props](#layouts-layout-props)
- [View Transitions](#view-transitions)
    - [Enabling Transitions](#view-transitions-enabling-transitions)
    - [Transition Callbacks](#view-transitions-transition-callbacks)
    - [Links](#view-transitions-links)
    - [Global Configuration](#view-transitions-global-configuration)
    - [Customizing Transitions](#view-transitions-customizing-transitions)
- [Shared Data](#shared-data)
    - [Sharing Data](#shared-data-sharing-data)
    - [Sharing Once Props](#shared-data-sharing-once-props)
    - [Accessing Shared Data](#shared-data-accessing-shared-data)
    - [TypeScript](#shared-data-typescript)
    - [Flash Data](#shared-data-flash-data)
- [Flash Data](#flash-data)
    - [Flashing Data](#flash-data-flashing-data)
    - [Accessing Flash Data](#flash-data-accessing-flash-data)
    - [The onFlash Callback](#flash-data-the-onflash-callback)
    - [Global Flash Event](#flash-data-global-flash-event)
    - [Client-Side Flash](#flash-data-client-side-flash)
    - [TypeScript](#flash-data-typescript)
    - [Testing](#flash-data-testing)
- [Partial Reloads](#partial-reloads)
    - [Only Certain Props](#partial-reloads-only-certain-props)
    - [Except Certain Props](#partial-reloads-except-certain-props)
    - [Router Shorthand](#partial-reloads-router-shorthand)
    - [Using Links](#partial-reloads-using-links)
    - [Lazy Data Evaluation](#partial-reloads-lazy-data-evaluation)
    - [Preserving Errors](#partial-reloads-preserving-errors)
    - [Combining with Once Props](#partial-reloads-combining-with-once-props)
- [Deferred Props](#deferred-props)
    - [Server-Side](#deferred-props-server-side)
    - [Client-Side](#deferred-props-client-side)
    - [Multiple Deferred Props](#deferred-props-multiple-deferred-props)
    - [Reloading Indicator](#deferred-props-reloading-indicator)
    - [Error Handling](#deferred-props-error-handling)
    - [Combining with Once Props](#deferred-props-combining-with-once-props)
- [Merging Props](#merging-props)
    - [Merge Methods](#merging-props-merge-methods)
    - [Matching Items](#merging-props-matching-items)
    - [Deep Merge](#merging-props-deep-merge)
    - [Client-Side Visits](#merging-props-client-side-visits)
    - [Combining with Deferred Props](#merging-props-combining-with-deferred-props)
    - [Combining with Once Props](#merging-props-combining-with-once-props)
    - [Resetting Props](#merging-props-resetting-props)
- [Once Props](#once-props)
    - [Creating Once Props](#once-props-creating-once-props)
    - [Forcing a Refresh](#once-props-forcing-a-refresh)
    - [Refreshing from the Client](#once-props-refreshing-from-the-client)
    - [Expiration](#once-props-expiration)
    - [Custom Keys](#once-props-custom-keys)
    - [Sharing Once Props](#once-props-sharing-once-props)
    - [Conditional Once Props](#once-props-conditional-once-props)
    - [Prefetching](#once-props-prefetching)
    - [Combining with Other Prop Types](#once-props-combining-with-other-prop-types)
- [Polling](#polling)
    - [Poll Helper](#polling-poll-helper)
    - [Concurrency Mode](#polling-concurrency-mode)
    - [Background Tabs](#polling-background-tabs)
- [Prefetching](#prefetching)
    - [Link Prefetching](#prefetching-link-prefetching)
    - [Programmatic Prefetching](#prefetching-programmatic-prefetching)
    - [Cache Tags](#prefetching-cache-tags)
    - [Cache Invalidation](#prefetching-cache-invalidation)
    - [Stale While Revalidate](#prefetching-stale-while-revalidate)
- [Load When Visible](#load-when-visible)
    - [Loading Before Visible](#load-when-visible-loading-before-visible)
    - [Always Trigger](#load-when-visible-always-trigger)
    - [Preserving Errors](#load-when-visible-preserving-errors)
    - [Form Submissions](#load-when-visible-form-submissions)
- [Infinite Scroll](#infinite-scroll)
    - [Server-Side](#infinite-scroll-server-side)
    - [Client-Side](#infinite-scroll-client-side)
    - [Loading Buffer](#infinite-scroll-loading-buffer)
    - [URL Synchronization](#infinite-scroll-url-synchronization)
    - [Resetting](#infinite-scroll-resetting)
    - [Loading Direction](#infinite-scroll-loading-direction)
    - [Reverse Mode](#infinite-scroll-reverse-mode)
    - [Manual Mode](#infinite-scroll-manual-mode)
    - [Slots](#infinite-scroll-slots)
    - [Custom Element](#infinite-scroll-custom-element)
    - [Element Targeting](#infinite-scroll-element-targeting)
    - [Scroll Containers](#infinite-scroll-scroll-containers)
    - [Programmatic Access](#infinite-scroll-programmatic-access)
    - [Inertia::scroll() Method](#infinite-scroll-inertiascroll-method)
- [Remembering State](#remembering-state)
    - [Saving Local State](#remembering-state-saving-local-state)
    - [Multiple Components](#remembering-state-multiple-components)
    - [Form Helper](#remembering-state-form-helper)
    - [Manually Saving State](#remembering-state-manually-saving-state)
- [Authentication](#authentication)
- [Authorization](#authorization)
- [CSRF Protection](#csrf-protection)
    - [Making Requests](#csrf-protection-making-requests)
    - [Handling Mismatches](#csrf-protection-handling-mismatches)
- [History Encryption](#history-encryption)
    - [How It Works](#history-encryption-how-it-works)
    - [Opting in](#history-encryption-opting-in)
    - [Clearing History](#history-encryption-clearing-history)
- [Asset Versioning](#asset-versioning)
    - [Configuration](#asset-versioning-configuration)
    - [Cache Busting](#asset-versioning-cache-busting)
    - [Background Requests](#asset-versioning-background-requests)
    - [Manual Refreshing](#asset-versioning-manual-refreshing)
- [Big Integers](#big-integers)
    - [Enabling Big Integers](#big-integers-enabling-big-integers)
    - [How Values Are Transported](#big-integers-how-values-are-transported)
    - [Objects and Classes](#big-integers-objects-and-classes)
    - [Submitting Big Integers](#big-integers-submitting-big-integers)
    - [Testing](#big-integers-testing)
- [Code Splitting](#code-splitting)
    - [Vite Plugin](#code-splitting-vite-plugin)
    - [Manual Vite](#code-splitting-manual-vite)
- [DevTools](#devtools)
    - [Installation](#devtools-installation)
    - [The Timeline](#devtools-the-timeline)
    - [Inspecting a Request](#devtools-inspecting-a-request)
    - [Client-Side Events](#devtools-client-side-events)
    - [Editor Links](#devtools-editor-links)
    - [How It Works](#devtools-how-it-works)
    - [Enabling DevTools](#devtools-enabling-devtools)
    - [Configuration](#devtools-configuration)
- [Error Handling](#error-handling)
    - [Development](#error-handling-development)
    - [Production](#error-handling-production)
- [Events](#events)
    - [Registering Listeners](#events-registering-listeners)
    - [Removing Listeners](#events-removing-listeners)
    - [One-time Listeners](#events-one-time-listeners)
    - [Canceling Events](#events-canceling-events)
    - [Before](#events-before)
    - [Start](#events-start)
    - [Progress](#events-progress)
    - [Success](#events-success)
    - [Flash](#events-flash)
    - [Error](#events-error)
    - [HTTP Exception](#events-http-exception)
    - [Network Error](#events-network-error)
    - [Finish](#events-finish)
    - [Navigate](#events-navigate)
    - [Location](#events-location)
    - [Prefetching](#events-prefetching)
    - [Prefetched](#events-prefetched)
    - [Event Callbacks](#events-event-callbacks)
- [Progress Indicators](#progress-indicators)
    - [Default](#progress-indicators-default)
    - [Programmatic Access](#progress-indicators-programmatic-access)
    - [Custom](#progress-indicators-custom)
    - [Visit Options](#progress-indicators-visit-options)
- [Scroll Management](#scroll-management)
    - [Scroll Resetting](#scroll-management-scroll-resetting)
    - [Scroll Preservation](#scroll-management-scroll-preservation)
    - [Scroll Regions](#scroll-management-scroll-regions)
    - [Text Fragments](#scroll-management-text-fragments)
- [Server-Side Rendering (SSR)](#server-side-rendering)
    - [Starter Kits](#server-side-rendering-starter-kits)
    - [Vite Plugin Setup](#server-side-rendering-vite-plugin-setup)
    - [Manual Setup](#server-side-rendering-manual-setup)
    - [Running the SSR Server](#server-side-rendering-running-the-ssr-server)
    - [Error Handling](#server-side-rendering-error-handling)
    - [Configuring the SSR Request](#server-side-rendering-configuring-the-ssr-request)
    - [Disabling SSR](#server-side-rendering-disabling-ssr)
    - [Excluding Routes from SSR](#server-side-rendering-excluding-routes-from-ssr)
    - [Client-Only Components](#server-side-rendering-client-only-components)
    - [Deployment](#server-side-rendering-deployment)
- [Testing](#testing)
    - [End-to-end Tests](#testing-end-to-end-tests)
    - [Client-Side Unit Tests](#testing-client-side-unit-tests)
    - [Endpoint Tests](#testing-endpoint-tests)
    - [Disabling SSR During Tests](#testing-disabling-ssr-during-tests)
- [TypeScript](#typescript)
    - [Using pnpm](#typescript-using-pnpm)
    - [Global Configuration](#typescript-global-configuration)
    - [Page Components](#typescript-page-components)
    - [Page Props](#typescript-page-props)
    - [Form Helper](#typescript-form-helper)
    - [Form Component](#typescript-form-component)
    - [HTTP Helper](#typescript-http-helper)
    - [Layout Callbacks](#typescript-layout-callbacks)
    - [Remembering State](#typescript-remembering-state)
    - [Restoring State](#typescript-restoring-state)
    - [Router Requests](#typescript-router-requests)
    - [Scoped Flash Data](#typescript-scoped-flash-data)
    - [Client-Side Visits](#typescript-client-side-visits)

<a name="how-it-works"></a>

## How It Works

<a name="how-it-works-use-the-tools-you-love"></a>

### Use the Tools You Love

With Inertia you build applications just like you've always done with your server-side web framework of choice. You use your framework's existing functionality for routing, controllers, middleware, authentication, authorization, data fetching, and more.

However, Inertia replaces your application's view layer. Instead of using server-side rendering via Blade templates, the views returned by your application are JavaScript page components. This allows you to build your entire front end using React, Vue, or Svelte, while still enjoying the productivity of Hypervel.

<a name="how-it-works-intercepting-requests"></a>

### Intercepting Requests

As you might expect, simply creating your front end in JavaScript doesn't give you a single-page application experience. If you were to click a link, your browser would make a full page visit, which would then cause your client-side framework to reboot on the subsequent page load. This is where Inertia changes everything.

At its core, Inertia is essentially a client-side routing library. It allows you to make page visits without forcing a full page reload. This is done using the `<Link>` component, a light-weight wrapper around a normal anchor link. When you click an Inertia link, Inertia intercepts the click and makes the visit via XHR instead. You can even make these visits programmatically in JavaScript using `router.visit()`.

When Inertia makes an XHR visit, the server detects that it's an Inertia visit and, instead of returning a full HTML response, it returns a JSON response with the JavaScript page component name and data (props). Inertia then dynamically swaps out the previous page component with the new page component and updates the browser's history state.

**The end result is a silky smooth single-page experience.**

To learn more about the nitty-gritty, technical details of how Inertia works under the hood, check out the [protocol page](https://inertiajs.com/docs/v3/core-concepts/the-protocol).

<a name="server-side-setup"></a>

## Server-Side Setup

The first step when installing Inertia is to configure your Hypervel application. Hypervel's [React starter kit](/docs/{{version}}/starter-kits) provides out-of-the-box scaffolding for new Inertia applications. However, if you would like to manually install Inertia into your application, please consult the documentation below.

<a name="server-side-setup-installation"></a>

### Installation

<a name="server-side-setup-install-dependencies"></a>

#### Install dependencies

First, install the Inertia server-side adapter using the Composer package manager.

```bash
composer require hypervel/inertia
```

To customize the adapter's configuration, publish its configuration file:

```shell
php artisan vendor:publish --provider="Hypervel\Inertia\InertiaServiceProvider"
```

<a name="server-side-setup-setup-root-template"></a>

#### Setup root template

Next, create a `resources/views/app.blade.php` file. This root template will be loaded on the first page visit to your application. Inertia provides Blade components for rendering the head and body content.

```blade
<html>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @vite('resources/js/app.js')
        <x-inertia::head />
    </head>
    <body>
        <x-inertia::app />
    </body>
</html>
```

For React applications, it's recommended to include the `@viteReactRefresh` directive before the `@vite` directive to enable Fast Refresh in development. Use `resources/js/app.jsx` in the `@vite` directive when following the React setup below, or `resources/js/app.tsx` for TypeScript. The entry point must match your Vite configuration.

The `<x-inertia::app>` component renders a `<div>` element with an `id` of `app`. This element serves as the mounting point for your JavaScript application. You may customize the `id` by passing a different value to the component.

```blade
<x-inertia::app id="custom-app-id" />
```

If you change the `id` of the root element, be sure to update it [client-side](#client-side-setup-defining-a-root-element) as well.

The `<x-inertia::head>` component also accepts [fallback content](#title-and-meta-ssr-fallback) via its slot for managing head elements when [SSR](#server-side-rendering) is not active.

By default, Inertia's Hypervel adapter will assume your root template is named `app.blade.php`. You may change this using the `$rootView` property on your `HandleInertiaRequests` middleware or the `Inertia::setRootView()` method during a request.

<a name="server-side-setup-register-middleware"></a>

#### Register middleware

Next, set up the Inertia middleware by publishing the `HandleInertiaRequests` middleware to your application with the following Artisan command.

```sh
php artisan inertia:middleware
```

Once the middleware has been published, append the `HandleInertiaRequests` middleware to the `web` middleware group in your application's `bootstrap/app.php` file.

```php
use App\Http\Middleware\HandleInertiaRequests;
use Hypervel\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        HandleInertiaRequests::class,
    ]);
})
```

This middleware provides a `version()` method for setting your [asset version](#asset-versioning), as well as a `share()` method for defining [shared data](#shared-data).

<a name="server-side-setup-create-your-first-response"></a>

#### Create your first response

That's it, you're ready to start creating Inertia [pages](#pages) and rendering them via [responses](#responses).

```php
use Hypervel\Inertia\Inertia;

class EventsController extends Controller
{
    public function show(Event $event)
    {
        return Inertia::render('Event/Show', [
            'event' => $event->only(
                'id',
                'title',
                'start_date',
                'description',
            ),
        ]);
    }
}
```

<a name="server-side-setup-blade-directives"></a>

### Blade Directives

Prior to v3, Inertia used `@inertiaHead` and `@inertia` Blade directives to render head and body content. These directives are still supported but do not offer SSR head [fallback content](#title-and-meta-ssr-fallback). The Blade components above are recommended for new applications.

```blade
<html>
    <head>
        @vite('resources/js/app.js')
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
```

You may customize the root element `id` by passing a value to the directive: `@inertia('custom-app-id')`.

<a name="client-side-setup"></a>

## Client-Side Setup

Once you have your [server-side framework configured](#server-side-setup), you then need to set up your client-side framework. Inertia currently provides support for React, Vue, and Svelte.

Hypervel's [React starter kit](/docs/{{version}}/starter-kits) provides out-of-the-box scaffolding for new Inertia applications. However, if you would like to manually install Inertia into your application, please consult the documentation below.

<a name="client-side-setup-prerequisites"></a>

### Prerequisites

Inertia requires your client-side framework and its corresponding [Vite plugin](/docs/{{version}}/vite#working-with-scripts) to be installed and configured. You may skip this section if your application already has these set up.

```bash tab=Vue
npm install vue @vitejs/plugin-vue
```

```bash tab=React
npm install react react-dom @vitejs/plugin-react
```

```bash tab=Svelte
npm install svelte @sveltejs/vite-plugin-svelte
```

Then, add the framework plugin to your `vite.config.js` file.

```js tab=Vue
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        vue(),
    ],
})
```

```js tab=React
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import react from '@vitejs/plugin-react'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
    ],
})
```

```js tab=Svelte
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import { svelte } from '@sveltejs/vite-plugin-svelte'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        svelte(),
    ],
})
```

For more information on configuring these plugins, consult Hypervel's [Vite documentation](/docs/{{version}}/vite#working-with-scripts). Hypervel uses the `laravel-vite-plugin` package, so its package name and imports remain unchanged.

<a name="client-side-setup-installation"></a>

### Installation

The `@inertiajs/vite` plugin supports Vite 7 and Vite 8.

<a name="client-side-setup-install-dependencies"></a>

#### Install dependencies

Install the Inertia client-side adapter and Vite plugin.

```bash tab=Vue
npm install @inertiajs/vue3 @inertiajs/vite
```

```bash tab=React
npm install @inertiajs/react @inertiajs/vite
```

```bash tab=Svelte
npm install @inertiajs/svelte @inertiajs/vite
```

<a name="client-side-setup-configure-vite"></a>

#### Configure Vite

Add the Inertia plugin to your `vite.config.js` file.

```js
import inertia from '@inertiajs/vite'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        inertia(),
    ],
})
```

<a name="client-side-setup-initialize-the-inertia-app"></a>

#### Initialize the Inertia app

Update your main JavaScript file to boot your Inertia app. The Vite plugin handles page resolution and mounting automatically, so a minimal entry point is all you need.

```js tab=Vue
import { createInertiaApp } from '@inertiajs/vue3'

createInertiaApp()
```

```jsx tab=React
import { createInertiaApp } from '@inertiajs/react'

createInertiaApp()
```

```js tab=Svelte
import { createInertiaApp } from '@inertiajs/svelte'

createInertiaApp()
```

The plugin generates a default resolver that looks for pages in both `./pages` and `./Pages` directories, and the app mounts automatically.

<a name="client-side-setup-react-strict-mode"></a>

#### React Strict Mode

The React adapter supports enabling React's [Strict Mode](https://react.dev/reference/react/StrictMode) via the `strictMode` option.

```jsx
createInertiaApp({
    strictMode: true,
    // ...
})
```

<a name="client-side-setup-pages-shorthand"></a>

#### Pages Shorthand

You may use the `pages` shorthand to customize which directory to search for page components.

```js tab=Vue
createInertiaApp({
    pages: './AppPages',
    // ...
})
```

```jsx tab=React
createInertiaApp({
    pages: './AppPages',
    // ...
})
```

```js tab=Svelte
createInertiaApp({
    pages: './AppPages',
    // ...
})
```

An object may also be provided for more control over how pages are resolved. You only need to include the options you wish to customize.

```js
createInertiaApp({
    pages: {
        path: './Pages',
        extension: '.tsx',
        lazy: true,
        transform: (name, page) => name.replace('/', '-'),
    },
})
```

| Option      | Description                                                                                                          |
| ----------- | -------------------------------------------------------------------------------------------------------------------- |
| `path`      | The directory to search for page components.                                                                         |
| `extension` | A string or array of file extensions (e.g., `'.tsx'` or `['.tsx', '.jsx']`). Defaults to your framework's extension. |
| `lazy`      | Whether to lazy-load page components. Defaults to `true`. See [code splitting](#code-splitting).                     |
| `transform` | A callback that receives the page name and page object, returning a transformed name.                                |

<a name="client-side-setup-customizing-the-app"></a>

### Customizing the App

Sometimes, you may wish to customize the app instance, for example, to register plugins, wrap it with providers, or set context values. Pass the `withApp` callback to `createInertiaApp` to customize the app before it renders.

```js tab=Vue
import { createInertiaApp } from '@inertiajs/vue3'
import { createI18n } from 'vue-i18n'

const i18n = createI18n({
    // ...
})

createInertiaApp({
    withApp(app) {
        app.use(i18n)
    },
})
```

```jsx tab=React
import { createInertiaApp } from '@inertiajs/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

const queryClient = new QueryClient()

createInertiaApp({
    withApp(app) {
        return (
            <QueryClientProvider client={queryClient}>
                {app}
            </QueryClientProvider>
        )
    },
})
```

```js tab=Svelte
import { createInertiaApp } from '@inertiajs/svelte'

createInertiaApp({
    withApp(context) {
        context.set('theme', 'dark')
    },
})
```

**Vue:**
The callback receives the Vue app instance, allowing you to call `app.use()`, `app.provide()`, `app.component()`, and any other app-level method.

**React:**
The callback receives the React element and must return a new element. This is where you may wrap your app with context providers.

**Svelte:**
The callback receives a `Map` that serves as Svelte's component context. Values set here are accessible in your components via `getContext()`.

A second argument provides the current rendering environment via `ssr`, allowing you to conditionally apply logic based on where the app is running.

```js tab=Vue
createInertiaApp({
    withApp(app, { ssr }) {
        app.use(i18n)

        if (!ssr) {
            app.use(browserOnlyPlugin)
        }
    },
})
```

```jsx tab=React
createInertiaApp({
    withApp(app, { ssr }) {
        if (!ssr) {
            return <BrowserProvider>{app}</BrowserProvider>
        }

        return app
    },
})
```

```js tab=Svelte
createInertiaApp({
    withApp(context, { ssr }) {
        context.set('theme', 'dark')

        if (!ssr) {
            context.set('analytics', createAnalytics())
        }
    },
})
```

The second argument also includes the current `page` object, giving you access to the component name, URL, version, and shared props before the app renders. This is useful for configuring plugins or providers with server-driven state, such as the user's locale.

```js tab=Vue
createInertiaApp({
    withApp(app, { page }) {
        const i18n = createI18n({ locale: page.props.locale })

        app.use(i18n)
    },
})
```

```jsx tab=React
createInertiaApp({
    withApp(app, { page }) {
        const i18n = createI18n({ locale: page.props.locale })

        return <I18nProvider i18n={i18n}>{app}</I18nProvider>
    },
})
```

```js tab=Svelte
createInertiaApp({
    withApp(context, { page }) {
        context.set('locale', page.props.locale)
    },
})
```

<a name="client-side-setup-ai-assistants-and-ide-integrations"></a>

### AI Assistants and IDE Integrations

AI assistants and IDE integrations often write state files into your project root. With `laravel-vite-plugin`'s `refresh: true` enabled, these writes may trigger unexpected full page reloads, since Vite's watcher only ignores `node_modules` and `.git` by default.

You may extend Vite's `server.watch.ignored` option to exclude these paths.

```js
export default defineConfig({
    server: {
        watch: {
            ignored: [
                '**/.junie/**',
                '**/.cursor/**',
                '**/.claude/**',
            ],
        },
    },
    // ...
})
```

Adjust the list to match your installed tools. See Vite's [server.watch](https://vite.dev/config/server-options.html#server-watch) documentation for additional options.

<a name="client-side-setup-manual-setup"></a>

### Manual Setup

If you prefer not to use the Vite plugin, you may provide the `resolve` and `setup` callbacks manually. The `resolve` callback tells Inertia how to load a page component and receives the component name and the full [page object](https://inertiajs.com/docs/v3/core-concepts/the-protocol). The `setup` callback initializes the client-side framework.

> [!NOTE]
> A manual `setup` callback prevents the Vite plugin from auto-generating [SSR](#server-side-rendering) handling. You should create a [separate SSR entry point](#server-side-rendering-ssr-entry-point) and update your app to use [client-side hydration](#server-side-rendering-client-side-hydration).

```js tab=Vue
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.vue')
        return pages[`./Pages/${name}.vue`]()
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },
})
```

```jsx tab=React
import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.jsx')
        return pages[`./Pages/${name}.jsx`]()
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />)
    },
})
```

```js tab=Svelte
import { createInertiaApp } from '@inertiajs/svelte'
import { mount } from 'svelte'

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.svelte')
        return pages[`./Pages/${name}.svelte`]()
    },
    setup({ el, App, props }) {
        mount(App, { target: el, props })
    },
})
```

By default, page components are lazy-loaded, splitting each page into its own bundle. To eagerly bundle all pages into a single file instead, see the [code splitting](#code-splitting) documentation.

The `laravel-vite-plugin` package also provides a [`resolvePageComponent`](/docs/{{version}}/vite#inertia) helper that may be used to resolve page components.

```js tab=Vue
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'

resolve: name => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
```

```js tab=React
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'

resolve: name => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
```

```js tab=Svelte
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'

resolve: name => resolvePageComponent(`./Pages/${name}.svelte`, import.meta.glob('./Pages/**/*.svelte')),
```

<a name="client-side-setup-configuring-defaults"></a>

### Configuring Defaults

You may pass a `defaults` object to `createInertiaApp()` to configure default settings for various features. You don't have to pass a default for every key, just the ones you want to tweak.

```js
createInertiaApp({
  defaults: {
    form: {
      recentlySuccessfulDuration: 5000,
    },
    prefetch: {
      cacheFor: "1m",
      hoverDelay: 150,
    },
    visitOptions: (href, options) => {
      return {
        headers: {
          ...options.headers,
          "X-Custom-Header": "value",
        },
      };
    },
  },
  // ...
});
```

The `visitOptions` callback receives the target URL and the current visit options, and should return an object with any options you want to override. For more details on the available configuration options, see the [forms](#forms-form-errors), [prefetching](#prefetching), and [manual visits](#manual-visits-global-visit-options) documentation.

<a name="client-side-setup-updating-configuration-at-runtime"></a>

#### Updating Configuration at Runtime

You may also update configuration values at runtime using the exported `config` instance. This is particularly useful when you need to adjust settings based on user preferences or application state.

```js tab=Vue
import { config } from "@inertiajs/vue3";

// Set a single value using dot notation...
config.set("form.recentlySuccessfulDuration", 1000);
config.set("prefetch.cacheFor", "5m");

// Set multiple values at once...
config.set({
  "form.recentlySuccessfulDuration": 1000,
  "prefetch.cacheFor": "5m",
});
```

```js tab=React
import { config } from "@inertiajs/react";

// Set a single value using dot notation...
config.set("form.recentlySuccessfulDuration", 1000);
config.set("prefetch.cacheFor", "5m");

// Set multiple values at once...
config.set({
  "form.recentlySuccessfulDuration": 1000,
  "prefetch.cacheFor": "5m",
});

// Get a configuration value...
const duration = config.get("form.recentlySuccessfulDuration");
```

```js tab=Svelte
import { config } from "@inertiajs/svelte";

// Set a single value using dot notation...
config.set("form.recentlySuccessfulDuration", 1000);
config.set("prefetch.cacheFor", "5m");

// Set multiple values at once...
config.set({
  "form.recentlySuccessfulDuration": 1000,
  "prefetch.cacheFor": "5m",
});

// Get a configuration value...
const duration = config.get("form.recentlySuccessfulDuration");
```

<a name="client-side-setup-defining-a-root-element"></a>

### Defining a Root Element

By default, Inertia assumes that your application's root template has a root element with an `id` of `app`. If your application's root element has a different `id`, you can provide it using the `id` property.

```js
createInertiaApp({
  id: "my-app",
  // ...
});
```

If you change the `id` of the root element, be sure to update it [server-side](#server-side-setup-setup-root-template) as well.

<a name="client-side-setup-content-security-policy"></a>

### Content Security Policy

For applications using a [Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP) that restricts inline styles, you may pass a `nonce` to `createInertiaApp()`. The nonce is applied to the inline styles Inertia injects for the [progress bar](#progress-indicators) and the [error modal](#error-handling), allowing them through your CSP.

```js
createInertiaApp({
  nonce: "your-csp-nonce",
  // ...
});
```

<a name="client-side-setup-http-client"></a>

### HTTP Client

Unlike Inertia 2 and earlier, Inertia 3 uses a built-in XHR client for all requests. No additional HTTP libraries like Axios are required.

<a name="client-side-setup-using-axios"></a>

#### Using Axios

You may provide the `axiosAdapter` as the `http` option when creating your Inertia app. This is useful when your application requires a custom Axios instance.

```js
import { axiosAdapter } from '@inertiajs/core'

createInertiaApp({
  http: axiosAdapter(),
  // ...
})
```

A custom Axios instance may also be provided to the adapter.

```js
import axios from 'axios'
import { axiosAdapter } from '@inertiajs/core'

const instance = axios.create({
  // ...
})

createInertiaApp({
  http: axiosAdapter(instance),
  // ...
})
```

<a name="client-side-setup-interceptors"></a>

#### Interceptors

The built-in XHR client supports interceptors for modifying requests, inspecting responses, or handling errors. These interceptors apply to all HTTP requests made by Inertia, including those from the router, `useForm`, `<Form>`, and `useHttp`.

```js tab=Vue
import { http } from '@inertiajs/vue3'

const removeRequestHandler = http.onRequest((config) => {
  config.headers['X-Custom-Header'] = 'value'
  return config
})

const removeResponseHandler = http.onResponse((response) => {
  console.log('Response status:', response.status)
  return response
})

const removeErrorHandler = http.onError((error) => {
  console.error('Request failed:', error)
})

// Remove a handler when it's no longer needed...
removeRequestHandler()
```

```js tab=React
import { http } from '@inertiajs/react'

const removeRequestHandler = http.onRequest((config) => {
  config.headers['X-Custom-Header'] = 'value'
  return config
})

const removeResponseHandler = http.onResponse((response) => {
  console.log('Response status:', response.status)
  return response
})

const removeErrorHandler = http.onError((error) => {
  console.error('Request failed:', error)
})

// Remove a handler when it's no longer needed...
removeRequestHandler()
```

```js tab=Svelte
import { http } from '@inertiajs/svelte'

const removeRequestHandler = http.onRequest((config) => {
  config.headers['X-Custom-Header'] = 'value'
  return config
})

const removeResponseHandler = http.onResponse((response) => {
  console.log('Response status:', response.status)
  return response
})

const removeErrorHandler = http.onError((error) => {
  console.error('Request failed:', error)
})

// Remove a handler when it's no longer needed...
removeRequestHandler()
```

Each `on*` method returns a cleanup function that removes the handler when called. Request handlers receive the request config and must return it (modified or not). Response handlers receive the response and must also return it. Handlers may be asynchronous.

<a name="client-side-setup-custom-http-client"></a>

#### Custom HTTP Client

For full control over how requests are made, you may provide a completely custom HTTP client via the `http` option. A custom client must implement the `request` method, which receives an `HttpRequestConfig` and returns a promise resolving to an `HttpResponse`. Review the [xhrHttpClient.ts](https://github.com/inertiajs/inertia/blob/3.x/packages/core/src/xhrHttpClient.ts) source for a reference implementation.

<a name="pages"></a>

## Pages

When building applications using Inertia, each page in your application typically has its own controller or route and a corresponding JavaScript component. This allows you to retrieve just the data necessary for that page - no API required.

In addition, all of the data needed for the page can be retrieved before the page is ever rendered by the browser, eliminating the need for displaying "loading" states when users visit your application.

<a name="pages-creating-pages"></a>

### Creating Pages

Inertia pages are simply JavaScript components. If you have ever written a Vue, React, or Svelte component, you will feel right at home. As you can see in the example below, pages receive data from your application's controllers as props.

```vue tab=Vue
<script setup>
import Layout from "./Layout";
import { Head } from "@inertiajs/vue3";

defineProps({ user: Object });
</script>

<template>
  <Layout>
    <Head title="Welcome" />
    <h1>Welcome</h1>
    <p>Hello {{ user.name }}, welcome to your first Inertia app!</p>
  </Layout>
</template>
```

```jsx tab=React
import Layout from "./Layout";
import { Head } from "@inertiajs/react";

export default function Welcome({ user }) {
  return (
    <Layout>
      <Head title="Welcome" />
      <h1>Welcome</h1>
      <p>Hello {user.name}, welcome to your first Inertia app!</p>
    </Layout>
  );
}
```

```svelte tab=Svelte
<script>
    import Layout from './Layout.svelte'

    let { user } = $props()
</script>

<svelte:head>
    <title>Welcome</title>
</svelte:head>

<Layout>
    <h1>Welcome</h1>
    <p>Hello {user.name}, welcome to your first Inertia app!</p>
</Layout>
```

Given the page above, you can render the page by returning an [Inertia response](#responses) from a controller or route. In this example, let's assume this page is stored at `resources/js/pages/User/Show.vue`, `resources/js/pages/User/Show.jsx`, or `resources/js/pages/User/Show.svelte` within a Hypervel application.

```php
use Hypervel\Inertia\Inertia;

class UserController extends Controller
{
    public function show(User $user)
    {
        return Inertia::render('User/Show', [
            'user' => $user
        ]);
    }
}
```

If you attempt to render a page that does not exist, the response will typically be a blank screen. To prevent this, you may set the `inertia.pages.ensure_pages_exist` configuration option to `true`. The Hypervel adapter will then throw a `Hypervel\Inertia\ComponentNotFoundException` when a page cannot be found. The `inertia.pages.paths` and `inertia.pages.extensions` options configure where to look for pages; the default path is `resources/js/pages`. If you use `resources/js/Pages` instead, update the configured path to match.

<a name="pages-transforming-component-names"></a>

### Transforming Component Names

Sometimes you may wish to transform component names. This may be done on the client side using the [`pages.transform`](#client-side-setup-pages-shorthand) option, or on the server using the `Inertia::transformComponentUsing()` method. The transformed name is sent to the browser and used for `ensure_pages_exist` checks.

```php
use Hypervel\Inertia\Inertia;

Inertia::transformComponentUsing(function (string $name): string {
    return "{$name}/Page";
});
```

<a name="pages-layouts"></a>

### Layouts

Most applications share common UI elements across pages. Inertia provides persistent layouts that survive page navigations, along with layout props for passing dynamic data between pages and their layouts. Visit the [layouts documentation](#layouts) to learn more.

<a name="responses"></a>

## Responses

<a name="responses-creating-responses"></a>

### Creating Responses

Creating an Inertia response is simple. To get started, invoke the `Inertia::render()` method within your controller or route, providing both the name of the [JavaScript page component](#pages) that you wish to render, as well as any properties (data) for the page.

In the example below, we will pass a single property (`event`) which contains four attributes (`id`, `title`, `start_date` and `description`) to the `Event/Show` page component.

```php
use Hypervel\Inertia\Inertia;

class EventsController extends Controller
{
    public function show(Event $event)
    {
        return Inertia::render('Event/Show', [
            'event' => $event->only(
                'id',
                'title',
                'start_date',
                'description'
            ),
        ]);

        // Alternatively, you can use the inertia() helper...
        return inertia('Event/Show', [
            'event' => $event->only(
                'id',
                'title',
                'start_date',
                'description'
            ),
        ]);
    }
}
```

The component name may also be a Backed Enum, which is useful for organizing page components with type-safe references.

```php
enum Page: string
{
    case EventShow = 'Event/Show';
    case EventIndex = 'Event/Index';
}

return Inertia::render(Page::EventShow, [
    'event' => $event,
]);
```

> [!NOTE]
> To ensure that pages load quickly, only return the minimum data required for
> the page.

> [!NOTE]
> Be aware that all data returned from the controllers will be visible
> client-side, so be sure to omit sensitive information.

<a name="responses-properties"></a>

### Properties

To pass data from the server to your page components, you can use properties. You can pass various types of values as props, including primitive types, arrays, objects, and several Hypervel-specific types that are automatically resolved:

```php
use App\Models\User;
use App\Http\Resources\UserResource;
use Hypervel\Inertia\Inertia;

Inertia::render('Dashboard', [
    // Primitive values
    'title' => 'Dashboard',
    'count' => 42,
    'active' => true,

    // Arrays and objects
    'settings' => ['theme' => 'dark', 'notifications' => true],

    // Arrayable objects (Collections, Models, etc.)
    'user' => auth()->user(), // Eloquent model
    'users' => User::all(), // Eloquent collection

    // API Resources
    'profile' => new UserResource(auth()->user()),

    // Closures
    'timestamp' => fn() => now()->timestamp,
]);
```

Arrayable objects like Eloquent models and collections are automatically converted using their `toArray()` method. Responsable objects like API resources are resolved through their `toResponse()` method.

Integers outside JavaScript's safe range lose precision in the browser. You may preserve them as native `BigInt` values using [big integer support](#big-integers).

<a name="responses-providesinertiaproperty-interface"></a>

### `ProvidesInertiaProperty` Interface

When passing props to your components, you may want to create custom classes that can transform themselves into the appropriate data format. While Hypervel's `Arrayable` interface simply converts objects to arrays, Inertia offers the more powerful `ProvidesInertiaProperty` interface for context-aware transformations.

This interface requires a `toInertiaProperty` method that receives a `PropertyContext` object containing the property key (`$context->key`), all props for the page (`$context->props`), and the request instance (`$context->request`).

```php
use Hypervel\Inertia\PropertyContext;
use Hypervel\Inertia\ProvidesInertiaProperty;
use Hypervel\Support\Facades\Storage;

class UserAvatar implements ProvidesInertiaProperty
{
    public function __construct(protected User $user, protected int $size = 64)
    {
        //
    }

    public function toInertiaProperty(PropertyContext $context): mixed
    {
        return $this->user->avatar
            ? Storage::url($this->user->avatar)
            : "https://ui-avatars.com/api/?name={$this->user->name}&size={$this->size}";
    }
}
```

Once defined, you can use this class directly as a prop value.

```php
Inertia::render('Profile', [
    'user' => $user,
    'avatar' => new UserAvatar($user, 128),
]);
```

The `PropertyContext` gives you access to the property key, which enables powerful patterns like merging with shared data.

```php
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\PropertyContext;
use Hypervel\Inertia\ProvidesInertiaProperty;

class MergeWithShared implements ProvidesInertiaProperty
{
    public function __construct(protected array $items = [])
    {
        //
    }

    public function toInertiaProperty(PropertyContext $context): mixed
    {
        // Access the property key to get shared data
        $shared = Inertia::getShared($context->key, []);

        // Merge with the new items
        return array_merge($shared, $this->items);
    }
}

// Usage
Inertia::share('notifications', ['Welcome back!']);

return Inertia::render('Dashboard', [
    'notifications' => new MergeWithShared(['New message received']),
    // Result: ['Welcome back!', 'New message received']
]);
```

<a name="responses-providesinertiaproperties-interface"></a>

### `ProvidesInertiaProperties` Interface

In some situations you may want to group related props together for reusability across different pages. You can accomplish this by implementing the `ProvidesInertiaProperties` interface.

This interface requires a `toInertiaProperties` method that returns an array of key-value pairs. The method receives a `RenderContext` object containing the component name (`$context->component`) and request instance (`$context->request`).

```php
use App\Models\User;
use Hypervel\Container\Attributes\CurrentUser;
use Hypervel\Inertia\RenderContext;
use Hypervel\Inertia\ProvidesInertiaProperties;

class UserPermissions implements ProvidesInertiaProperties
{
    public function __construct(#[CurrentUser] protected User $user)
    {
        //
    }

    public function toInertiaProperties(RenderContext $context): array
    {
        return [
            'canEdit' => $this->user->can('edit'),
            'canDelete' => $this->user->can('delete'),
            'canPublish' => $this->user->can('publish'),
            'isAdmin' => $this->user->hasRole('admin'),
        ];
    }
}
```

You can use these prop classes directly in the `render()` and `with()` methods.

```php
public function index(UserPermissions $permissions)
{
    return Inertia::render('UserProfile', $permissions);

    // or...

    return Inertia::render('UserProfile')->with($permissions);
}
```

You can also combine multiple prop classes with other props in an array:

```php
public function index(UserPermissions $permissions)
{
    return Inertia::render('UserProfile', [
        'user' => auth()->user(),
        $permissions,
    ]);

    // or using method chaining...

    return Inertia::render('UserProfile')
        ->with('user', auth()->user())
        ->with($permissions);
}
```

<a name="responses-root-template-data"></a>

### Root Template Data

There are situations where you may want to access your prop data in your application's root Blade template. For example, you may want to add a meta description tag, Twitter card meta tags, or Facebook Open Graph meta tags. You can access this data via the `$page` variable.

```blade
<meta name="twitter:title" content="{{ $page['props']['event']['title'] }}">
```

Sometimes you may even want to provide data to the root template that will not be sent to your JavaScript page component. This can be accomplished by invoking the `withViewData` method.

```php
return Inertia::render('Event', ['event' => $event])
    ->withViewData(['meta' => $event->meta]);
```

After invoking the `withViewData` method, you can access the defined data as you would typically access a Blade template variable.

```blade
<meta name="description" content="{{ $meta }}">
```

<a name="responses-maximum-response-size"></a>

### Maximum Response Size

To enable client-side history navigation, all Inertia server responses are stored in the browser's history state. However, keep in mind that some browsers impose a size limit on how much data can be saved within the history state.

For example, [Firefox](https://developer.mozilla.org/en-US/docs/Web/API/History/pushState) has a size limit of 16 MiB and throws a `NS_ERROR_ILLEGAL_VALUE` error if you exceed this limit. Typically, this is much more data than you'll ever practically need when building applications.

<a name="redirects"></a>

## Redirects

When making a non-GET Inertia request manually or via a `<Link>` element, you should ensure that you always respond with a proper Inertia redirect response.

For example, if your controller is creating a new user, your "store" endpoint should return a redirect back to a standard `GET` endpoint, such as your user "index" page. Inertia will automatically follow this redirect and update the page accordingly.

```php
class UsersController extends Controller
{
    public function index()
    {
        return Inertia::render('Users/Index', [
            'users' => User::all(),
        ]);
    }

    public function store(Request $request)
    {
        User::create(
            $request->validate([
                'name' => ['required', 'max:50'],
                'email' => ['required', 'max:50', 'email'],
            ])
        );

        return to_route('users.index');
    }
}
```

<a name="redirects-303-response-code"></a>

### 303 Response Code

When redirecting after a `PUT`, `PATCH`, or `DELETE` request, you must use a `303` response code, otherwise the subsequent request will not be treated as a `GET` request. A `303` redirect is very similar to a `302` redirect; however, the follow-up request is explicitly changed to a `GET` request.

Hypervel's Inertia adapter automatically converts `302` redirects after `PUT`, `PATCH`, and `DELETE` requests to `303` redirects.

<a name="redirects-preserving-fragments"></a>

### Preserving Fragments

Sometimes a user may visit a URL with a fragment, such as `/article/old-slug#section`, and the server needs to redirect to a different URL. The fragment from the original request is normally lost during the redirect.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/article/old-slug#section">View section</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/article/old-slug#section">View section</Link>
```

```svelte tab=Svelte
import { Link } from '@inertiajs/svelte'

<Link href="/article/old-slug#section">View section</Link>
```

You may preserve the fragment by chaining the `preserveFragment` method on the redirect response. The client will carry over the `#section` fragment to the redirect target, resulting in `/article/new-slug#section`.

```php
return redirect('/article/new-slug')->preserveFragment();
```

<a name="redirects-external-redirects"></a>

### External Redirects

Sometimes it's necessary to redirect to an external website, or even another non-Inertia endpoint in your app while handling an Inertia request. This can be accomplished using a server-side initiated `window.location` visit via the `Inertia::location()` method.

```php
return Inertia::location($url);
```

The `Inertia::location()` method will generate a `409 Conflict` response and include the destination URL in the `X-Inertia-Location` header. When this response is received client-side, Inertia will automatically perform a `window.location = url` visit.

<a name="redirects-previous-url"></a>

### Previous URL

Sometimes you may wish to know which page the user navigated from. Hypervel's session middleware doesn't store the previous URL and route for Inertia visits, since they are sent as AJAX requests, so `session()->previousUrl()`, `session()->previousUri()`, and `session()->previousRoute()` return the last full page load. The `back()` helper is unaffected, as it resolves the previous location from the request's `Referer` header. You may enable the `store_previous_url` option in your `config/inertia.php` configuration file to store the previous location for Inertia visits as well.

```php
return [
    'store_previous_url' => true,
];
```

Prefetch requests and [partial reloads](#partial-reloads) are excluded, since deferred props, polling, and infinite scroll requests aren't navigations the user came from. Overriding the `shouldStoreCurrentUrl()` method in your `HandleInertiaRequests` middleware allows you to customize which visits are stored.

```php
use Hypervel\Http\Request;
use Hypervel\Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    public function shouldStoreCurrentUrl(Request $request, Response $response): bool
    {
        return parent::shouldStoreCurrentUrl($request, $response)
            && ! $request->routeIs('admin.*');
    }
}
```

<a name="routing"></a>

## Routing

<a name="routing-defining-routes"></a>

### Defining Routes

When using Inertia, all of your application's routes are defined server-side. This means that you don't need Vue Router or React Router. Instead, you can simply define Hypervel routes and return [Inertia responses](#responses) from those routes.

<a name="routing-shorthand-routes"></a>

### Shorthand Routes

If you have a [page](#pages) that doesn't need a corresponding controller method, like an "FAQ" or "about" page, you can route directly to a component via the `Route::inertia()` method.

```php
Route::inertia('/about', 'About');
```

<a name="routing-generating-urls"></a>

### Generating URLs

Some server-side frameworks allow you to generate URLs from named routes. However, you will not have access to those helpers client-side. Here are a couple ways to still use named routes with Inertia.

The first option is to generate URLs server-side and include them as props. Notice in this example how we're passing the `edit_url` and `create_url` to the `Users/Index` component.

```php
class UsersController extends Controller
{
    public function index()
    {
        return Inertia::render('Users/Index', [
            'users' => User::all()->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'edit_url' => route('users.edit', $user),
            ]),
            'create_url' => route('users.create'),
        ]);
    }
}
```

You may also use Wayfinder to make your server-side routes available to your client-side code.

<a name="routing-wayfinder"></a>

#### Wayfinder

When using [Wayfinder](/docs/{{version}}/wayfinder), you can pass the generated TypeScript method directly to the [Link component](#links-wayfinder), [form helpers](#forms-wayfinder), or [router methods](#manual-visits-wayfinder) and Inertia understands how to handle it. In fact, if you are developing an application using Hypervel's [React starter kit](/docs/{{version}}/starter-kits), Wayfinder is already configured for you.

<a name="routing-customizing-the-page-url"></a>

### Customizing the Page URL

The page object includes a `url` that represents the current page's URL. By default, the Hypervel adapter resolves this using the `fullUrl()` method on the `Request` instance, but strips the scheme and host so the result is a relative URL.

If you need to customize how the URL is resolved, you may provide a resolver within the `urlResolver` method of the Inertia `HandleInertiaRequests` middleware.

```php
class HandleInertiaRequests extends Middleware
{
    public function urlResolver(): ?Closure
    {
        return function (Request $request) {
            // Return the URL for the request...
        };
    }
}
```

Alternatively, you may define the resolver using the `Inertia::resolveUrlUsing()` method.

```php
Inertia::resolveUrlUsing(function (Request $request) {
    // Return the URL for the request...
});
```

<a name="title-and-meta"></a>

## Title & Meta

Since Inertia-powered JavaScript apps are rendered within the document `<body>`, they are unable to render markup to the document `<head>`, as it's outside of their scope. To help with this, Inertia ships with a `<Head>` component which can be used to set the page `<title>`, `<meta>` tags, and other `<head>` elements.

The `<Head>` component will only replace `<head>` elements that are not in your server-side root template.

The `<Head>` component is not available in the Svelte adapter, as Svelte already ships with its own `<svelte:head>` component.

<a name="title-and-meta-head-component"></a>

### Head Component

To add `<head>` elements to your page, use the `<Head>` component. Within this component, you can include the elements that you wish to add to the document `<head>`.

```vue tab=Vue
import { Head } from '@inertiajs/vue3'

<Head>
    <title>Your page title</title>
    <meta name="description" content="Your page description">
</Head>
```

```jsx tab=React
import { Head } from "@inertiajs/react";

<Head>
  <title>Your page title</title>
  <meta name="description" content="Your page description" />
</Head>;
```

```svelte tab=Svelte
<svelte:head>
    <title>Your page title</title>
    <meta name="description" content="Your page description" />
</svelte:head>
```

<a name="title-and-meta-title-shorthand"></a>

### Title Shorthand

If you only need to add a `<title>` to the document `<head>`, you may simply pass the title as a prop to the `<Head>` component.

```vue tab=Vue
import { Head } from '@inertiajs/vue3'

<Head title="Your page title" />
```

```jsx tab=React
import { Head } from "@inertiajs/react";

<Head title="Your page title" />;
```

```js tab=Svelte
// Not supported
```

<a name="title-and-meta-title-callback"></a>

### Title Callback

You can globally modify the page `<title>` using the `title` callback in the `createInertiaApp` setup method. Typically, this method is invoked in your application's main JavaScript file. A common use case for the title callback is automatically adding an app name before or after each page title.

```js
createInertiaApp({
  title: (title) => `${title} - My App`,
  // ...
});
```

The callback also receives the current [page object](#pages) as its second argument, which you may use to build titles from the page's props.

```js
createInertiaApp({
  title: (title, page) => `${title} - ${page.props.appName}`,
  // ...
});
```

After defining the `title` callback, the callback will automatically be invoked when you set a title using the `<Head>` component.

```vue tab=Vue
import { Head } from '@inertiajs/vue3'

<Head title="Home">
```

```jsx tab=React
import { Head } from '@inertiajs/react'

<Head title="Home">
```

```js tab=Svelte
// Not supported
```

In this example, this results in the following `<title>` tag.

```html
<title>Home - My App</title>
```

The `title` callback will also be invoked when you set the title using a `<title>` tag within your `<Head>` component.

```vue tab=Vue
import { Head } from '@inertiajs/vue3'

<Head>
    <title>Home</title>
</Head>
```

```jsx tab=React
import { Head } from "@inertiajs/react";

<Head>
  <title>Home</title>
</Head>;
```

```js tab=Svelte
// Not supported
```

<a name="title-and-meta-multiple-head-instances"></a>

### Multiple Head Instances

It's possible to have multiple instances of the `<Head>` component throughout your application. For example, your layout can set some default `<Head>` elements, and then your individual pages can override those defaults.

```vue tab=Vue
// Layout.vue import { Head } from '@inertiajs/vue3'

<Head>
    <title>My app</title>
    <meta head-key="description" name="description" content="This is the default description" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
</Head>

// About.vue import { Head } from '@inertiajs/vue3'

<Head>
    <title>About - My app</title>
    <meta head-key="description" name="description" content="This is a page specific description" />
</Head>
```

```jsx tab=React
// Layout.js

import { Head } from "@inertiajs/react";

<Head>
  <title>My app</title>
  <meta
    head-key="description"
    name="description"
    content="This is the default description"
  />
  <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
</Head>;

// About.js

import { Head } from "@inertiajs/react";

<Head>
  <title>About - My app</title>
  <meta
    head-key="description"
    name="description"
    content="This is a page specific description"
  />
</Head>;
```

```js tab=Svelte
// Not supported
```

Inertia will only ever render one `<title>` tag; however, all other tags will be stacked since it's valid to have multiple instances of them. To avoid duplicate tags in your `<head>`, you can use the `head-key` property, which will make sure the tag is only rendered once. This is illustrated in the example above for the `<meta name="description">` tag.

The code example above will render the following HTML.

```html
<head>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
  <title>About - My app</title>
  <meta name="description" content="This is a page specific description" />
</head>
```

<a name="title-and-meta-head-extension"></a>

### Head Extension

When building a real application, it can sometimes be helpful to create a custom head component that extends Inertia's `<Head>` component. This gives you a place to set app-wide defaults, such as appending the app name to the page title.

```vue tab=Vue
<!-- AppHead.vue -->

<script setup>
import { Head } from "@inertiajs/vue3";

defineProps({ title: String });
</script>

<template>
  <Head :title="title ? `${title} - My App` : 'My App'">
    <slot />
  </Head>
</template>
```

```jsx tab=React
// AppHead.js

import { Head } from "@inertiajs/react";

const Site = ({ title, children }) => {
  return (
    <Head>
      <title>{title ? `${title} - My App` : "My App"}</title>
      {children}
    </Head>
  );
};

export default Site;
```

```js tab=Svelte
// Not supported
```

Once you have created the custom component, you can just start using it in your pages.

```vue tab=Vue
import AppHead from './AppHead'

<AppHead title="About" />
```

```jsx tab=React
import AppHead from './AppHead'

<AppHead title="About">
```

```js tab=Svelte
// Not supported
```

<a name="title-and-meta-server-side-head-elements"></a>

### Server-Side Head Elements

You may wish to include default head elements like `<title>` or `<meta>` tags in your root Blade template. This creates two challenges: preventing duplicates when [SSR](#server-side-rendering) is active, and ensuring the client-side `<Head>` component may replace them during navigation.

<a name="title-and-meta-ssr-fallback"></a>

#### SSR Fallback

The `<x-inertia::head>` Blade component accepts fallback content via its slot. This content is only rendered in the initial HTML when [SSR](#server-side-rendering) is not active. When SSR is active, the `<Head>` component already provides these elements as part of the server-rendered response, so the fallback is skipped.

```blade
<head>
    @vite('resources/js/app.js')
    <x-inertia::head>
        <title>{{ config('app.name') }}</title>
        <meta name="description" content="My application description">
    </x-inertia::head>
</head>
```

<a name="title-and-meta-the-data-inertia-attribute"></a>

#### The `data-inertia` Attribute

The client-side `<Head>` component tracks the elements it manages by marking them with a `data-inertia` attribute. By default, it leaves any other tags in your Blade template untouched. Since there may only be one `<title>` tag, the `<Head>` component will always replace it. Other elements like `<meta>` and `<link>` may have multiple instances, so the component needs a way to identify which ones it should manage.

You may add the `data-inertia` attribute to elements in your Blade template to have the `<Head>` component adopt them. On the first client-side navigation, it will match these elements by key and replace or remove them as needed.

```blade
<head>
    @vite('resources/js/app.js')
    <title>{{ config('app.name') }}</title>
    <meta data-inertia="description" name="description" content="My application description">
</head>
```

The `data-inertia` value corresponds to the `head-key` property on your client-side `<Head>` elements.

<a name="links"></a>

## Links

To create links to other pages within an Inertia app, you will typically use the Inertia `<Link>` component. This component is a light wrapper around a standard anchor `<a>` link that intercepts click events and prevents full page reloads. This is [how Inertia provides a single-page app experience](#how-it-works) once your application has been loaded.

<a name="links-creating-links"></a>

### Creating Links

To create an Inertia link, use the Inertia `<Link>` component. Any attributes you provide to this component will be proxied to the underlying HTML tag.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/">Home</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/">Home</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<a href="/" use:inertia>Home</a>

<Link href="/">Home</Link>
```

By default, Inertia renders links as anchor `<a>` elements. However, you can change the tag using the `as` prop.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/logout" method="post" as="button">Logout</Link>

// Renders as...
<button type="button">Logout</button>
```

```jsx tab=React
import { Link } from '@inertiajs/react'

<Link href="/logout" method="post" as="button">Logout</Link>

// Renders as...
<button type="button">Logout</button>
```

```svelte tab=Svelte
import { Link } from '@inertiajs/svelte'

<Link href="/logout" method="post" as="button">Logout</Link>

// Renders as...
<button type="button">Logout</button>
```

> [!NOTE]
> Creating anchor (`<a>`) links that issue `POST`, `PUT`, `PATCH`, or `DELETE` requests is discouraged because it causes accessibility issues with "Open Link in New Tab / Window". The component automatically renders a `<button>` element when using these methods.

<a name="links-method"></a>

### Method

You can specify the HTTP request method for an Inertia link request using the `method` prop. The default method used by links is `GET`, but you can use the `method` prop to make `POST`, `PUT`, `PATCH`, and `DELETE` requests via links.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/logout" method="post" as="button">Logout</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/logout" method="post" as="button">
  Logout
</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<button use:inertia={{ href: '/logout', method: 'post' }} type="button">Logout</button>

<Link href="/logout" method="post">Logout</Link>
```

<a name="links-wayfinder"></a>

### Wayfinder

When using [Wayfinder](/docs/{{version}}/wayfinder) in conjunction with the `Link` component, you can simply pass the resulting object directly to the `href` prop. The `Link` will infer the HTTP method and URL directly from the Wayfinder object.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'
import { show } from '@/actions/App/Http/Controllers/UserController'

<Link :href="show(1)">John Doe</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";
import { show } from "@/actions/App/Http/Controllers/UserController";

<Link href={show(1)}>John Doe</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'
import { show } from '@/actions/App/Http/Controllers/UserController'

<button use:inertia={{ href: show(1) }} type="button">John Doe</button>

<Link href={show(1)}>John Doe</Link>
```

<a name="links-data"></a>

### Data

When making `POST` or `PUT` requests, you may wish to add additional data to the request. You can accomplish this using the `data` prop. The provided data can be an `object` or `FormData` instance.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/endpoint" method="post" :data="{ foo: bar }">Save</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/endpoint" method="post" data={{ foo: bar }}>
  Save
</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<button use:inertia={{ href: '/endpoint', method: 'post', data: { foo: bar } }} type="button">Save</button>

<Link href="/endpoint" method="post" data={{ foo: bar }}>Save</Link>
```

<a name="links-custom-headers"></a>

### Custom Headers

The `headers` prop allows you to add custom headers to an Inertia link. However, the headers Inertia uses internally to communicate its state to the server take priority and therefore cannot be overwritten.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/endpoint" :headers="{ foo: bar }">Save</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/endpoint" headers={{ foo: bar }}>
  Save
</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<button use:inertia={{ href: '/endpoint', headers: { foo: bar } }}>Save</button>

<Link href="/endpoint" headers={{ foo: bar }}>Save</Link>
```

<a name="links-browser-history"></a>

### Browser History

The `replace` prop allows you to specify the browser's history behavior. By default, page visits push (new) state (`window.history.pushState`) into the history; however, it's also possible to replace state (`window.history.replaceState`) by setting the `replace` prop to `true`. This will cause the visit to replace the current history state instead of adding a new history state to the stack.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/" replace>Home</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link replace href="/">
  Home
</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<a href="/" use:inertia={{ replace: true }}>Home</a>

<Link href="/" replace>Home</Link>
```

<a name="links-state-preservation"></a>

### State Preservation

You can preserve a page component's local state using the `preserve-state` prop. This will prevent a page component from fully re-rendering. The `preserve-state` prop is especially helpful on pages that contain forms, since you can avoid manually repopulating input fields and can also maintain a focused input.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<input v-model="query" type="text" />

<Link href="/search" :data="{ query }" preserve-state>Search</Link>
```

```jsx tab=React
import { Link } from '@inertiajs/react'

<input onChange={this.handleChange} value={query} type="text" />

<Link href="/search" data={query} preserveState>Search</Link>
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<input bind:value={query} type="text" />

<button use:inertia={{ href: '/search', data: { query }, preserveState: true }}>Search</button>

<Link href="/search" data={{ query }} preserveState>Search</Link>
```

<a name="links-scroll-preservation"></a>

### Scroll Preservation

You can use the `preserveScroll` prop to prevent Inertia from automatically resetting the scroll position when making a page visit.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/" preserve-scroll>Home</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link preserveScroll href="/">
  Home
</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<a href="/" use:inertia={{ preserveScroll: true }}>Home</a>

<Link href="/" preserveScroll>Home</Link>
```

For more information on managing scroll position, check out the documentation on [scroll management](#scroll-management).

<a name="links-partial-reloads"></a>

### Partial Reloads

The `only` prop allows you to specify that only a subset of a page's props (data) should be retrieved from the server on subsequent visits to that page.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/users?active=true" :only="['users']">Show active</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/users?active=true" only={["users"]}>
  Show active
</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<a href="/users?active=true" use:inertia={{ only: ['users'] }}>Show active</a>

<Link href="/users?active=true" only={['users']}>Show active</Link>
```

For more information on this topic, check out the complete documentation on [partial reloads](#partial-reloads).

<a name="links-view-transitions"></a>

### View Transitions

You may enable [View transitions](#view-transitions) for a link by setting the `viewTransition` prop to `true`. This will use the browser's View Transitions API to animate the page transition.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/another-page" view-transition>Navigate</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/another-page" viewTransition>
  Navigate
</Link>;
```

```svelte tab=Svelte
import { Link } from '@inertiajs/svelte'

<Link href="/another-page" viewTransition>Navigate</Link>
```

<a name="links-active-states"></a>

### Active States

It's common to set an active state for navigation links based on the current page. This can be accomplished when using Inertia by inspecting the `page` object and doing string comparisons against the `page.url` and `page.component` properties.

```vue tab=Vue
import { Link } from '@inertiajs/vue3' // URL exact match...
<Link href="/users" :class="{ active: $page.url === '/users' }">Users</Link>

// Component exact match...
<Link
  href="/users"
  :class="{ active: $page.component === 'Users/Index' }"
>Users</Link>

// URL starts with (/users, /users/create, /users/1, etc.)...
<Link
  href="/users"
  :class="{ active: $page.url.startsWith('/users') }"
>Users</Link>

// Component starts with (Users/Index, Users/Create, Users/Show, etc.)...
<Link
  href="/users"
  :class="{ active: $page.component.startsWith('Users') }"
>Users</Link>
```

```jsx tab=React
import { usePage } from '@inertiajs/react'

const { url, component } = usePage()

// URL exact match...
<Link href="/users" className={url === '/users' ? 'active' : ''}>Users</Link>

// Component exact match...
<Link href="/users" className={component === 'Users/Index' ? 'active' : ''}>Users</Link>

// URL starts with (/users, /users/create, /users/1, etc.)...
<Link href="/users" className={url.startsWith('/users') ? 'active' : ''}>Users</Link>

// Component starts with (Users/Index, Users/Create, Users/Show, etc.)...
<Link href="/users" className={component.startsWith('Users') ? 'active' : ''}>Users</Link>
```

```svelte tab=Svelte
import { inertia, Link, page } from '@inertiajs/svelte'

// URL exact match...
<a href="/users" use:inertia class:active={page.url === '/users'}>Users</a>

// Component exact match...
<a href="/users" use:inertia class:active={page.component === 'Users/Index'}>Users</a>

// URL starts with (/users, /users/create, /users/1, etc.)...
<Link href="/users" class={page.url.startsWith('/users') ? 'active' : ''}>Users</Link>

// Component starts with (Users/Index, Users/Create, Users/Show, etc.)...
<Link href="/users" class={page.component.startsWith('Users') ? 'active' : ''}>Users</Link>
```

You can perform exact match comparisons (`===`), `startsWith()` comparisons (useful for matching a subset of pages), or even more complex comparisons using regular expressions.

Using this approach, you're not limited to just setting class names. You can use this technique to conditionally render any markup on active state, such as different link text or even an SVG icon that indicates the link is active.

<a name="links-data-loading-attribute"></a>

### Data Loading Attribute

While a link is making an active request, a `data-loading` attribute is added to the link element. This allows you to style the link while it's in a loading state. The attribute is removed once the request is complete.

<a name="manual-visits"></a>

## Manual Visits

In addition to [creating links](#links), it's also possible to manually make Inertia visits or requests programmatically via JavaScript. This is accomplished via the `router.visit()` method.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit(url, {
  method: "get",
  data: {},
  replace: false,
  preserveState: false,
  preserveScroll: false,
  only: [],
  except: [],
  headers: {},
  errorBag: null,
  forceFormData: false,
  queryStringArrayFormat: "brackets",
  async: false,
  showProgress: true,
  fresh: false,
  reset: [],
  preserveUrl: false,
  prefetch: false,
  preserveErrors: false,
  viewTransition: false,
  component: null,
  pageProps: null,
  onCancelToken: (cancelToken) => {},
  onCancel: () => {},
  onBefore: (visit) => {},
  onStart: (visit) => {},
  onProgress: (progress) => {},
  onSuccess: (page) => {},
  onError: (errors) => {},
  onHttpException: (response) => {},
  onNetworkError: (error) => {},
  onFinish: (visit) => {},
  onPrefetching: () => {},
  onPrefetched: () => {},
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit(url, {
  method: "get",
  data: {},
  replace: false,
  preserveState: false,
  preserveScroll: false,
  only: [],
  except: [],
  headers: {},
  errorBag: null,
  forceFormData: false,
  queryStringArrayFormat: "brackets",
  async: false,
  showProgress: true,
  fresh: false,
  reset: [],
  preserveUrl: false,
  prefetch: false,
  preserveErrors: false,
  viewTransition: false,
  component: null,
  pageProps: null,
  onCancelToken: (cancelToken) => {},
  onCancel: () => {},
  onBefore: (visit) => {},
  onStart: (visit) => {},
  onProgress: (progress) => {},
  onSuccess: (page) => {},
  onError: (errors) => {},
  onHttpException: (response) => {},
  onNetworkError: (error) => {},
  onFinish: (visit) => {},
  onPrefetching: () => {},
  onPrefetched: () => {},
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit(url, {
  method: "get",
  data: {},
  replace: false,
  preserveState: false,
  preserveScroll: false,
  only: [],
  except: [],
  headers: {},
  errorBag: null,
  forceFormData: false,
  queryStringArrayFormat: "brackets",
  async: false,
  showProgress: true,
  fresh: false,
  reset: [],
  preserveUrl: false,
  prefetch: false,
  preserveErrors: false,
  viewTransition: false,
  component: null,
  pageProps: null,
  onCancelToken: (cancelToken) => {},
  onCancel: () => {},
  onBefore: (visit) => {},
  onStart: (visit) => {},
  onProgress: (progress) => {},
  onSuccess: (page) => {},
  onError: (errors) => {},
  onHttpException: (response) => {},
  onNetworkError: (error) => {},
  onFinish: (visit) => {},
  onPrefetching: () => {},
  onPrefetched: () => {},
});
```

However, it's generally more convenient to use one of Inertia's shortcut request methods. These methods share all the same options as `router.visit()`.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.get(url, data, options);
router.post(url, data, options);
router.put(url, data, options);
router.patch(url, data, options);
router.delete(url, options);
router.reload(options); // Uses the current URL
```

```js tab=React
import { router } from "@inertiajs/react";

router.get(url, data, options);
router.post(url, data, options);
router.put(url, data, options);
router.patch(url, data, options);
router.delete(url, options);
router.reload(options); // Uses the current URL
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.get(url, data, options);
router.post(url, data, options);
router.put(url, data, options);
router.patch(url, data, options);
router.delete(url, options);
router.reload(options); // Uses the current URL
```

The `reload()` method is a convenient, shorthand method that automatically visits the current page with `preserveState` and `preserveScroll` both set to `true`, making it the perfect method to invoke when you just want to reload the current page's data.

<a name="manual-visits-method"></a>

### Method

When making manual visits, you may use the `method` option to set the request's HTTP method to `get`, `post`, `put`, `patch` or `delete`. The default method is `get`.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit(url, { method: "post" });
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit(url, { method: "post" });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit(url, { method: "post" });
```

<a name="manual-visits-wayfinder"></a>

### Wayfinder

When using [Wayfinder](/docs/{{version}}/wayfinder), you can pass the resulting object directly to any router method. The router will infer the HTTP method and URL from the Wayfinder object.

```js tab=Vue
import { router } from "@inertiajs/vue3";
import { show, store, destroy } from "@/actions/App/Http/Controllers/UserController";

router.visit(show(1));
router.post(store());
router.delete(destroy(1));
```

```js tab=React
import { router } from "@inertiajs/react";
import { show, store, destroy } from "@/actions/App/Http/Controllers/UserController";

router.visit(show(1));
router.post(store());
router.delete(destroy(1));
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";
import { show, store, destroy } from "@/actions/App/Http/Controllers/UserController";

router.visit(show(1));
router.post(store());
router.delete(destroy(1));
```

If you provide both a Wayfinder object and specify the `method` option, the `method` option will take precedence.

```js tab=Vue
import { router } from "@inertiajs/vue3";
import { update } from "@/actions/App/Http/Controllers/UserController";

router.visit(update(1), { method: "patch" });
```

```js tab=React
import { router } from "@inertiajs/react";
import { update } from "@/actions/App/Http/Controllers/UserController";

router.visit(update(1), { method: "patch" });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";
import { update } from "@/actions/App/Http/Controllers/UserController";

router.visit(update(1), { method: "patch" });
```

<a name="manual-visits-data"></a>

### Data

You may use the `data` option to add data to the request.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit("/users", {
  method: "post",
  data: {
    name: "John Doe",
    email: "john.doe@example.com",
  },
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit("/users", {
  method: "post",
  data: {
    name: "John Doe",
    email: "john.doe@example.com",
  },
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit("/users", {
  method: "post",
  data: {
    name: "John Doe",
    email: "john.doe@example.com",
  },
});
```

For convenience, the `get()`, `post()`, `put()`, and `patch()` methods all accept `data` as their second argument.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/users", {
  name: "John Doe",
  email: "john.doe@example.com",
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/users", {
  name: "John Doe",
  email: "john.doe@example.com",
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/users", {
  name: "John Doe",
  email: "john.doe@example.com",
});
```

<a name="manual-visits-custom-headers"></a>

### Custom Headers

The `headers` option allows you to add custom headers to a request.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/users", data, {
  headers: {
    "Custom-Header": "value",
  },
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/users", data, {
  headers: {
    "Custom-Header": "value",
  },
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/users", data, {
  headers: {
    "Custom-Header": "value",
  },
});
```

The headers Inertia uses internally to communicate its state to the server take priority and therefore cannot be overwritten.

<a name="manual-visits-global-visit-options"></a>

### Global Visit Options

You may configure a `visitOptions` callback when [initializing your Inertia app](#client-side-setup-configuring-defaults) to modify visit options globally for every request. The callback receives the target URL and the current visit options, and should return an object with any options you want to override.

```js tab=Vue
import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";

createInertiaApp({
  // ...
  defaults: {
    visitOptions: (href, options) => {
      return {
        headers: {
          ...options.headers,
          "X-Custom-Header": "value",
        },
      };
    },
  },
});
```

```jsx tab=React
import { createInertiaApp } from "@inertiajs/react";
import { createRoot } from "react-dom/client";

createInertiaApp({
  // ...
  defaults: {
    visitOptions: (href, options) => {
      return {
        headers: {
          ...options.headers,
          "X-Custom-Header": "value",
        },
      };
    },
  },
});
```

```js tab=Svelte
import { createInertiaApp } from "@inertiajs/svelte";

createInertiaApp({
  // ...
  defaults: {
    visitOptions: (href, options) => {
      return {
        headers: {
          ...options.headers,
          "X-Custom-Header": "value",
        },
      };
    },
  },
});
```

<a name="manual-visits-file-uploads"></a>

### File Uploads

When making visits / requests that include files, Inertia will automatically convert the request data into a `FormData` object. If you would like the request to always use a `FormData` object, you may use the `forceFormData` option.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/companies", data, {
  forceFormData: true,
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/companies", data, {
  forceFormData: true,
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/companies", data, {
  forceFormData: true,
});
```

For more information on uploading files, check out the dedicated [file uploads](#file-uploads) documentation.

<a name="manual-visits-browser-history"></a>

### Browser History

When making visits, Inertia automatically adds a new entry into the browser history. However, it's also possible to replace the current history entry by setting the `replace` option to `true`.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.get("/users", { search: "John" }, { replace: true });
```

```js tab=React
import { router } from "@inertiajs/react";

router.get("/users", { search: "John" }, { replace: true });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.get("/users", { search: "John" }, { replace: true });
```

Visits made to the same URL automatically set `replace` to `true`.

<a name="manual-visits-client-side-visits"></a>

### Client-Side Visits

You can use the `router.push` and `router.replace` methods to make client-side visits. These methods are useful when you want to update the browser's history without making a server request.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.push({
  url: "/users",
  component: "Users",
  props: { search: "John" },
  clearHistory: false,
  encryptHistory: false,
  preserveScroll: false,
  preserveState: false,
  errorBag: null,
  onSuccess: (page) => {},
  onError: (errors) => {},
  onFinish: (visit) => {},
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.push({
  url: "/users",
  component: "Users",
  props: { search: "John" },
  clearHistory: false,
  encryptHistory: false,
  preserveScroll: false,
  preserveState: false,
  errorBag: null,
  onSuccess: (page) => {},
  onError: (errors) => {},
  onFinish: (visit) => {},
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.push({
  url: "/users",
  component: "Users",
  props: { search: "John" },
  clearHistory: false,
  encryptHistory: false,
  preserveScroll: false,
  preserveState: false,
  errorBag: null,
  onSuccess: (page) => {},
  onError: (errors) => {},
  onFinish: (visit) => {},
});
```

All of the parameters are optional. By default, all passed parameters (except `errorBag`) will be merged with the current page. This means you are responsible for overriding the current page's URL, component, and props.

If you need access to the current page's props, you may pass a function to the `props` option. This function receives the current props (including any [once props](#once-props)) and should return the new props.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.push({ url: "/users", component: "Users" });

router.replace({
  props: (currentProps) => ({ ...currentProps, search: "John" }),
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.push({ url: "/users", component: "Users" });

router.replace({
  props: (currentProps) => ({ ...currentProps, search: "John" }),
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.push({ url: "/users", component: "Users" });

router.replace({
  props: (currentProps) => ({ ...currentProps, search: "John" }),
});
```

The function also receives [once props](#once-props) as a second argument. This is useful when you want to replace all regular props while still preserving once props.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.replace({
  props: (currentProps, onceProps) => ({ ...onceProps, search: "John" }),
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.replace({
  props: (currentProps, onceProps) => ({ ...onceProps, search: "John" }),
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.replace({
  props: (currentProps, onceProps) => ({ ...onceProps, search: "John" }),
});
```

The `errorBag` option allows you to specify which error bag to use when handling validation errors in the `onError` callback.

Make sure that any route you push on the client side is also defined on the server side. If the user refreshes the page, the server will need to know how to render the page.

> [!WARNING]
> Some browsers limit the number of `history.pushState()` and
> `history.replaceState()` calls allowed within a short time period. Inertia
> catches this error and logs it to the console, but the state update will be
> lost. Avoid calling `router.push()` or `router.replace()` too frequently, and
> consider debouncing or batching updates in high-frequency scenarios.

<a name="manual-visits-prop-helpers"></a>

#### Prop Helpers

Inertia provides three helper methods for updating page props without making server requests. These methods are shortcuts to `router.replace()` and automatically set `preserveScroll` and `preserveState` to `true`.

```js tab=Vue
import { router } from "@inertiajs/vue3";

// Replace a prop value...
router.replaceProp("user.name", "Jane Smith");

// Append to an array prop...
router.appendToProp("messages", { id: 4, text: "New message" });

// Prepend to an array prop...
router.prependToProp("tags", "urgent");
```

```js tab=React
import { router } from "@inertiajs/react";

// Replace a prop value...
router.replaceProp("user.name", "Jane Smith");

// Append to an array prop...
router.appendToProp("messages", { id: 4, text: "New message" });

// Prepend to an array prop...
router.prependToProp("tags", "urgent");
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

// Replace a prop value...
router.replaceProp("user.name", "Jane Smith");

// Append to an array prop...
router.appendToProp("messages", { id: 4, text: "New message" });

// Prepend to an array prop...
router.prependToProp("tags", "urgent");
```

All three methods support dot notation for nested props and can accept a callback function that receives the current value as the first argument and the current page props as the second argument.

```js tab=Vue
import { router } from '@inertiajs/vue3'

router.prependToProp('notifications', (current, props) => {
    return {
        id: Date.now(),
        message: `Hello ${props.user.name}`,
    }
})
```

```js tab=React
import { router } from '@inertiajs/react'

router.prependToProp('notifications', (current, props) => {
    return {
        id: Date.now(),
        message: `Hello ${props.user.name}`,
    }
})
```

```js tab=Svelte
import { router } from '@inertiajs/svelte'

router.prependToProp('notifications', (current, props) => {
    return {
        id: Date.now(),
        message: `Hello ${props.user.name}`,
    }
})
```

<a name="manual-visits-state-preservation"></a>

### State Preservation

By default, page visits to the same page create a fresh page component instance. This causes any local state, such as form inputs, scroll positions, and focus states to be lost.

However, in some situations, it's necessary to preserve the page component state. For example, when submitting a form, you need to preserve your form data in the event that form validation fails on the server.

For this reason, the `post`, `put`, `patch`, `delete`, and `reload` methods all set the `preserveState` option to `true` by default.

You can instruct Inertia to preserve the component's state when using the `get` method by setting the `preserveState` option to `true`.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.get("/users", { search: "John" }, { preserveState: true });
```

```js tab=React
import { router } from "@inertiajs/react";

router.get("/users", { search: "John" }, { preserveState: true });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.get("/users", { search: "John" }, { preserveState: true });
```

If you'd like to only preserve state if the response includes validation errors, set the `preserveState` option to "errors".

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.get("/users", { search: "John" }, { preserveState: "errors" });
```

```js tab=React
import { router } from "@inertiajs/react";

router.get("/users", { search: "John" }, { preserveState: "errors" });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.get("/users", { search: "John" }, { preserveState: "errors" });
```

You can also lazily evaluate the `preserveState` option based on the response by providing a callback.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/users", data, {
  preserveState: (page) => page.props.someProp === "value",
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/users", data, {
  preserveState: (page) => page.props.someProp === "value",
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/users", data, {
  preserveState: (page) => page.props.someProp === "value",
});
```

<a name="manual-visits-scroll-preservation"></a>

### Scroll Preservation

When navigating between pages, Inertia mimics default browser behavior by automatically resetting the scroll position of the document body (as well as any [scroll regions](#scroll-management-scroll-regions) you've defined) back to the top of the page.

You can disable this behavior by setting the `preserveScroll` option to `true`.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit(url, { preserveScroll: true });
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit(url, { preserveScroll: true });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit(url, { preserveScroll: true });
```

If you'd like to only preserve the scroll position if the response includes validation errors, set the `preserveScroll` option to "errors".

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit(url, { preserveScroll: "errors" });
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit(url, { preserveScroll: "errors" });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit(url, { preserveScroll: "errors" });
```

You can also lazily evaluate the `preserveScroll` option based on the response by providing a callback.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/users", data, {
  preserveScroll: (page) => page.props.someProp === "value",
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/users", data, {
  preserveScroll: (page) => page.props.someProp === "value",
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/users", data, {
  preserveScroll: (page) => page.props.someProp === "value",
});
```

For more information regarding this feature, please consult the [scroll management](#scroll-management) documentation.

<a name="manual-visits-partial-reloads"></a>

### Partial Reloads

The `only` option allows you to request a subset of the props (data) from the server on subsequent visits to the same page, thus making your application more efficient since it does not need to retrieve data that the page is not interested in refreshing.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.get("/users", { search: "John" }, { only: ["users"] });
```

```js tab=React
import { router } from "@inertiajs/react";

router.get("/users", { search: "John" }, { only: ["users"] });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.get("/users", { search: "John" }, { only: ["users"] });
```

For more information on this feature, please consult the [partial reloads](#partial-reloads) documentation.

<a name="manual-visits-view-transitions"></a>

### View Transitions

You may enable [View transitions](#view-transitions) for a visit by setting the `viewTransition` option to `true`. This will use the browser's View Transitions API to animate the page transition.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit("/another-page", { viewTransition: true });
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit("/another-page", { viewTransition: true });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit("/another-page", { viewTransition: true });
```

<a name="manual-visits-visit-cancellation"></a>

### Visit Cancellation

You may cancel all in-flight visits using the `router.cancelAll()` method. By default, this cancels all synchronous, asynchronous, and prefetch requests.

```js tab=Vue
import { router } from "@inertiajs/vue3";

// Cancel all in-flight requests...
router.cancelAll();
```

```js tab=React
import { router } from "@inertiajs/react";

// Cancel all in-flight requests...
router.cancelAll();
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

// Cancel all in-flight requests...
router.cancelAll();
```

You may selectively cancel specific request types by passing an options object.

```js
// Cancel only async requests (leaving sync and prefetch active)...
router.cancelAll({ sync: false, prefetch: false });

// Cancel only sync requests...
router.cancelAll({ async: false, prefetch: false });

// Cancel everything except prefetch requests...
router.cancelAll({ prefetch: false });
```

<a name="manual-visits-cancel-tokens"></a>

#### Cancel Tokens

For more granular control, you may cancel individual visits using a cancel token. Inertia automatically generates a cancel token and provides it via the `onCancelToken()` callback prior to making the visit.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/users", data, {
  onCancelToken: (cancelToken) => (this.cancelToken = cancelToken),
});

// Cancel the visit...
this.cancelToken.cancel();
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/users", data, {
  onCancelToken: (cancelToken) => (this.cancelToken = cancelToken),
});

// Cancel the visit...
this.cancelToken.cancel();
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/users", data, {
  onCancelToken: (cancelToken) => (this.cancelToken = cancelToken),
});

// Cancel the visit...
this.cancelToken.cancel();
```

The `onCancel()` and `onFinish()` event callbacks will be executed when a visit is canceled.

<a name="manual-visits-event-callbacks"></a>

### Event Callbacks

In addition to Inertia's [global events](#events), Inertia also provides a number of per-visit event callbacks.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/users", data, {
  onBefore: (visit) => {},
  onStart: (visit) => {},
  onProgress: (progress) => {},
  onSuccess: (page) => {},
  onError: (errors) => {},
  onHttpException: (response) => {},
  onNetworkError: (error) => {},
  onCancel: () => {},
  onFinish: (visit) => {},
  onPrefetching: () => {},
  onPrefetched: () => {},
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/users", data, {
  onBefore: (visit) => {},
  onStart: (visit) => {},
  onProgress: (progress) => {},
  onSuccess: (page) => {},
  onError: (errors) => {},
  onHttpException: (response) => {},
  onNetworkError: (error) => {},
  onCancel: () => {},
  onFinish: (visit) => {},
  onPrefetching: () => {},
  onPrefetched: () => {},
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/users", data, {
  onBefore: (visit) => {},
  onStart: (visit) => {},
  onProgress: (progress) => {},
  onSuccess: (page) => {},
  onError: (errors) => {},
  onHttpException: (response) => {},
  onNetworkError: (error) => {},
  onCancel: () => {},
  onFinish: (visit) => {},
  onPrefetching: () => {},
  onPrefetched: () => {},
});
```

Returning `false` from the `onBefore()` callback will cause the visit to be canceled. Returning `false` from `onHttpException()` or `onNetworkError()` will prevent the corresponding [global event](#events) from being fired and its default behavior.

```js tab=Vue
import { router } from '@inertiajs/vue3'

router.delete(`/users/${user.id}`, {
    onBefore: () => confirm('Are you sure you want to delete this user?'),
})
```

```js tab=React
import { router } from '@inertiajs/react'

router.delete(`/users/${user.id}`, {
    onBefore: () => confirm('Are you sure you want to delete this user?'),
})
```

```js tab=Svelte
import { router } from '@inertiajs/svelte'

router.delete(`/users/${user.id}`, {
    onBefore: () => confirm('Are you sure you want to delete this user?'),
})
```

It's also possible to return a promise from the `onSuccess()` and `onError()` callbacks. When doing so, the "finish" event will be delayed until the promise has resolved.

```js tab=Vue
import { router } from '@inertiajs/vue3'

router.post(url, {
    onSuccess: () => {
        return Promise.all([
            this.firstTask(),
            this.secondTask()
        ])
    },
    onFinish: visit => {
        // Not called until firstTask() and secondTask() have finished
    },
})
```

```js tab=React
import { router } from '@inertiajs/react'

router.post(url, {
    onSuccess: () => {
        return Promise.all([
            this.firstTask(),
            this.secondTask()
        ])
    },
    onFinish: visit => {
        // Not called until firstTask() and secondTask() have finished
    },
})
```

```js tab=Svelte
import { router } from '@inertiajs/svelte'

router.post(url, {
    onSuccess: () => {
        return Promise.all([
            this.firstTask(),
            this.secondTask()
        ])
    },
    onFinish: visit => {
        // Not called until firstTask() and secondTask() have finished
    },
})
```

<a name="instant-visits"></a>

## Instant Visits

Sometimes you may wish to navigate to a new page without waiting for the server to respond. Instant visits allow Inertia to immediately swap to the target page component while the server request happens in the background. Once the server responds, the real props are merged in.

Unlike [client-side visits](#manual-visits-client-side-visits), which update the page entirely on the client without making a server request, instant visits still make a full server request. The difference is that the user sees the target page right away instead of waiting for the response.

<a name="instant-visits-basic-usage"></a>

### Basic Usage

To make an instant visit, provide the target `component` name to a `Link` or to `router.visit()`.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/dashboard" component="Dashboard">Dashboard</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/dashboard" component="Dashboard">
  Dashboard
</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<a href="/dashboard" use:inertia={{ component: 'Dashboard' }}>Dashboard</a>

<Link href="/dashboard" component="Dashboard">Dashboard</Link>
```

When clicked, Inertia immediately renders the `Dashboard` component while the server request fires in the background. The full props are merged in when the response arrives.

The target component must be able to render without its page-specific props, as only [shared props](#shared-data) are available on the intermediate page. You may use optional chaining or conditional rendering to handle missing props.

Programmatic instant visits work the same way via the `component` option on `router.visit()`.

```js
router.visit("/dashboard", {
  component: "Dashboard",
});
```

<a name="instant-visits-shared-props"></a>

### Shared Props

The Hypervel adapter includes a `sharedProps` metadata key in the page response, listing the top-level prop keys registered via `Inertia::share()`.

```json
{
  "component": "Dashboard",
  "props": { "auth": { "user": "..." }, "stats": { ... } },
  "sharedProps": ["auth"]
}
```

Inertia reads this list and carries those props over from the current page to the intermediate page. Props like `auth` are available immediately, while page-specific props like `stats` will be `undefined` until the server responds.

<a name="instant-visits-page-props"></a>

### Page Props

You may provide props for the intermediate page using the `pageProps` option. This is useful for passing data you already have on the current page, or for setting placeholder values to display loading states while the server responds.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link
  href="/posts/1"
  component="Posts/Show"
  :page-props="{ title: 'Loading...' }"
>
  View Post
</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link
  href="/posts/1"
  component="Posts/Show"
  pageProps={{ title: "Loading..." }}
>
  View Post
</Link>;
```

```svelte tab=Svelte
import { Link } from '@inertiajs/svelte'

<Link
  href="/posts/1"
  component="Posts/Show"
  pageProps={{ title: 'Loading...' }}
>
  View Post
</Link>
```

When `pageProps` is provided as an object, shared props are not automatically carried over. You are in full control of the intermediate page's props.

A callback may also be passed to `pageProps`. The callback receives the current page's props and the shared props as arguments, so you may selectively spread them.

```js
router.visit("/posts/1", {
  component: "Posts/Show",
  pageProps: (currentProps, sharedProps) => ({
    ...sharedProps,
    title: "Loading...",
  }),
});
```

<a name="instant-visits-disabling-shared-prop-keys"></a>

### Disabling Shared Prop Keys

You may disable the `sharedProps` metadata key in your `config/inertia.php` configuration file. The server will still resolve and include shared prop values in the response, but the metadata listing which keys are shared will be omitted. Without this list, the client cannot identify which props to carry over during instant visits.

```php
return [
    'expose_shared_prop_keys' => false,
];
```

<a name="forms"></a>

## Forms

Inertia provides two primary ways to build forms: the `<Form>` component and the `useForm` helper. Both integrate with your server-side framework's validation and handle form submissions without full page reloads.

<a name="forms-form-component"></a>

### Form Component

Inertia provides a `<Form>` component that behaves much like a classic HTML form, but uses Inertia under the hood to avoid full page reloads. This is the simplest way to get started with forms in Inertia.

```vue tab=Vue
<script setup>
import { Form } from "@inertiajs/vue3";
</script>

<template>
  <Form action="/users" method="post">
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Create User</button>
  </Form>
</template>
```

```jsx tab=React
import { Form } from "@inertiajs/react";

export default () => (
  <Form action="/users" method="post">
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Create User</button>
  </Form>
);
```

```svelte tab=Svelte
<script>
    import { Form } from '@inertiajs/svelte'
</script>

<Form action="/users" method="post">
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Create User</button>
</Form>
```

Just like a traditional HTML form, there is no need to attach a `v-model` (Vue), an `onChange` handler (React), or a `bind:` (Svelte) to your input fields, just give each input a `name` attribute and the `Form` component will handle the data submission for you. In React, also provide a `defaultValue` if applicable.

The component also supports nested data structures, file uploads, and dotted key notation.

```vue tab=Vue
<template>
  <Form action="/reports" method="post">
    <input type="text" name="name" />
    <textarea name="report[description]"></textarea>
    <input type="text" name="report[tags][]" />
    <input type="file" name="documents" multiple />
    <button type="submit">Create Report</button>
  </Form>
</template>
```

```jsx tab=React
<Form action="/reports" method="post">
  <input type="text" name="name" />
  <textarea name="report[description]"></textarea>
  <input type="text" name="report[tags][]" />
  <input type="file" name="documents" multiple />
  <button type="submit">Create Report</button>
</Form>
```

```svelte tab=Svelte
<Form action="/reports" method="post">
    <input type="text" name="name" />
    <textarea name="report[description]"></textarea>
    <input type="text" name="report[tags][]" />
    <input type="file" name="documents" multiple />
    <button type="submit">Create Report</button>
</Form>
```

You can pass a `transform` prop to modify the form data before submission. This is useful for injecting additional fields or transforming existing data, although hidden inputs work too.

```vue tab=Vue
<template>
  <Form
    action="/posts"
    method="post"
    :transform="(data) => ({ ...data, user_id: 123 })"
  >
    <input type="text" name="title" />
    <button type="submit">Create Post</button>
  </Form>
</template>
```

```jsx tab=React
<Form
  action="/posts"
  method="post"
  transform={(data) => ({ ...data, user_id: 123 })}
>
  <input type="text" name="title" />
  <button type="submit">Create Post</button>
</Form>
```

```svelte tab=Svelte
<Form
    action="/posts"
    method="post"
    transform={data => ({ ...data, user_id: 123 })}
>
    <input type="text" name="title" />
    <button type="submit">Create Post</button>
</Form>
```

<a name="forms-wayfinder"></a>

#### Wayfinder

When using [Wayfinder](/docs/{{version}}/wayfinder), you can pass the resulting object directly to the `action` prop. The Form component will infer the HTTP method and URL from the Wayfinder object.

```vue tab=Vue
<script setup>
import { Form } from "@inertiajs/vue3";
import { store } from "@/actions/App/Http/Controllers/UserController";
</script>

<template>
  <Form :action="store()">
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Create User</button>
  </Form>
</template>
```

```jsx tab=React
import { Form } from "@inertiajs/react";
import { store } from "@/actions/App/Http/Controllers/UserController";

export default () => (
  <Form action={store()}>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Create User</button>
  </Form>
);
```

```svelte tab=Svelte
<script>
    import { Form } from '@inertiajs/svelte'
    import { store } from '@/actions/App/Http/Controllers/UserController'
</script>

<Form action={store()}>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Create User</button>
</Form>
```

<a name="forms-default-values"></a>

#### Default Values

You can set default values for form inputs using standard HTML attributes. Use `defaultValue` (React and Vue) or `value` (Svelte) for text inputs and textareas, and `defaultChecked` (React and Vue) or `checked` (Svelte) for checkboxes and radios.

```vue tab=Vue
<template>
  <Form action="/users" method="post">
    <input type="text" name="name" defaultValue="John Doe" />

    <select name="country">
      <option value="us">United States</option>
      <option value="ca">Canada</option>
      <option value="uk" selected>United Kingdom</option>
    </select>

    <input type="checkbox" name="subscribe" value="yes" defaultChecked />

    <button type="submit">Submit</button>
  </Form>
</template>
```

```jsx tab=React
<Form action="/users" method="post">
  <input type="text" name="name" defaultValue="John Doe" />

  <select name="country" defaultValue="uk">
    <option value="us">United States</option>
    <option value="ca">Canada</option>
    <option value="uk">United Kingdom</option>
  </select>

  <input type="checkbox" name="subscribe" value="yes" defaultChecked />

  <button type="submit">Submit</button>
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post">
    <input type="text" name="name" value="John Doe" />

    <select name="country" value="uk">
        <option value="us">United States</option>
        <option value="ca">Canada</option>
        <option value="uk">United Kingdom</option>
    </select>

    <input type="checkbox" name="subscribe" value="yes" checked />

    <button type="submit">Submit</button>
</Form>
```

<a name="forms-checkbox-inputs"></a>

#### Checkbox Inputs

When working with checkboxes, you may want to add an explicit `value` attribute such as `value="1"`. Without a value attribute, checked checkboxes will submit as `"on"`, which some server-side validation rules may not recognize as a proper boolean value.

<a name="forms-slot-props"></a>

#### Slot Props

The `<Form>` component exposes reactive state and helper methods through its default slot, giving you access to form processing state, errors, and utility functions.

```vue tab=Vue
<template>
  <Form
    action="/users"
    method="post"
    #default="{
      errors,
      hasErrors,
      processing,
      progress,
      wasSuccessful,
      recentlySuccessful,
      setError,
      clearErrors,
      resetAndClearErrors,
      defaults,
      isDirty,
      reset,
      submit,
      cancel,
    }"
  >
    <input type="text" name="name" />

    <div v-if="errors.name">
      {{ errors.name }}
    </div>

    <button type="submit" :disabled="processing">
      {{ processing ? "Creating..." : "Create User" }}
    </button>

    <div v-if="wasSuccessful">User created successfully!</div>
  </Form>
</template>
```

```jsx tab=React
<Form action="/users" method="post">
    {({
        errors,
        hasErrors,
        processing,
        progress,
        wasSuccessful,
        recentlySuccessful,
        setError,
        clearErrors,
        resetAndClearErrors,
        defaults,
        isDirty,
        reset,
        submit,
        cancel,
    }) => (
        <>
            <input type="text" name="name" />

            {errors.name && <div>{errors.name}</div>}

            <button type="submit" disabled={processing}>
                {processing ? 'Creating...' : 'Create User'}
            </button>

            {wasSuccessful && <div>User created successfully!</div>}
        </>
    )}
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post">
    {#snippet children({
        errors,
        hasErrors,
        processing,
        progress,
        wasSuccessful,
        recentlySuccessful,
        setError,
        clearErrors,
        resetAndClearErrors,
        defaults,
        isDirty,
        reset,
        submit,
        cancel,
    })}
        <input type="text" name="name" />

        {#if errors.name}
            <div>{errors.name}</div>
        {/if}

        <button type="submit" disabled={processing}>
            {processing ? 'Creating...' : 'Create User'}
        </button>

        {#if wasSuccessful}
            <div>User created successfully!</div>
        {/if}
    {/snippet}
</Form>
```

The `defaults` method allows you to update the form's default values to match the current field values. When called, subsequent `reset()` calls will restore fields to these new defaults, and the `isDirty` property will track changes from these updated defaults. Unlike `useForm`, this method accepts no arguments and always uses all current form values.

The `errors` object uses dotted notation for nested fields, allowing you to display validation messages for complex form structures.

```vue tab=Vue
<Form action="/users" method="post" #default="{ errors }">
    <input type="text" name="user.name" />
    <div v-if="errors['user.name']">{{ errors['user.name'] }}</div>
</Form>
```

```jsx tab=React
<Form action="/users" method="post">
    {({ errors }) => (
        <>
            <input type="text" name="user.name" />
            {errors['user.name'] && <div>{errors['user.name']}</div>}
        </>
    )}
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post">
    {#snippet children({ errors })}
        <input type="text" name="user.name" />
        {#if errors['user.name']}
            <div>{errors['user.name']}</div>
        {/if}
    {/snippet}
</Form>
```

<a name="forms-props-and-options"></a>

#### Props and Options

In addition to `action` and `method`, the `<Form>` component accepts several props. Many of them are identical to the options available in Inertia's [visit options](#manual-visits).

```vue tab=Vue
<template>
  <Form
    action="/profile"
    method="put"
    error-bag="profile"
    query-string-array-format="indices"
    :headers="{ 'X-Custom-Header': 'value' }"
    :show-progress="false"
    :transform="(data) => ({ ...data, timestamp: Date.now() })"
    :optimistic="(props, data) => ({ ...props })"
    :invalidate-cache-tags="['users', 'dashboard']"
    disable-while-processing
    cancel-on-unmount
    :options="{
      preserveScroll: true,
      preserveState: true,
      preserveUrl: true,
      replace: true,
      only: ['users', 'flash'],
      except: ['secret'],
      reset: ['page'],
    }"
  >
    <input type="text" name="name" />
    <button type="submit">Update</button>
  </Form>
</template>
```

```jsx tab=React
<Form
  action="/profile"
  method="put"
  errorBag="profile"
  queryStringArrayFormat="indices"
  headers={{ "X-Custom-Header": "value" }}
  showProgress={false}
  transform={(data) => ({ ...data, timestamp: Date.now() })}
  optimistic={(props, data) => ({ ...props })}
  invalidateCacheTags={["users", "dashboard"]}
  disableWhileProcessing
  cancelOnUnmount
  options={{
    preserveScroll: true,
    preserveState: true,
    preserveUrl: true,
    replace: true,
    only: ["users", "flash"],
    except: ["secret"],
    reset: ["page"],
  }}
>
  <input type="text" name="name" />
  <button type="submit">Update</button>
</Form>
```

```svelte tab=Svelte
<Form
    action="/profile"
    method="put"
    errorBag="profile"
    queryStringArrayFormat="indices"
    headers={{ 'X-Custom-Header': 'value' }}
    showProgress={false}
    transform={data => ({ ...data, timestamp: Date.now() })}
    optimistic={(props, data) => ({ ...props })}
    invalidateCacheTags={['users', 'dashboard']}
    disableWhileProcessing
    cancelOnUnmount
    options={{
        preserveScroll: true,
        preserveState: true,
        preserveUrl: true,
        replace: true,
        only: ['users', 'flash'],
        except: ['secret'],
        reset: ['page'],
    }}
>
    <input type="text" name="name" />
    <button type="submit">Update</button>
</Form>
```

Some props are intentionally grouped under `options` instead of being top-level to avoid confusion. For example, `only`, `except`, and `reset` relate to _partial reloads_, not _partial submissions_. The general rule: top-level props are for the form submission itself, while `options` control how Inertia handles the subsequent visit.

When setting the `disableWhileProcessing` (React and Svelte) or `disable-while-processing` (Vue) prop, the `Form` component will add the `inert` attribute to the HTML `form` tag while the form is processing to prevent user interaction.

To style the form while it's processing, you can target the inert form in the following ways.

```jsx
<Form
  action="/profile"
  method="put"
  disableWhileProcessing
  className="inert:opacity-50 inert:pointer-events-none"
>
  {/* Your form fields here */}
</Form>
```

```css
form[inert] {
  opacity: 0.5;
  pointer-events: none;
}
```

<a name="forms-events"></a>

#### Events

The `<Form>` component emits all the standard visit [events](#events) for form submissions.

```vue tab=Vue
<template>
  <Form
    action="/users"
    method="post"
    @before="handleBefore"
    @start="handleStart"
    @progress="handleProgress"
    @success="handleSuccess"
    @error="handleError"
    @finish="handleFinish"
    @cancel="handleCancel"
    @cancelToken="handleCancelToken"
  >
    <input type="text" name="name" />
    <button type="submit">Create User</button>
  </Form>
</template>
```

```jsx tab=React
<Form
  action="/users"
  method="post"
  onCancelToken={handleCancelToken}
  onBefore={handleBefore}
  onStart={handleStart}
  onProgress={handleProgress}
  onCancel={handleCancel}
  onSuccess={handleSuccess}
  onError={handleError}
  onFinish={handleFinish}
>
  <input type="text" name="name" />
  <button type="submit">Create User</button>
</Form>
```

```svelte tab=Svelte
<Form
    action="/users"
    method="post"
    onCancelToken={handleCancelToken}
    onBefore={handleBefore}
    onStart={handleStart}
    onProgress={handleProgress}
    onCancel={handleCancel}
    onSuccess={handleSuccess}
    onError={handleError}
    onFinish={handleFinish}
>
    <input type="text" name="name" />
    <button type="submit">Create User</button>
</Form>
```

<a name="forms-resetting-the-form"></a>

#### Resetting the Form

The `Form` component provides several attributes that allow you to reset the form after a submission.

`resetOnSuccess` may be used to reset the form after a successful submission.

```vue tab=Vue
<template>
  <!-- Reset the entire form on success -->
  <Form action="/users" method="post" resetOnSuccess>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
  </Form>

  <!-- Reset specific fields on success -->
  <Form action="/users" method="post" :resetOnSuccess="['name']">
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
  </Form>
</template>
```

```jsx tab=React
// Reset the entire form on success
<Form action="/users" method="post" resetOnSuccess>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
</Form>

// Reset specific fields on success
<Form action="/users" method="post" resetOnSuccess={['name']}>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
</Form>
```

```svelte tab=Svelte
<!-- Reset the entire form on success -->
<Form action="/users" method="post" resetOnSuccess>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
</Form>

<!-- Reset specific fields on success -->
<Form action="/users" method="post" resetOnSuccess={['name']}>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
</Form>
```

`resetOnError` may be used to reset the form after errors.

```vue tab=Vue
<template>
  <!-- Reset the entire form on success -->
  <Form action="/users" method="post" resetOnError>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
  </Form>

  <!-- Reset specific fields on success -->
  <Form action="/users" method="post" :resetOnError="['name']">
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
  </Form>
</template>
```

```jsx tab=React
// Reset the entire form on success
<Form action="/users" method="post" resetOnError>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
</Form>

// Reset specific fields on success
<Form action="/users" method="post" resetOnError={['name']}>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
</Form>
```

```svelte tab=Svelte
<!-- Reset the entire form on success -->
<Form action="/users" method="post" resetOnError>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
</Form>

<!-- Reset specific fields on success -->
<Form action="/users" method="post" resetOnError={['name']}>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
</Form>
```

<a name="forms-setting-new-default-values"></a>

#### Setting New Default Values

The `Form` component provides the `setDefaultsOnSuccess` attribute to set the current form values as the new defaults after a successful submission.

```vue tab=Vue
<template>
  <Form action="/users" method="post" setDefaultsOnSuccess>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
  </Form>
</template>
```

```jsx tab=React
<Form action="/users" method="post" setDefaultsOnSuccess>
  <input type="text" name="name" />
  <input type="email" name="email" />
  <button type="submit">Submit</button>
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post" setDefaultsOnSuccess>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Submit</button>
</Form>
```

<a name="forms-canceling-form-submissions"></a>

#### Canceling Form Submissions

**v3.7.0+**

The `cancel` method aborts an in-flight submission. It's available through the [slot props](#forms-slot-props), the [component ref](#forms-programmatic-access), and the [form context](#forms-form-context).

```vue tab=Vue
<template>
  <Form action="/users" method="post" #default="{ processing, cancel }">
    <input type="text" name="name" />

    <button v-if="processing" type="button" @click="cancel">Cancel</button>
    <button v-else type="submit">Submit</button>
  </Form>
</template>
```

```jsx tab=React
<Form action="/users" method="post">
  {({ processing, cancel }) => (
    <>
      <input type="text" name="name" />

      {processing ? (
        <button type="button" onClick={cancel}>Cancel</button>
      ) : (
        <button type="submit">Submit</button>
      )}
    </>
  )}
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post">
    {#snippet children({ processing, cancel })}
        <input type="text" name="name" />

        {#if processing}
            <button type="button" onclick={cancel}>Cancel</button>
        {:else}
            <button type="submit">Submit</button>
        {/if}
    {/snippet}
</Form>
```

Submissions continue running when the form that started them is removed from the page. Navigating away cancels the submission, but closing a modal that contains the form does not, so an abandoned upload still completes. You may use the `cancelOnUnmount` attribute to cancel an in-flight submission as soon as the form unmounts.

```vue tab=Vue
<template>
  <Form action="/avatar" method="post" cancel-on-unmount>
    <input type="file" name="avatar" />
    <button type="submit">Upload</button>
  </Form>
</template>
```

```jsx tab=React
<Form action="/avatar" method="post" cancelOnUnmount>
  <input type="file" name="avatar" />
  <button type="submit">Upload</button>
</Form>
```

```svelte tab=Svelte
<Form action="/avatar" method="post" cancelOnUnmount>
    <input type="file" name="avatar" />
    <button type="submit">Upload</button>
</Form>
```

<a name="forms-dotted-key-notation"></a>

#### Dotted Key Notation

The `<Form>` component supports dotted key notation for creating nested objects from flat input names. This provides a convenient way to structure form data.

```vue tab=Vue
<template>
  <Form action="/users" method="post">
    <input type="text" name="user.name" />
    <input type="text" name="user.skills[]" />
    <input type="text" name="address.street" />
    <button type="submit">Submit</button>
  </Form>
</template>
```

```jsx tab=React
<Form action="/users" method="post">
  <input type="text" name="user.name" />
  <input type="text" name="user.skills[]" />
  <input type="text" name="address.street" />
  <button type="submit">Submit</button>
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post">
    <input type="text" name="user.name" />
    <input type="text" name="user.skills[]" />
    <input type="text" name="address.street" />
    <button type="submit">Submit</button>
</Form>
```

The example above would generate the following data structure.

```json
{
  "user": {
    "name": "John Doe",
    "skills": ["JavaScript"]
  },
  "address": {
    "street": "123 Main St"
  }
}
```

If you need literal dots in your field names (not as nested object separators), you can escape them using backslashes.

```vue tab=Vue
<template>
  <Form action="/config" method="post">
    <input type="text" name="app\.name" />
    <input type="text" name="settings.theme\.mode" />
    <button type="submit">Save</button>
  </Form>
</template>
```

```jsx tab=React
<Form action="/config" method="post">
  <input type="text" name="app\.name" />
  <input type="text" name="settings.theme\.mode" />
  <button type="submit">Save</button>
</Form>
```

```svelte tab=Svelte
<Form action="/config" method="post">
    <input type="text" name="app\.name" />
    <input type="text" name="settings.theme\.mode" />
    <button type="submit">Save</button>
</Form>
```

The example above would generate the following data structure.

```json
{
  "app.name": "My Application",
  "settings": {
    "theme.mode": "dark"
  }
}
```

<a name="forms-programmatic-access"></a>

#### Programmatic Access

You can access the form's methods programmatically using refs. This provides an alternative to [slot props](#forms-slot-props) when you need to trigger form actions from outside the form.

```vue tab=Vue
<script setup>
import { ref } from "vue";
import { Form } from "@inertiajs/vue3";

const formRef = ref();

const handleSubmit = () => {
  formRef.value.submit();
};
</script>

<template>
  <Form ref="formRef" action="/users" method="post">
    <input type="text" name="name" />
    <button type="submit">Submit</button>
  </Form>

  <button @click="handleSubmit">Submit Programmatically</button>
</template>
```

```jsx tab=React
import { useRef } from 'react'
import { Form } from '@inertiajs/react'

export default function CreateUser() {
    const formRef = useRef()

    const handleSubmit = () => {
        formRef.current.submit()
    }

    return (
        <Form ref={formRef} action="/users" method="post">
            <input type="text" name="name" />
            <button type="submit">Submit</button>
        </Form>

        <button onClick={handleSubmit}>Submit Programmatically</button>
    )
}
```

```svelte tab=Svelte
<script>
import { Form } from '@inertiajs/svelte'

let formRef

function handleSubmit() {
    formRef.submit()
}
</script>

<Form bind:this={formRef} action="/users" method="post">
    <input type="text" name="name" />
    <button type="submit">Submit</button>
</Form>

<button onclick={handleSubmit}>Submit Programmatically</button>
```

In React and Vue, refs provide access to all form methods and reactive state. In Svelte, refs expose only methods, so reactive state like `isDirty` and `errors` should be accessed via [slot props](#forms-slot-props) instead.

<a name="forms-form-context"></a>

#### Form Context

Deeply nested child components may need access to form state or methods without passing props through multiple levels. The `useFormContext` hook provides access to the parent `<Form>` component's state and methods from any child component.

```vue tab=Vue
<script setup>
import { useFormContext } from "@inertiajs/vue3";

const form = useFormContext();
</script>

<template>
  <div v-if="form">
    <span v-if="form.isDirty">Unsaved changes</span>
    <span v-if="form.errors.name">{{ form.errors.name }}</span>
    <button type="button" @click="form.submit()">Submit</button>
    <button type="button" @click="form.reset()">Reset</button>
  </div>
</template>
```

```jsx tab=React
import { useFormContext } from "@inertiajs/react";

export default function FormActions() {
  const form = useFormContext();

  if (!form) {
    return null;
  }

  return (
    <div>
      {form.isDirty && <span>Unsaved changes</span>}
      {form.errors.name && <span>{form.errors.name}</span>}
      <button type="button" onClick={() => form.submit()}>
        Submit
      </button>
      <button type="button" onClick={() => form.reset()}>
        Reset
      </button>
    </div>
  );
}
```

```svelte tab=Svelte
<script>
import { useFormContext } from '@inertiajs/svelte'

const form = useFormContext()
</script>

{#if form}
    {#if form.isDirty}<span>Unsaved changes</span>{/if}
    {#if form.errors.name}<span>{form.errors.name}</span>{/if}
    <button type="button" onclick={() => form.submit()}>Submit</button>
    <button type="button" onclick={() => form.reset()}>Reset</button>
{/if}
```

The context provides access to all the same properties and methods available through [slot props](#forms-slot-props).

> [!NOTE]
> Both the `<Form>` component and `useFormContext` accept generic type parameters for type-safe errors and slot props. See the [TypeScript](#typescript-form-component) documentation for details.

<a name="forms-precognition"></a>

#### Precognition

The `<Form>` component includes built-in support for [Precognition](/docs/{{version}}/precognition), enabling real-time form validation without duplicating your server-side validation rules on the client.

> [!NOTE]
> Precognition requires server-side support. See the [Precognition documentation](/docs/{{version}}/precognition) for setup instructions.

Once your server is configured, call `validate()` with a field name to trigger validation for that field. The `invalid()` helper checks if a field has validation errors, while `validating` indicates when a request is in progress.

```vue tab=Vue
<template>
  <Form
    action="/users"
    method="post"
    #default="{ errors, invalid, validate, validating }"
  >
    <label for="name">Name:</label>
    <input id="name" name="name" @change="validate('name')" />
    <p v-if="invalid('name')">{{ errors.name }}</p>

    <label for="email">Email:</label>
    <input id="email" name="email" @change="validate('email')" />
    <p v-if="invalid('email')">{{ errors.email }}</p>

    <p v-if="validating">Validating...</p>

    <button type="submit">Create User</button>
  </Form>
</template>
```

```jsx tab=React
<Form action="/users" method="post">
  {({ errors, invalid, validate, validating }) => (
    <>
      <label htmlFor="name">Name:</label>
      <input id="name" name="name" onChange={() => validate("name")} />
      {invalid("name") && <p>{errors.name}</p>}

      <label htmlFor="email">Email:</label>
      <input id="email" name="email" onChange={() => validate("email")} />
      {invalid("email") && <p>{errors.email}</p>}

      {validating && <p>Validating...</p>}

      <button type="submit">Create User</button>
    </>
  )}
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post">
    {#snippet children({ errors, invalid, validate, validating })}
        <label for="name">Name:</label>
        <input id="name" name="name" onchange={() => validate('name')} />
        {#if invalid('name')}
            <p>{errors.name}</p>
        {/if}

        <label for="email">Email:</label>
        <input id="email" name="email" onchange={() => validate('email')} />
        {#if invalid('email')}
            <p>{errors.email}</p>
        {/if}

        {#if validating}
            <p>Validating...</p>
        {/if}

        <button type="submit">Create User</button>
    {/snippet}
</Form>
```

You may also use the `valid()` helper to check if a field has passed validation.

```vue tab=Vue
<Form
  action="/users"
  method="post"
  #default="{ errors, invalid, valid, validate }"
>
    <input name="email" @change="validate('email')" />
    <p v-if="valid('email')">Valid email address</p>
    <p v-if="invalid('email')">{{ errors.email }}</p>
</Form>
```

```jsx tab=React
<Form action="/users" method="post">
  {({ errors, invalid, valid, validate }) => (
    <>
      <input name="email" onChange={() => validate("email")} />
      {valid("email") && <p>Valid email address</p>}
      {invalid("email") && <p>{errors.email}</p>}
    </>
  )}
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post">
    {#snippet children({ errors, invalid, valid, validate })}
        <input name="email" onchange={() => validate('email')} />
        {#if valid('email')}
            <p>Valid email address</p>
        {/if}
        {#if invalid('email')}
            <p>{errors.email}</p>
        {/if}
    {/snippet}
</Form>
```

> [!WARNING]
> A form input will only appear as valid or invalid once it has changed and a
> validation response has been received. Calling `validate('field')` will not send a validation request until the field's value differs from the initial data.

<a name="forms-validating-multiple-fields"></a>

##### Validating Multiple Fields

You may validate multiple fields at once using the `only` option. This is particularly useful when building wizard-style forms where you want to validate all visible fields before proceeding to the next step.

```vue tab=Vue
<Form action="/users" method="post" #default="{ validate }">
    <!-- Step 1 fields -->
    <input name="name" />
    <input name="email" />

    <button
        type="button"
        @click="validate({
            only: ['name', 'email'],
            onSuccess: () => goToNextStep(),
            onValidationError: () => showErrors(),
        })"
    >
        Next Step
    </button>
</Form>
```

```jsx tab=React
<Form action="/users" method="post">
  {({ validate }) => (
    <>
      {/* Step 1 fields */}
      <input name="name" />
      <input name="email" />

      <button
        type="button"
        onClick={() =>
          validate({
            only: ["name", "email"],
            onSuccess: () => goToNextStep(),
            onValidationError: () => showErrors(),
          })
        }
      >
        Next Step
      </button>
    </>
  )}
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post">
    {#snippet children({ validate })}
        <!-- Step 1 fields -->
        <input name="name" />
        <input name="email" />

        <button
            type="button"
            onclick={() => validate({
                only: ['name', 'email'],
                onSuccess: () => goToNextStep(),
                onValidationError: () => showErrors(),
            })}
        >
            Next Step
        </button>
    {/snippet}
</Form>
```

<a name="forms-touch-and-validate"></a>

##### Touch and Validate

The `touch()` method marks fields as "touched" without triggering validation. You may then validate all touched fields by calling `validate()` without arguments.

```vue tab=Vue
<Form action="/users" method="post" #default="{ validate, touch, touched }">
    <input name="name" @blur="touch('name')" />
    <input name="email" @blur="touch('email')" />
    <input name="phone" @blur="touch('phone')" />

    <button type="button" @click="validate()">Validate Touched Fields</button>

    <p v-if="touched('name')">Name has been touched</p>
</Form>
```

```jsx tab=React
<Form action="/users" method="post">
  {({ validate, touch, touched }) => (
    <>
      <input name="name" onBlur={() => touch("name")} />
      <input name="email" onBlur={() => touch("email")} />
      <input name="phone" onBlur={() => touch("phone")} />

      <button type="button" onClick={() => validate()}>
        Validate Touched Fields
      </button>

      {touched("name") && <p>Name has been touched</p>}
    </>
  )}
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post">
    {#snippet children({ validate, touch, touched })}
        <input name="name" onblur={() => touch('name')} />
        <input name="email" onblur={() => touch('email')} />
        <input name="phone" onblur={() => touch('phone')} />

        <button type="button" onclick={() => validate()}>Validate Touched Fields</button>

        {#if touched('name')}
            <p>Name has been touched</p>
        {/if}
    {/snippet}
</Form>
```

The `touched()` helper may also be called without arguments to check if any field has been touched. The `reset()` method clears the touched state for reset fields.

<a name="forms-options"></a>

##### Options

The `validate()` method accepts an options object with callbacks and configuration.

```js
validate("username", {
  onSuccess: () => {
    // Validation passed...
  },
  onValidationError: (response) => {
    // Validation failed (422 response)...
  },
  onBeforeValidation: (newRequest, oldRequest) => {
    // Return false to prevent validation...
  },
  onFinish: () => {
    // Always runs after validation...
  },
});
```

You may also call `validate()` with only an options object to validate specific fields.

```js
validate({
  only: ["name", "email"],
  onSuccess: () => goToNextStep(),
});
```

Validation requests are automatically debounced. The first request fires immediately, then subsequent changes are debounced (1500ms by default). You may customize this timeout.

```vue tab=Vue
<Form action="/users" method="post" :validation-timeout="500">
    <!-- ... -->
</Form>
```

```jsx tab=React
<Form action="/users" method="post" validationTimeout={500}>
  {/* ... */}
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post" validationTimeout={500}>
    <!-- ... -->
</Form>
```

By default, files are excluded from validation requests to avoid unnecessary uploads. You may enable file validation when you need to validate file inputs like size or mime type.

```vue tab=Vue
<Form action="/users" method="post" validate-files>
    <!-- ... -->
</Form>
```

```jsx tab=React
<Form action="/users" method="post" validateFiles>
  {/* ... */}
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post" validateFiles>
    <!-- ... -->
</Form>
```

By default, validation errors are simplified to strings (the first error message). You may keep errors as arrays to display all error messages for fields with multiple validation rules.

```vue tab=Vue
<Form action="/users" method="post" with-all-errors>
    <!-- ... -->
</Form>
```

```jsx tab=React
<Form action="/users" method="post" withAllErrors>
  {/* ... */}
</Form>
```

```svelte tab=Svelte
<Form action="/users" method="post" withAllErrors>
    <!-- ... -->
</Form>
```

<a name="forms-form-helper"></a>

### Form Helper

In addition to the `<Form>` component, Inertia also provides a `useForm` helper for when you need programmatic control over your form's data and submission behavior.

```vue tab=Vue
<script setup>
import { useForm } from "@inertiajs/vue3";

const form = useForm({
  email: null,
  password: null,
  remember: false,
});
</script>

<template>
  <form @submit.prevent="form.post('/login')">
    <input type="text" v-model="form.email" />
    <div v-if="form.errors.email">{{ form.errors.email }}</div>
    <input type="password" v-model="form.password" />
    <div v-if="form.errors.password">{{ form.errors.password }}</div>
    <input type="checkbox" v-model="form.remember" /> Remember Me
    <button type="submit" :disabled="form.processing">Login</button>
  </form>
</template>
```

```jsx tab=React
import { useForm } from "@inertiajs/react";

const { data, setData, post, processing, errors } = useForm({
  email: "",
  password: "",
  remember: false,
});

function submit(e) {
  e.preventDefault();
  post("/login");
}

return (
  <form onSubmit={submit}>
    <input
      type="text"
      value={data.email}
      onChange={(e) => setData("email", e.target.value)}
    />
    {errors.email && <div>{errors.email}</div>}
    <input
      type="password"
      value={data.password}
      onChange={(e) => setData("password", e.target.value)}
    />
    {errors.password && <div>{errors.password}</div>}
    <input
      type="checkbox"
      checked={data.remember}
      onChange={(e) => setData("remember", e.target.checked)}
    />{" "}
    Remember Me
    <button type="submit" disabled={processing}>
      Login
    </button>
  </form>
);
```

```svelte tab=Svelte
<script>
import { useForm } from '@inertiajs/svelte'

const form = useForm({
    email: null,
    password: null,
    remember: false,
})

function submit(e) {
    e.preventDefault()
    form.post('/login')
}
</script>

<form onsubmit={submit}>
    <input type="text" bind:value={form.email} />
    {#if form.errors.email}
        <div class="form-error">{form.errors.email}</div>
    {/if}
    <input type="password" bind:value={form.password} />
    {#if form.errors.password}
        <div class="form-error">{form.errors.password}</div>
    {/if}
    <input type="checkbox" bind:checked={form.remember} /> Remember Me
    <button type="submit" disabled={form.processing}>Submit</button>
</form>
```

To submit the form, you may use the `get`, `post`, `put`, `patch` and `delete` methods.

```js tab=Vue
form.submit(method, url, options);
form.get(url, options);
form.post(url, options);
form.put(url, options);
form.patch(url, options);
form.delete(url, options);
```

```js tab=React
const { submit, get, post, put, patch, delete: destroy } = useForm({ ... })

submit(method, url, options)
get(url, options)
post(url, options)
put(url, options)
patch(url, options)
destroy(url, options)
```

```js tab=Svelte
form.submit(method, url, options);
form.get(url, options);
form.post(url, options);
form.put(url, options);
form.patch(url, options);
form.delete(url, options);
```

The submit methods support all of the typical [visit options](#manual-visits), such as `preserveState`, `preserveScroll`, and event callbacks, which can be helpful for performing tasks on successful form submissions. For example, you might use the `onSuccess` callback to reset inputs to their original state.

```js tab=Vue
form.post("/profile", {
  preserveScroll: true,
  onSuccess: () => form.reset("password"),
});
```

```js tab=React
const { post, reset } = useForm({ ... })

post('/profile', {
    preserveScroll: true,
    onSuccess: () => reset('password'),
})
```

```js tab=Svelte
form.post("/profile", {
  preserveScroll: true,
  onSuccess: () => form.reset("password"),
});
```

You may pass the HTTP method and URL as the first two arguments to `useForm()`, then call `submit()` without arguments to dispatch the request. This also unlocks real-time validation. See [Precognition](#forms-precognition-2) for details.

If you need to modify the form data before it's sent to the server, you can do so via the `transform()` method.

```js tab=Vue
form
  .transform((data) => ({
    ...data,
    remember: data.remember ? "on" : "",
  }))
  .post("/login");
```

```js tab=React
const { transform } = useForm({ ... })

transform((data) => ({
    ...data,
    remember: data.remember ? 'on' : '',
}))
```

```js tab=Svelte
form
  .transform((data) => ({
    ...data,
    remember: data.remember ? "on" : "",
  }))
  .post("/login");
```

You can use the `processing` property to track if a form is currently being submitted. This can be helpful for preventing double form submissions by disabling the submit button.

```vue tab=Vue
<button type="submit" :disabled="form.processing">Submit</button>
```

```jsx tab=React
const { processing } = useForm({ ... })

<button type="submit" disabled={processing}>Submit</button>
```

```svelte tab=Svelte
<button type="submit" disabled={form.processing}>Submit</button>
```

If your form is uploading files, the current progress event is available via the `progress` property, allowing you to easily display the upload progress.

```vue tab=Vue
<progress v-if="form.progress" :value="form.progress.percentage" max="100">
    {{ form.progress.percentage }}%
</progress>
```

```jsx tab=React
const { progress } = useForm({ ... })

{progress && (
    <progress value={progress.percentage} max="100">
        {progress.percentage}%
    </progress>
)}
```

```svelte tab=Svelte
{#if form.progress}
    <progress value={form.progress.percentage} max="100">
        {form.progress.percentage}%
    </progress>
{/if}
```

<a name="forms-form-errors"></a>

#### Form Errors

If there are form validation errors, they are available via the `errors` property. When building Hypervel-powered Inertia applications, form errors will automatically be populated when your application throws instances of `ValidationException`, such as when using `$request->validate()`.

```vue tab=Vue
<div v-if="form.errors.email">{{ form.errors.email }}</div>
```

```jsx tab=React
const { errors } = useForm({ ... })

{errors.email && <div>{errors.email}</div>}
```

```svelte tab=Svelte
{#if form.errors.email}
    <div>{form.errors.email}</div>
{/if}
```

For a more thorough discussion of form validation and errors, please consult the [validation documentation](#validation).

To determine if a form has any errors, you may use the `hasErrors` property. To clear form errors, use the `clearErrors()` method.

```js tab=Vue
// Clear all errors...
form.clearErrors();

// Clear errors for specific fields...
form.clearErrors("field", "anotherfield");
```

```js tab=React
const { clearErrors } = useForm({ ... })

// Clear all errors...
clearErrors()

// Clear errors for specific fields...
clearErrors('field', 'anotherfield')
```

```js tab=Svelte
// Clear all errors...
form.clearErrors();

// Clear errors for specific fields...
form.clearErrors("field", "anotherfield");
```

If you're using client-side input validation libraries or do client-side validation manually, you can set your own errors on the form using the `setErrors()` method.

```js tab=Vue
// Set a single error...
form.setError("field", "Your error message.");

// Set multiple errors at once...
form.setError({
  foo: "Your error message for the foo field.",
  bar: "Some other error for the bar field.",
});
```

```js tab=React
const { setError } = useForm({ ... })

// Set a single error...
setError('field', 'Your error message.');

// Set multiple errors at once...
setError({
    foo: 'Your error message for the foo field.',
    bar: 'Some other error for the bar field.'
});
```

```js tab=Svelte
// Set a single error
form.setError("field", "Your error message.");

// Set multiple errors at once
form.setError({
  foo: "Your error message for the foo field.",
  bar: "Some other error for the bar field.",
});
```

Unlike an actual form submission, the page's props remain unchanged when manually setting errors on a form instance.

When a form has been successfully submitted, the `wasSuccessful` property will be `true`. In addition to this, forms have a `recentlySuccessful` property, which will be set to `true` for two seconds after a successful form submission. This property can be utilized to show temporary success messages.

You may customize the duration of the `recentlySuccessful` state by setting the `form.recentlySuccessfulDuration` option in your [application defaults](#client-side-setup-configuring-defaults). The default value is `2000` milliseconds.

<a name="forms-resetting-the-form-2"></a>

#### Resetting the Form

To reset the form's values back to their default values, you can use the `reset()` method.

```js tab=Vue
// Reset the form...
form.reset();

// Reset specific fields...
form.reset("field", "anotherfield");
```

```js tab=React
const { reset } = useForm({ ... })

// Reset the form...
reset()

// Reset specific fields...
reset('field', 'anotherfield')
```

```js tab=Svelte
// Reset the form...
form.reset();

// Reset specific fields...
form.reset("field", "anotherfield");
```

Sometimes, you may want to restore your form fields to their default values and clear any validation errors at the same time. Instead of calling `reset()` and `clearErrors()` separately, you can use the `resetAndClearErrors()` method, which combines both actions into a single call.

```js tab=Vue
// Reset the form and clear all errors...
form.resetAndClearErrors();

// Reset specific fields and clear their errors...
form.resetAndClearErrors("field", "anotherfield");
```

```js tab=React
const { resetAndClearErrors } = useForm({ ... })

// Reset the form and clear all errors...
resetAndClearErrors()

// Reset specific fields and clear their errors...
resetAndClearErrors('field', 'anotherfield')
```

```js tab=Svelte
// Reset the form and clear all errors...
form.resetAndClearErrors();

// Reset specific fields and clear their errors...
form.resetAndClearErrors("field", "anotherfield");
```

<a name="forms-setting-new-default-values-2"></a>

#### Setting New Default Values

If your form's default values become outdated, you can use the `defaults()` method to update them. Then, the form will be reset to the correct values the next time the `reset()` method is invoked.

```js tab=Vue
// Set the form's current values as the new defaults...
form.defaults();

// Update the default value of a single field...
form.defaults("email", "updated-default@example.com");

// Update the default value of multiple fields...
form.defaults({
  name: "Updated Example",
  email: "updated-default@example.com",
});
```

```js tab=React
const { setDefaults } = useForm({ ... })

// Set the form's current values as the new defaults...
setDefaults()

// Update the default value of a single field...
setDefaults('email', 'updated-default@example.com')

// Update the default value of multiple fields...
setDefaults({
    name: 'Updated Example',
    email: 'updated-default@example.com',
})
```

```js tab=Svelte
// Set the form's current values as the new defaults...
form.defaults();

// Update the default value of a single field...
form.defaults("email", "updated-default@example.com");

// Change the default value of multiple fields...
form.defaults({
  name: "Updated Example",
  email: "updated-default@example.com",
});
```

<a name="forms-form-field-change-tracking"></a>

#### Form Field Change Tracking

To determine if a form has any changes, you may use the `isDirty` property.

```vue tab=Vue
<div v-if="form.isDirty">There are unsaved form changes.</div>
```

```jsx tab=React
const { isDirty } = useForm({ ... })

{isDirty && <div>There are unsaved form changes.</div>}
```

```svelte tab=Svelte
{#if form.isDirty}
    <div>There are unsaved form changes.</div>
{/if}
```

<a name="forms-canceling-form-submissions-2"></a>

#### Canceling Form Submissions

To cancel a form submission, use the `cancel()` method.

```js tab=Vue
form.cancel();
```

```js tab=React
const { cancel } = useForm({ ... })

cancel()
```

```js tab=Svelte
form.cancel();
```

<a name="forms-form-data-and-history-state"></a>

#### Form Data and History State

To instruct Inertia to store a form's data and errors in [history state](#remembering-state), you can provide a unique form key as the first argument when instantiating your form.

```js tab=Vue
import { useForm } from '@inertiajs/vue3'

const form = useForm('CreateUser', data)
const form = useForm(`EditUser:${user.id}`, data)
```

```js tab=React
import { useForm } from '@inertiajs/react'

const form = useForm('CreateUser', data)
const form = useForm(`EditUser:${user.id}`, data)
```

```js tab=Svelte
import { useForm } from '@inertiajs/svelte'

const form = useForm('CreateUser', data)
const form = useForm(`EditUser:${user.id}`, data)
```

<a name="forms-excluding-fields"></a>

##### Excluding Fields

Sensitive fields like passwords may be excluded from history state using the `dontRemember()` method.

```js tab=Vue
import { useForm } from "@inertiajs/vue3";

const form = useForm("LoginForm", {
  email: "",
  password: "",
}).dontRemember("password");
```

```js tab=React
import { useForm } from "@inertiajs/react";

const form = useForm("LoginForm", {
  email: "",
  password: "",
}).dontRemember("password");
```

```js tab=Svelte
import { useForm } from "@inertiajs/svelte";

const form = useForm("LoginForm", {
  email: "",
  password: "",
}).dontRemember("password");
```

Multiple fields may be excluded by passing additional arguments.

```js
form.dontRemember("password", "password_confirmation");
```

> [!NOTE]
> Some browsers trigger a "save password" prompt whenever password field values
> are written to history state, even without form submission. Excluding password
> fields avoids this issue.

<a name="forms-wayfinder-2"></a>

#### Wayfinder

When using [Wayfinder](/docs/{{version}}/wayfinder) in conjunction with the form helper, you can simply pass the resulting object directly to the `form.submit` method. The form helper will infer the HTTP method and URL from the Wayfinder object.

```js tab=Vue
import { useForm } from "@inertiajs/vue3";
import { store } from "@/actions/App/Http/Controllers/UserController";

const form = useForm({
  name: "John Doe",
  email: "john.doe@example.com",
});

form.submit(store());
```

```js tab=React
import { useForm } from "@inertiajs/react";
import { store } from "@/actions/App/Http/Controllers/UserController";

const form = useForm({
  name: "John Doe",
  email: "john.doe@example.com",
});

form.submit(store());
```

```js tab=Svelte
import { useForm } from "@inertiajs/svelte";
import { store } from "@/actions/App/Http/Controllers/UserController";

const form = useForm({
  name: "John Doe",
  email: "john.doe@example.com",
});

form.submit(store());
```

<a name="forms-precognition-2"></a>

#### Precognition

Just like the `<Form>` component, the `useForm` helper supports [Precognition](#forms-precognition) for real-time validation. You may enable it by chaining the `withPrecognition()` method with the HTTP method and endpoint for validation requests.

> [!NOTE]
> Precognition requires server-side support. See the [Precognition documentation](/docs/{{version}}/precognition) for setup instructions.

```js tab=Vue
import { useForm } from "@inertiajs/vue3";

const form = useForm({
  name: "",
  email: "",
}).withPrecognition("post", "/users");
```

```js tab=React
import { useForm } from "@inertiajs/react";

const form = useForm({
  name: "",
  email: "",
}).withPrecognition("post", "/users");
```

```js tab=Svelte
import { useForm } from "@inertiajs/svelte";

const form = useForm({
  name: "",
  email: "",
}).withPrecognition("post", "/users");
```

You may also pass the method and URL as the first arguments to `useForm()`.

```js
const form = useForm("post", "/users", {
  name: "",
  email: "",
});
```

You may also use [Wayfinder](/docs/{{version}}/wayfinder) when enabling Precognition.

```js
import { store } from "@/actions/App/Http/Controllers/UserController";

const form = useForm({
  name: "",
  email: "",
}).withPrecognition(store());

// Or passing Wayfinder as the first argument...
const form = useForm(store(), {
  name: "",
  email: "",
});
```

Once Precognition is enabled, call `validate()` with a field name to trigger validation for that field. The `invalid()` helper checks if a field has validation errors, while `validating` indicates when a request is in progress.

```vue tab=Vue
<script setup>
import { useForm } from "@inertiajs/vue3";

const form = useForm("post", "/users", {
  name: "",
  email: "",
});
</script>

<template>
  <form @submit.prevent="form.submit()">
    <input v-model="form.name" @change="form.validate('name')" />
    <p v-if="form.invalid('name')">{{ form.errors.name }}</p>

    <input v-model="form.email" @change="form.validate('email')" />
    <p v-if="form.invalid('email')">{{ form.errors.email }}</p>

    <p v-if="form.validating">Validating...</p>

    <button type="submit">Create User</button>
  </form>
</template>
```

```jsx tab=React
import { useForm } from "@inertiajs/react";

const { data, setData, submit, errors, validating, validate, invalid } = useForm(
  "post",
  "/users",
  {
    name: "",
    email: "",
  },
);

function handleSubmit(e) {
  e.preventDefault();
  submit();
}

return (
  <form onSubmit={handleSubmit}>
    <input
      value={data.name}
      onChange={(e) => setData("name", e.target.value)}
      onBlur={() => validate("name")}
    />
    {invalid("name") && <p>{errors.name}</p>}

    <input
      value={data.email}
      onChange={(e) => setData("email", e.target.value)}
      onBlur={() => validate("email")}
    />
    {invalid("email") && <p>{errors.email}</p>}

    {validating && <p>Validating...</p>}

    <button type="submit">Create User</button>
  </form>
);
```

```svelte tab=Svelte
<script>
import { useForm } from '@inertiajs/svelte'

const form = useForm('post', '/users', {
    name: '',
    email: '',
})
</script>

<form onsubmit={(e) => { e.preventDefault(); form.submit() }}>
    <input bind:value={form.name} onchange={() => form.validate('name')} />
    {#if form.invalid('name')}
        <p>{form.errors.name}</p>
    {/if}

    <input bind:value={form.email} onchange={() => form.validate('email')} />
    {#if form.invalid('email')}
        <p>{form.errors.email}</p>
    {/if}

    {#if form.validating}
        <p>Validating...</p>
    {/if}

    <button type="submit">Create User</button>
</form>
```

You may also use the `valid()` helper to check if a field has passed validation.

<a name="forms-touch-and-validate-2"></a>

##### Touch and Validate

The `touch()` method marks fields as "touched" without triggering validation. You may then validate all touched fields by calling `validate()` without arguments. The `touched()` helper checks if a field has been touched. The `reset()` method clears the touched state for reset fields.

```vue tab=Vue
<input v-model="form.name" @blur="form.touch('name')" />
<input v-model="form.email" @blur="form.touch('email')" />

<button type="button" @click="form.validate()">Validate Touched Fields</button>

<p v-if="form.touched('name')">Name has been touched</p>
```

```jsx tab=React
<input value={data.name} onChange={e => setData('name', e.target.value)} onBlur={() => touch('name')} />
<input value={data.email} onChange={e => setData('email', e.target.value)} onBlur={() => touch('email')} />

<button type="button" onClick={() => validate()}>Validate Touched Fields</button>

{touched('name') && <p>Name has been touched</p>}
```

```svelte tab=Svelte
<input bind:value={form.name} onblur={() => form.touch('name')} />
<input bind:value={form.email} onblur={() => form.touch('email')} />

<button type="button" onclick={() => form.validate()}>Validate Touched Fields</button>

{#if form.touched('name')}
    <p>Name has been touched</p>
{/if}
```

<a name="forms-options-2"></a>

##### Options

Validation requests are automatically debounced. The first request fires immediately, then subsequent changes are debounced (1500ms by default). You may customize this timeout using `setValidationTimeout()`.

```js tab=Vue
const form = useForm("post", "/users", {
  name: "",
}).setValidationTimeout(500);
```

```js tab=React
const form = useForm("post", "/users", {
  name: "",
});

form.setValidationTimeout(500);
```

```js tab=Svelte
const form = useForm("post", "/users", {
  name: "",
});

form.setValidationTimeout(500);
```

By default, files are excluded from validation requests to avoid unnecessary uploads. You may enable file validation using `validateFiles()`.

```js tab=Vue
const form = useForm("post", "/users", {
  avatar: null,
}).validateFiles();
```

```js tab=React
const form = useForm("post", "/users", {
  avatar: null,
});

form.validateFiles();
```

```js tab=Svelte
const form = useForm("post", "/users", {
  avatar: null,
});

form.validateFiles();
```

By default, validation errors are simplified to strings (the first error message). You can indicate you would like all errors as arrays using `withAllErrors()`.

```js tab=Vue
const form = useForm("post", "/users", {
  name: "",
}).withAllErrors();
```

```js tab=React
const form = useForm("post", "/users", {
  name: "",
});

form.withAllErrors();
```

```js tab=Svelte
const form = useForm("post", "/users", {
  name: "",
});

form.withAllErrors();
```

With Precognition enabled, you may call `submit()` without arguments to dispatch the request to the configured endpoint.

<a name="forms-server-side-responses"></a>

### Server-Side Responses

When using Inertia, you don't typically inspect form responses client-side like you would with traditional XHR/fetch requests. Instead, your server-side route or controller issues a [redirect](#redirects) response after processing the form, often redirecting to a success page.

```php
class UsersController extends Controller
{
    public function index()
    {
        return Inertia::render('Users/Index', [
            'users' => User::all(),
        ]);
    }

    public function store(Request $request)
    {
        User::create($request->validate([
            'first_name' => ['required', 'max:50'],
            'last_name' => ['required', 'max:50'],
            'email' => ['required', 'max:50', 'email'],
        ]));

        return to_route('users.index');
    }
}
```

This redirect-based approach works with all form submission methods: the `<Form>` component, `useForm` helper, and manual router submissions. It makes handling Inertia forms feel very similar to classic server-side form submissions.

<a name="forms-server-side-validation"></a>

### Server-Side Validation

Both the `<Form>` component and `useForm` helper automatically handle server-side validation errors. When your server returns validation errors, they're automatically available in the `errors` object without any additional configuration.

Unlike traditional XHR/fetch requests where you might check for a `422` status code, Inertia handles validation errors as part of its redirect-based flow, just like classic server-side form submissions, but without the full page reload.

For a complete guide on validation error handling, including error bags and advanced scenarios, see the [validation documentation](#validation).

<a name="forms-manual-form-submissions"></a>

### Manual Form Submissions

It's also possible to submit forms manually using Inertia's `router` methods directly, without using the `<Form>` component or `useForm` helper:

```vue tab=Vue
<script setup>
import { reactive } from "vue";
import { router } from "@inertiajs/vue3";

const form = reactive({
  first_name: null,
  last_name: null,
  email: null,
});

function submit() {
  router.post("/users", form);
}
</script>

<template>
  <form @submit.prevent="submit">
    <label for="first_name">First name:</label>
    <input id="first_name" v-model="form.first_name" />
    <label for="last_name">Last name:</label>
    <input id="last_name" v-model="form.last_name" />
    <label for="email">Email:</label>
    <input id="email" v-model="form.email" />
    <button type="submit">Submit</button>
  </form>
</template>
```

```jsx tab=React
import { useState } from "react";
import { router } from "@inertiajs/react";

export default function Edit() {
  const [values, setValues] = useState({
    first_name: "",
    last_name: "",
    email: "",
  });

  function handleChange(e) {
    const key = e.target.id;
    const value = e.target.value;
    setValues((values) => ({
      ...values,
      [key]: value,
    }));
  }

  function handleSubmit(e) {
    e.preventDefault();
    router.post("/users", values);
  }

  return (
    <form onSubmit={handleSubmit}>
      <label htmlFor="first_name">First name:</label>
      <input
        id="first_name"
        value={values.first_name}
        onChange={handleChange}
      />
      <label htmlFor="last_name">Last name:</label>
      <input id="last_name" value={values.last_name} onChange={handleChange} />
      <label htmlFor="email">Email:</label>
      <input id="email" value={values.email} onChange={handleChange} />
      <button type="submit">Submit</button>
    </form>
  );
}
```

```svelte tab=Svelte
<script>
import { router } from '@inertiajs/svelte'

let values = {
    first_name: null,
    last_name: null,
    email: null,
}

function submit(e) {
    e.preventDefault()
    router.post('/users', values)
}
</script>

<form onsubmit={submit}>
    <label for="first_name">First name:</label>
    <input id="first_name" bind:value={values.first_name}>

    <label for="last_name">Last name:</label>
    <input id="last_name" bind:value={values.last_name}>

    <label for="email">Email:</label>
    <input id="email" bind:value={values.email}>

    <button type="submit">Submit</button>
</form>
```

<a name="forms-file-uploads"></a>

### File Uploads

When making requests or form submissions that include files, Inertia will automatically convert the request data into a `FormData` object. This works with the `<Form>` component, `useForm` helper, and manual router submissions.

For more information on file uploads, including progress tracking, see the [file uploads documentation](#file-uploads).

<a name="forms-optimistic-updates"></a>

### Optimistic Updates

Both the `<Form>` component and `useForm` helper support optimistic updates, allowing you to update the UI immediately before the server responds. For more details, see the [optimistic updates](#optimistic-updates) documentation.

<a name="forms-non-inertia-submissions"></a>

### Non-Inertia Submissions

Using Inertia to submit forms works great for the vast majority of situations. For standalone HTTP requests that don't trigger page visits, you may use the [`useHttp`](#http-requests) hook, which provides the same developer experience as `useForm`. You're also free to make plain XHR or `fetch` requests using the library of your choice.

<a name="http-requests"></a>

## HTTP Requests

Not every request needs to trigger an Inertia page visit. For calls to an external API or fetching data from a non-Inertia endpoint, the `useHttp` hook provides the same developer experience as `useForm`, but for standalone HTTP requests.

<a name="http-requests-basic-usage"></a>

### Basic Usage

The `useHttp` hook accepts initial data and returns reactive state along with methods for making HTTP requests.

```vue tab=Vue
<script setup>
import { useHttp } from '@inertiajs/vue3'

const http = useHttp({
    query: '',
})

function search() {
    http.get('/api/search', {
        onSuccess: (response) => {
            console.log(response)
        },
    })
}
</script>

<template>
    <input v-model="http.query" @input="search" />
    <div v-if="http.processing">Searching...</div>
</template>
```

```jsx tab=React
import { useHttp } from '@inertiajs/react'

export default function Search() {
    const { data, setData, get, processing } = useHttp({
        query: '',
    })

    function search(e) {
        setData('query', e.target.value)
        get('/api/search', {
            onSuccess: (response) => {
                console.log(response)
            },
        })
    }

    return (
        <>
            <input value={data.query} onChange={search} />
            {processing && <div>Searching...</div>}
        </>
    )
}
```

```svelte tab=Svelte
<script>
import { useHttp } from '@inertiajs/svelte'

const http = useHttp({
    query: '',
})

function search() {
    http.get('/api/search', {
        onSuccess: (response) => {
            console.log(response)
        },
    })
}
</script>

<input bind:value={http.query} oninput={search} />
{#if http.processing}
    <div>Searching...</div>
{/if}
```

Unlike router visits, `useHttp` requests do not trigger page navigation or interact with Inertia's page lifecycle. They are plain HTTP requests that return JSON responses.

<a name="http-requests-submitting-data"></a>

### Submitting Data

The hook provides `get`, `post`, `put`, `patch`, and `delete` convenience methods. A generic `submit` method is also available for dynamic HTTP methods.

```js tab=Vue
http.get(url, options)
http.post(url, options)
http.put(url, options)
http.patch(url, options)
http.delete(url, options)
http.submit(method, url, options)
```

```js tab=React
const { get, post, put, patch, delete: destroy, submit } = useHttp({ ... })

get(url, options)
post(url, options)
put(url, options)
patch(url, options)
destroy(url, options)
submit(method, url, options)
```

```js tab=Svelte
http.get(url, options)
http.post(url, options)
http.put(url, options)
http.patch(url, options)
http.delete(url, options)
http.submit(method, url, options)
```

Each method returns a `Promise` that resolves with the parsed JSON response data. TypeScript users may [type the request data and response](#typescript-http-helper).

<a name="http-requests-pre-binding-the-endpoint"></a>

#### Pre-binding the Endpoint

You may pass the HTTP method and URL as the first two arguments to `useHttp()`, then call `submit()` without arguments to dispatch the request. This also unlocks real-time validation. See [Precognition](#http-requests-precognition) for details.

```js tab=Vue
const response = await http.post('/api/comments', {
    onError: (errors) => {
        console.log(errors)
    },
})
```

```js tab=React
const response = await post('/api/comments', {
    onError: (errors) => {
        console.log(errors)
    },
})
```

```js tab=Svelte
const response = await http.post('/api/comments', {
    onError: (errors) => {
        console.log(errors)
    },
})
```

<a name="http-requests-multiple-requests"></a>

### Multiple Requests

Each `useHttp` instance tracks its own `processing`, `errors`, and other reactive state. When making independent requests, you may create a separate instance for each one so their states don't collide.

```js tab=Vue
const search = useHttp({ query: '' })
const upload = useHttp({ file: null })
```

```js tab=React
const search = useHttp({ query: '' })
const upload = useHttp({ file: null })
```

```js tab=Svelte
const search = useHttp({ query: '' })
const upload = useHttp({ file: null })
```

<a name="http-requests-reactive-state"></a>

### Reactive State

The `useHttp` hook exposes the same reactive properties as `useForm`:

| Property             | Type             | Description                                       |
| -------------------- | ---------------- | ------------------------------------------------- |
| `errors`             | `object`         | Validation errors keyed by field name             |
| `hasErrors`          | `boolean`        | Whether validation errors exist                   |
| `processing`         | `boolean`        | Whether a request is in progress                  |
| `progress`           | `object \| null` | Upload progress with `percentage` and `total`     |
| `wasSuccessful`      | `boolean`        | Whether the last request was successful           |
| `recentlySuccessful` | `boolean`        | `true` for two seconds after a successful request |
| `isDirty`            | `boolean`        | Whether the data differs from its defaults        |

<a name="http-requests-validation-errors"></a>

### Validation Errors

When a request returns a `422` status code, the hook automatically parses validation errors and makes them available through the `errors` property.

```vue tab=Vue
<script setup>
import { useHttp } from '@inertiajs/vue3'

const http = useHttp({
    name: '',
    email: '',
})

function save() {
    http.post('/api/users')
}
</script>

<template>
    <input v-model="http.name" />
    <div v-if="http.errors.name">{{ http.errors.name }}</div>

    <input v-model="http.email" />
    <div v-if="http.errors.email">{{ http.errors.email }}</div>

    <button @click="save" :disabled="http.processing">Save</button>
</template>
```

```jsx tab=React
import { useHttp } from '@inertiajs/react'

export default function CreateUser() {
    const { data, setData, post, errors, processing } = useHttp({
        name: '',
        email: '',
    })

    function save(e) {
        e.preventDefault()
        post('/api/users')
    }

    return (
        <form onSubmit={save}>
            <input value={data.name} onChange={e => setData('name', e.target.value)} />
            {errors.name && <div>{errors.name}</div>}

            <input value={data.email} onChange={e => setData('email', e.target.value)} />
            {errors.email && <div>{errors.email}</div>}

            <button type="submit" disabled={processing}>Save</button>
        </form>
    )
}
```

```svelte tab=Svelte
<script>
import { useHttp } from '@inertiajs/svelte'

const http = useHttp({
    name: '',
    email: '',
})

function save() {
    http.post('/api/users')
}
</script>

<input bind:value={http.name} />
{#if http.errors.name}
    <div>{http.errors.name}</div>
{/if}

<input bind:value={http.email} />
{#if http.errors.email}
    <div>{http.errors.email}</div>
{/if}

<button onclick={save} disabled={http.processing}>Save</button>
```

<a name="http-requests-displaying-all-errors"></a>

### Displaying All Errors

By default, validation errors are simplified to the first error message for each field. You may chain `withAllErrors()` to receive all error messages as arrays, which is useful for fields with multiple validation rules.

```js tab=Vue
const http = useHttp({
    name: '',
    email: '',
}).withAllErrors()

// http.errors.name === ['Name is required.', 'Name must be at least 3 characters.']
```

```js tab=React
const http = useHttp({
    name: '',
    email: '',
}).withAllErrors()

// http.errors.name === ['Name is required.', 'Name must be at least 3 characters.']
```

```js tab=Svelte
const http = useHttp({
    name: '',
    email: '',
}).withAllErrors()

// http.errors.name === ['Name is required.', 'Name must be at least 3 characters.']
```

The same method is available on the [`useForm`](#forms-options-2) helper and the [`<Form>`](#forms-options) component.

<a name="http-requests-file-uploads"></a>

### File Uploads

When the data includes files, the hook automatically sends the request as `multipart/form-data`. Upload progress is available through the `progress` property.

```vue tab=Vue
<script setup>
import { useHttp } from '@inertiajs/vue3'

const http = useHttp({
    file: null,
})

function upload() {
    http.post('/api/uploads')
}
</script>

<template>
    <input type="file" @change="http.file = $event.target.files[0]" />
    <progress v-if="http.progress" :value="http.progress.percentage" max="100" />
    <button @click="upload" :disabled="http.processing">Upload</button>
</template>
```

```jsx tab=React
import { useHttp } from '@inertiajs/react'

export default function Upload() {
    const { setData, post, progress, processing } = useHttp({
        file: null,
    })

    return (
        <>
            <input type="file" onChange={e => setData('file', e.target.files[0])} />
            {progress && <progress value={progress.percentage} max="100" />}
            <button onClick={() => post('/api/uploads')} disabled={processing}>Upload</button>
        </>
    )
}
```

```svelte tab=Svelte
<script>
import { useHttp } from '@inertiajs/svelte'

const http = useHttp({
    file: null,
})

function upload() {
    http.post('/api/uploads')
}
</script>

<input type="file" onchange={e => http.file = e.target.files[0]} />
{#if http.progress}
    <progress value={http.progress.percentage} max="100" />
{/if}
<button onclick={upload} disabled={http.processing}>Upload</button>
```

<a name="http-requests-canceling-requests"></a>

### Canceling Requests

You may cancel an in-progress request using the `cancel()` method.

```js tab=Vue
http.cancel()
```

```js tab=React
const { cancel } = useHttp({ ... })

cancel()
```

```js tab=Svelte
http.cancel()
```

<a name="http-requests-optimistic-updates"></a>

### Optimistic Updates

The `useHttp` hook supports [optimistic updates](#optimistic-updates) via the `optimistic()` method. The callback receives the current data and should return a partial update to apply immediately.

```js tab=Vue
http.optimistic((data) => ({
    likes: data.likes + 1,
})).post('/api/likes')
```

```js tab=React
const { optimistic, post } = useHttp({ likes: 0 })

optimistic((data) => ({
    likes: data.likes + 1,
}))
post('/api/likes')
```

```js tab=Svelte
http.optimistic((data) => ({
    likes: data.likes + 1,
})).post('/api/likes')
```

The update is applied synchronously. If the request fails, the data is rolled back to its previous state.

<a name="http-requests-event-callbacks"></a>

### Event Callbacks

Each submit method accepts an options object with lifecycle callbacks:

```js
http.post('/api/users', {
    onBefore: () => { ... },
    onStart: () => { ... },
    onProgress: (progress) => { ... },
    onSuccess: (data, response) => { ... },
    onError: (errors) => { ... },
    onHttpException: (response) => { ... },
    onNetworkError: (error) => { ... },
    onCancel: () => { ... },
    onFinish: () => { ... },
})
```

You may return `false` from `onBefore` to cancel the request.

<a name="http-requests-accessing-the-http-response"></a>

#### Accessing the HTTP response

The `onSuccess` callback receives the parsed response data as its first argument and the HTTP response object as its second argument. The response object contains `status`, `data`, and `headers` properties, which is useful when you need to inspect the status code or response headers.

```js
http.post('/api/users', {
    onSuccess: (data, response) => {
        console.log(response.status) // 200, 201, etc.
    },
})
```

<a name="http-requests-http-exceptions"></a>

#### HTTP exceptions

The `onHttpException` callback fires when the server returns a non-422 HTTP error, such as a `500` or `403`. The callback receives the HTTP response object, giving you access to the status code, response body, and headers.

```js
http.post('/api/users', {
    onHttpException: (response) => {
        console.log(response.status) // 500, 403, etc.
        console.log(response.data)   // Response body
    },
})
```

<a name="http-requests-network-errors"></a>

#### Network errors

The `onNetworkError` callback fires when the request fails due to a network issue, such as when the user loses their internet connection. The callback receives the `Error` object.

```js
http.post('/api/users', {
    onNetworkError: (error) => {
        console.log(error.message)
    },
})
```

<a name="http-requests-precognition"></a>

### Precognition

The `useHttp` hook supports [Precognition](/docs/{{version}}/precognition) for real-time validation. Enable it by chaining `withPrecognition()` with the HTTP method and validation endpoint.

```js tab=Vue
import { useHttp } from '@inertiajs/vue3'

const http = useHttp({
    name: '',
    email: '',
}).withPrecognition('post', '/api/users')
```

```js tab=React
import { useHttp } from '@inertiajs/react'

const http = useHttp({
    name: '',
    email: '',
}).withPrecognition('post', '/api/users')
```

```js tab=Svelte
import { useHttp } from '@inertiajs/svelte'

const http = useHttp({
    name: '',
    email: '',
}).withPrecognition('post', '/api/users')
```

Once enabled, the `validate()`, `touch()`, `touched()`, `valid()`, and `invalid()` methods become available, working identically to [form precognition](#forms-precognition-2).

<a name="http-requests-history-state"></a>

### History State

You may persist data and errors in browser history state by providing a remember key as the first argument.

```js tab=Vue
const http = useHttp('SearchData', {
    query: '',
})
```

```js tab=React
const http = useHttp('SearchData', {
    query: '',
})
```

```js tab=Svelte
const http = useHttp('SearchData', {
    query: '',
})
```

You may exclude sensitive fields from being stored in history state using the `dontRemember()` method.

```js
const http = useHttp('Login', {
    email: '',
    token: '',
}).dontRemember('token')
```

<a name="optimistic-updates"></a>

## Optimistic Updates

Inertia allows you to update the UI immediately without waiting for the server to respond, such as incrementing a like counter, toggling a bookmark, or adding an item to a list. Optimistic updates apply changes instantly while the request is in flight, automatically rolling back if the request fails.

<a name="optimistic-updates-router-visits"></a>

### Router Visits

You may chain the `optimistic()` method before any router visit. The callback receives the current page props and should return a partial update to apply immediately.

```js tab=Vue
import { router } from '@inertiajs/vue3'

router.optimistic((props) => ({
    post: {
        ...props.post,
        likes: props.post.likes + 1,
    },
})).post(`/posts/${post.id}/like`)
```

```js tab=React
import { router } from '@inertiajs/react'

router.optimistic((props) => ({
    post: {
        ...props.post,
        likes: props.post.likes + 1,
    },
})).post(`/posts/${post.id}/like`)
```

```js tab=Svelte
import { router } from '@inertiajs/svelte'

router.optimistic((props) => ({
    post: {
        ...props.post,
        likes: props.post.likes + 1,
    },
})).post(`/posts/${post.id}/like`)
```

The optimistic update is applied immediately to the current page's props, so your component re-renders with the new values before the request is sent. When the server responds, Inertia replaces the optimistic data with the server's response. If the request fails, the props are automatically reverted to their original values.

<a name="optimistic-updates-form-component"></a>

### Form Component

The `<Form>` component supports optimistic updates via the `optimistic` prop. Since the component manages input data internally, the form data is provided as a second callback argument.

```vue tab=Vue
<template>
  <Form
    action="/todos"
    method="post"
    :optimistic="(props, data) => ({
      todos: [...props.todos, { id: Date.now(), name: data.name, done: false }],
    })"
  >
    <input type="text" name="name" />
    <button type="submit">Add Todo</button>
  </Form>
</template>
```

```jsx tab=React
<Form
  action="/todos"
  method="post"
  optimistic={(props, data) => ({
    todos: [...props.todos, { id: Date.now(), name: data.name, done: false }],
  })}
>
  <input type="text" name="name" />
  <button type="submit">Add Todo</button>
</Form>
```

```svelte tab=Svelte
<Form
    action="/todos"
    method="post"
    optimistic={(props, data) => ({
        todos: [...props.todos, { id: Date.now(), name: data.name, done: false }],
    })}
>
    <input type="text" name="name" />
    <button type="submit">Add Todo</button>
</Form>
```

<a name="optimistic-updates-form-helper"></a>

### Form Helper

The `useForm` helper also supports optimistic updates via the same `optimistic()` method.

```vue tab=Vue
<script setup>
import { useForm } from '@inertiajs/vue3'

const props = defineProps({ posts: Array })

const form = useForm({
    title: '',
})

function save() {
    form.optimistic((props) => ({
        posts: [...props.posts, { title: form.title }],
    })).post('/posts')
}
</script>

<template>
    <input v-model="form.title" />
    <button @click="save" :disabled="form.processing">Save</button>
</template>
```

```jsx tab=React
import { useForm } from '@inertiajs/react'

export default function Posts({ posts }) {
    const { data, setData, optimistic, post, processing } = useForm({
        title: '',
    })

    function save(e) {
        e.preventDefault()
        optimistic((props) => ({
            posts: [...props.posts, { title: data.title }],
        }))
        post('/posts')
    }

    return (
        <form onSubmit={save}>
            <input value={data.title} onChange={e => setData('title', e.target.value)} />
            <button type="submit" disabled={processing}>Save</button>
        </form>
    )
}
```

```svelte tab=Svelte
<script>
import { useForm } from '@inertiajs/svelte'

let { posts } = $props()

const form = useForm({
    title: '',
})

function save() {
    form.optimistic((props) => ({
        posts: [...props.posts, { title: form.title }],
    })).post('/posts')
}
</script>

<input bind:value={form.title} />
<button onclick={save} disabled={form.processing}>Save</button>
```

<a name="optimistic-updates-http-requests"></a>

### HTTP Requests

The [`useHttp`](#http-requests) hook supports optimistic updates as well. Since HTTP requests don't interact with Inertia's page props, the optimistic callback receives and updates the form's own data. On failure, the form data is reverted to its pre-request state.

```js tab=Vue
import { useHttp } from '@inertiajs/vue3'

const form = useHttp({
    likes: 0,
})

form.optimistic((data) => ({
    likes: data.likes + 1,
})).post('/api/likes')
```

```js tab=React
import { useHttp } from '@inertiajs/react'

const { optimistic, post } = useHttp({
    likes: 0,
})

optimistic((data) => ({
    likes: data.likes + 1,
}))
post('/api/likes')
```

```js tab=Svelte
import { useHttp } from '@inertiajs/svelte'

const form = useHttp({
    likes: 0,
})

form.optimistic((data) => ({
    likes: data.likes + 1,
})).post('/api/likes')
```

<a name="optimistic-updates-how-it-works"></a>

### How It Works

When an optimistic update is applied:

1. The returned props are compared against the current page props, and only the keys that actually changed are snapshotted
2. The callback's return value is merged into the current data
3. The request is sent to the server
4. On success, the server's response replaces the optimistic data
5. On failure, only the snapshotted keys are reverted, rolling back the optimistic changes

The callback should return a **partial** object containing only the keys you wish to update. The returned values are shallow-merged with the current data.

<a name="optimistic-updates-automatic-rollback"></a>

#### Automatic Rollback

Optimistic state is automatically reverted in several scenarios:

- **Validation errors**: The optimistic state is reverted and the validation errors are preserved
- **Server errors**: When the request fails for any other reason, the original state is restored
- **Interrupted visits**: When a new visit interrupts an in-flight request, the previous optimistic state is restored before the new optimistic update is applied

<a name="optimistic-updates-concurrent-updates"></a>

#### Concurrent Updates

Multiple optimistic requests may be in flight at the same time. Inertia tracks which props each optimistic update touched, and server responses will not overwrite a prop until the last optimistic request that modified it has resolved.

<a name="optimistic-updates-inline-option"></a>

### Inline Option

You may also pass the optimistic callback directly in the visit options instead of chaining.

```js tab=Vue
import { router } from '@inertiajs/vue3'

router.post(`/posts/${post.id}/like`, {}, {
    optimistic: (props) => ({
        post: { ...props.post, likes: props.post.likes + 1 },
    }),
})
```

```js tab=React
import { router } from '@inertiajs/react'

router.post(`/posts/${post.id}/like`, {}, {
    optimistic: (props) => ({
        post: { ...props.post, likes: props.post.likes + 1 },
    }),
})
```

```js tab=Svelte
import { router } from '@inertiajs/svelte'

router.post(`/posts/${post.id}/like`, {}, {
    optimistic: (props) => ({
        post: { ...props.post, likes: props.post.likes + 1 },
    }),
})
```

The inline option works with `useHttp` submit methods as well.

```js
form.post('/api/likes', {
    optimistic: (data) => ({
        likes: data.likes + 1,
    }),
})
```

<a name="file-uploads"></a>

## File Uploads

<a name="file-uploads-formdata-conversion"></a>

### `FormData` Conversion

When making Inertia requests that include files (even nested files), Inertia will automatically convert the request data into a `FormData` object. This conversion is necessary in order to submit a `multipart/form-data` request via XHR.

If you would like the request to always use a `FormData` object regardless of whether a file is present in the data, you may provide the `forceFormData` option when making the request.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/users", data, {
  forceFormData: true,
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/users", data, {
  forceFormData: true,
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/users", data, {
  forceFormData: true,
});
```

You can learn more about the `FormData` interface via its [MDN documentation](https://developer.mozilla.org/en-US/docs/Web/API/FormData).

<a name="file-uploads-file-upload-example"></a>

### File Upload Example

Let's examine a complete file upload example using Inertia. This example includes both a `name` text input and an `avatar` file input.

```vue tab=Vue
<script setup>
import { useForm } from "@inertiajs/vue3";

const form = useForm({
  name: null,
  avatar: null,
});

function submit() {
  form.post("/users");
}
</script>

<template>
  <form @submit.prevent="submit">
    <input type="text" v-model="form.name" />
    <input type="file" @input="form.avatar = $event.target.files[0]" />
    <progress v-if="form.progress" :value="form.progress.percentage" max="100">
      {{ form.progress.percentage }}%
    </progress>
    <button type="submit">Submit</button>
  </form>
</template>
```

```jsx tab=React
import { useForm } from "@inertiajs/react";

const { data, setData, post, progress } = useForm({
  name: null,
  avatar: null,
});

function submit(e) {
  e.preventDefault();
  post("/users");
}

return (
  <form onSubmit={submit}>
    <input
      type="text"
      value={data.name}
      onChange={(e) => setData("name", e.target.value)}
    />
    <input type="file" onChange={(e) => setData("avatar", e.target.files[0])} />
    {progress && (
      <progress value={progress.percentage} max="100">
        {progress.percentage}%
      </progress>
    )}
    <button type="submit">Submit</button>
  </form>
);
```

```svelte tab=Svelte
<script>
    import { useForm } from '@inertiajs/svelte'

    const form = useForm({
        name: null,
        avatar: null,
    })

    function submit(e) {
        e.preventDefault()
        form.post('/users')
    }
</script>

<form onsubmit={submit}>
    <input type="text" bind:value={form.name} />
    <input type="file" oninput={e => form.avatar = e.target.files[0]} />
    {#if form.progress}
        <progress value={form.progress.percentage} max="100">
            {form.progress.percentage}%
        </progress>
    {/if}
    <button type="submit">Submit</button>
</form>
```

This example uses the [Inertia form helper](#forms-form-helper) for convenience, since the form helper provides easy access to the current upload progress. However, you are free to submit your forms using [manual Inertia visits](#manual-visits) as well.

<a name="file-uploads-multipart-limitations"></a>

### Multipart Limitations

Uploading files using a `multipart/form-data` request is not natively supported in some server-side frameworks when using the `PUT`, `PATCH`, or `DELETE` HTTP methods. The simplest workaround for this limitation is to simply upload files using a `POST` request instead.

Hypervel parses multipart `PUT`, `PATCH`, and `DELETE` requests natively. It also supports [form method spoofing](/docs/{{version}}/routing#form-method-spoofing), which allows you to upload the files using `POST`, but have the framework handle the request as a `PUT` or `PATCH` request. This is done by including a `_method` attribute in the data of your request.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post(`/users/${user.id}`, {
  _method: "put",
  avatar: form.avatar,
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post(`/users/${user.id}`, {
  _method: "put",
  avatar: form.avatar,
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post(`/users/${user.id}`, {
  _method: "put",
  avatar: form.avatar,
});
```

<a name="validation"></a>

## Validation

<a name="validation-how-it-works"></a>

### How It Works

Handling server-side validation errors in Inertia works differently than a classic XHR-driven form that requires you to catch the validation errors from `422` responses and manually update the form's error state - because Inertia never receives `422` responses. Instead, Inertia operates much more like a standard full page form submission. Here's how:

First, you [submit your form using Inertia](#forms). If there are server-side validation errors, you don't return those errors as a `422` JSON response. Instead, you redirect (server-side) the user back to the form page they were previously on, flashing the validation errors in the session. Hypervel does this automatically.

Next, any time these validation errors are present in the session, they automatically get shared with Inertia, making them available client-side as page props which you can display in your form. Since props are reactive, they are automatically shown when the form submission completes.

Finally, since Inertia apps never generate `422` responses, Inertia needs another way to determine if a response includes validation errors. To do this, Inertia checks the `page.props.errors` object for the existence of any errors. In the event that errors are present, the request's `onError()` callback will be called instead of the `onSuccess()` callback.

<a name="validation-sharing-errors"></a>

### Sharing Errors

In order for your server-side validation errors to be available client-side, your server-side framework must share them via the `errors` prop. Hypervel's Inertia middleware does this automatically. When overriding its `share` method, be sure to include `parent::share($request)`.

<a name="validation-displaying-errors"></a>

### Displaying Errors

Since validation errors are made available client-side as page component props, you can conditionally display them based on their existence. Remember, when using Hypervel's Inertia middleware, the `errors` prop will automatically be available to your page.

```vue tab=Vue
<script setup>
import { reactive } from "vue";
import { router } from "@inertiajs/vue3";

defineProps({ errors: Object });

const form = reactive({
  first_name: null,
  last_name: null,
  email: null,
});

function submit() {
  router.post("/users", form);
}
</script>

<template>
  <form @submit.prevent="submit">
    <label for="first_name">First name:</label>
    <input id="first_name" v-model="form.first_name" />
    <div v-if="errors.first_name">{{ errors.first_name }}</div>
    <label for="last_name">Last name:</label>
    <input id="last_name" v-model="form.last_name" />
    <div v-if="errors.last_name">{{ errors.last_name }}</div>
    <label for="email">Email:</label>
    <input id="email" v-model="form.email" />
    <div v-if="errors.email">{{ errors.email }}</div>
    <button type="submit">Submit</button>
  </form>
</template>
```

```jsx tab=React
import { useState } from "react";
import { router, usePage } from "@inertiajs/react";

export default function Edit() {
  const { errors } = usePage().props;

  const [values, setValues] = useState({
    first_name: null,
    last_name: null,
    email: null,
  });

  function handleChange(e) {
    setValues((values) => ({
      ...values,
      [e.target.id]: e.target.value,
    }));
  }

  function handleSubmit(e) {
    e.preventDefault();
    router.post("/users", values);
  }

  return (
    <form onSubmit={handleSubmit}>
      <label htmlFor="first_name">First name:</label>
      <input
        id="first_name"
        value={values.first_name}
        onChange={handleChange}
      />
      {errors.first_name && <div>{errors.first_name}</div>}
      <label htmlFor="last_name">Last name:</label>
      <input id="last_name" value={values.last_name} onChange={handleChange} />
      {errors.last_name && <div>{errors.last_name}</div>}
      <label htmlFor="email">Email:</label>
      <input id="email" value={values.email} onChange={handleChange} />
      {errors.email && <div>{errors.email}</div>}
      <button type="submit">Submit</button>
    </form>
  );
}
```

```svelte tab=Svelte
<script>
    import { router } from '@inertiajs/svelte'

    let { errors = {} } = $props()

    let values = {
        first_name: null,
        last_name: null,
        email: null,
    }

    function handleSubmit() {
        router.post('/users', values)
    }
</script>

<form onsubmit={(e) => { e.preventDefault(); handleSubmit() }}>
    <label for="first_name">First name:</label>
    <input id="first_name" bind:value={values.first_name}>
    {#if errors.first_name}<div>{errors.first_name}</div>{/if}

    <label for="last_name">Last name:</label>
    <input id="last_name" bind:value={values.last_name}>
    {#if errors.last_name}<div>{errors.last_name}</div>{/if}

    <label for="email">Email:</label>
    <input id="email" bind:value={values.email}>
    {#if errors.email}<div>{errors.email}</div>{/if}

    <button type="submit">Submit</button>
</form>
```

When using the Vue adapters, you may also access the errors via the `$page.props.errors` object.

<a name="validation-multiple-errors-per-field"></a>

#### Multiple Errors Per Field

By default, Inertia's Hypervel adapter returns only the first validation error for each field. You may opt-in to receiving all errors by setting the `$withAllErrors` property to `true` in your middleware.

```php
class HandleInertiaRequests extends Middleware
{
    protected bool $withAllErrors = true;

    // ...
}
```

When enabled, each field will contain an array of error strings instead of a single string.

```vue tab=Vue
<p v-for="error in errors.email">{{ error }}</p>
```

```jsx tab=React
{
  errors.email.map((error, index) => <p key={index}>{error}</p>);
}
```

```svelte tab=Svelte
{#each errors.email as error}
    <p>{error}</p>
{/each}
```

You may [configure TypeScript](#typescript-error-values) to expect arrays instead of strings.

<a name="validation-repopulating-input"></a>

### Repopulating Input

While handling errors in Inertia is similar to full page form submissions, Inertia offers even more benefits. In fact, you don't even need to manually repopulate old form input data.

When validation errors occur, the user is typically redirected back to the form page they were previously on. And, by default, Inertia automatically preserves the [component state](#manual-visits-state-preservation) for `post`, `put`, `patch`, and `delete` requests. Therefore, all the old form input data remains exactly as it was when the user submitted the form.

So, the only work remaining is to display any validation errors using the `errors` prop.

<a name="validation-error-bags"></a>

### Error Bags

If you're using the [form helper](#forms-form-helper), it's not necessary to use error bags since validation errors are automatically scoped to the form object making the request.

For pages that have more than one form, it's possible to encounter conflicts when displaying validation errors if two forms share the same field names. For example, imagine a "create company" form and a "create user" form that both have a `name` field. Since both forms will be displaying the `page.props.errors.name` validation error, generating a validation error for the `name` field in either form will cause the error to appear in both forms.

To solve this issue, you can use "error bags". Error bags scope the validation errors returned from the server within a unique key specific to that form. Continuing with our example above, you might have a `createCompany` error bag for the first form and a `createUser` error bag for the second form.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/companies", data, {
  errorBag: "createCompany",
});

router.post("/users", data, {
  errorBag: "createUser",
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/companies", data, {
  errorBag: "createCompany",
});

router.post("/users", data, {
  errorBag: "createUser",
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/companies", data, {
  errorBag: "createCompany",
});

router.post("/users", data, {
  errorBag: "createUser",
});
```

Specifying an error bag will cause the validation errors to come back from the server within `page.props.errors.createCompany` and `page.props.errors.createUser`.

<a name="layouts"></a>

## Layouts

Most applications share common UI elements across pages, such as a primary navigation bar, sidebar, or footer. Layout components let you define this shared UI once and wrap your pages with it automatically.

<a name="layouts-creating-layouts"></a>

### Creating Layouts

A layout is a standard component that accepts child content. There is nothing Inertia-specific about it.

```vue tab=Vue
<script setup>
import { Link } from "@inertiajs/vue3"
</script>

<template>
    <main>
        <header>
            <Link href="/">Home</Link>
            <Link href="/about">About</Link>
            <Link href="/contact">Contact</Link>
        </header>
        <article>
            <slot />
        </article>
    </main>
</template>
```

```jsx tab=React
import { Link } from "@inertiajs/react"

export default function Layout({ children }) {
    return (
        <main>
            <header>
                <Link href="/">Home</Link>
                <Link href="/about">About</Link>
                <Link href="/contact">Contact</Link>
            </header>
            <article>{children}</article>
        </main>
    )
}
```

```svelte tab=Svelte
<script>
    import { inertia } from '@inertiajs/svelte'

    let { children } = $props()
</script>

<main>
    <header>
        <a use:inertia href="/">Home</a>
        <a use:inertia href="/about">About</a>
        <a use:inertia href="/contact">Contact</a>
    </header>
    <article>
        {@render children()}
    </article>
</main>
```

You may use a layout by wrapping your page content with it directly. However, this approach forces the layout instance to be destroyed and recreated between visits.

```vue tab=Vue
<script setup>
import Layout from './Layout'

defineProps({ user: Object })
</script>

<template>
    <Layout>
        <h1>Welcome</h1>
        <p>Hello {{ user.name }}, welcome to your first Inertia app!</p>
    </Layout>
</template>
```

```jsx tab=React
import Layout from './Layout'

export default function Welcome({ user }) {
    return (
        <Layout>
            <h1>Welcome</h1>
            <p>Hello {user.name}, welcome to your first Inertia app!</p>
        </Layout>
    )
}
```

```svelte tab=Svelte
<script>
    import Layout from './Layout.svelte'

    let { user } = $props()
</script>

<Layout>
    <h1>Welcome</h1>
    <p>Hello {user.name}, welcome to your first Inertia app!</p>
</Layout>
```

<a name="layouts-persistent-layouts"></a>

### Persistent Layouts

Wrapping a page with a layout as a child component works, but it means the layout is destroyed and recreated on every visit. This prevents maintaining layout state across navigations, such as an audio player that should keep playing or a sidebar that should retain its scroll position.

Persistent layouts solve this by telling Inertia which layout to use for a page. Inertia then manages the layout instance separately, keeping it alive between visits.

```vue tab=Vue
<script>
import Layout from './Layout'

export default {
    layout: Layout,
}
</script>

<script setup>
defineProps({ user: Object })
</script>

<template>
    <h1>Welcome</h1>
    <p>Hello {{ user.name }}, welcome to your first Inertia app!</p>
</template>
```

```jsx tab=React
import Layout from './Layout'

const Welcome = ({ user }) => {
    return (
        <>
            <h1>Welcome</h1>
            <p>Hello {user.name}, welcome to your first Inertia app!</p>
        </>
    )
}

Welcome.layout = (page) => <Layout>{page}</Layout>

export default Welcome
```

```svelte tab=Svelte
<script module>
    export { default as layout } from './Layout.svelte'
</script>

<script>
    let { user } = $props()
</script>

<h1>Welcome</h1>
<p>Hello {user.name}, welcome to your first Inertia app!</p>
```

**Vue:**

Vue 3.3+ users may alternatively use [defineOptions](https://vuejs.org/api/sfc-script-setup.html#defineoptions) to define a layout within `<script setup>`:

```vue
<script setup>
import Layout from './Layout'
defineOptions({ layout: Layout })
</script>
```

**React:**

Arrow function components should be wrapped in an array. Without the array, Inertia cannot distinguish them from render functions at runtime:

```jsx
const ArrowLayout = ({ children }) => <main>{children}</main>

Welcome.layout = [ArrowLayout]
```

<a name="layouts-nested-layouts"></a>

#### Nested Layouts

You may create more complex layout arrangements using nested layouts. Pass an array of layout components to wrap the page in multiple layers.

```vue tab=Vue
<script>
import SiteLayout from './SiteLayout'
import NestedLayout from './NestedLayout'

export default {
    layout: [SiteLayout, NestedLayout],
}
</script>

<script setup>
defineProps({ user: Object })
</script>

<template>
    <h1>Welcome</h1>
    <p>Hello {{ user.name }}, welcome to your first Inertia app!</p>
</template>
```

```jsx tab=React
import SiteLayout from './SiteLayout'
import NestedLayout from './NestedLayout'

const Welcome = ({ user }) => {
    return (
        <>
            <h1>Welcome</h1>
            <p>Hello {user.name}, welcome to your first Inertia app!</p>
        </>
    )
}

Welcome.layout = [SiteLayout, NestedLayout]

export default Welcome
```

```svelte tab=Svelte
<script module>
    import SiteLayout from './SiteLayout.svelte'
    import NestedLayout from './NestedLayout.svelte'

    export const layout = [SiteLayout, NestedLayout]
</script>

<script>
    let { user } = $props()
</script>

<h1>Welcome</h1>
<p>Hello {user.name}, welcome to your first Inertia app!</p>
```

<a name="layouts-default-layouts"></a>

### Default Layouts

The `layout` option in `createInertiaApp` lets you define a default layout for all pages, saving you from defining it on every page individually. Per-page layouts always take precedence over the default.

```js
import Layout from './Layout'

createInertiaApp({
    layout: () => Layout,
    // ...
})
```

You may also conditionally return a layout based on the page name. For example, you may wish to exclude public pages from the default layout.

```js
import Layout from './Layout'

createInertiaApp({
    layout: (name) => {
        if (name.startsWith('Public/')) {
            return null
        }

        return Layout
    },
    // ...
})
```

The full page object is also available as the second argument, giving you access to the page's URL, props, and other metadata.

The `layout` callback supports all layout formats, including arrays for [nested layouts](#layouts-nested-layouts), named objects for [named layouts](#layouts-targeting-named-layouts), and tuples for [static props](#layouts-static-props).

<a name="layouts-using-the-resolve-callback"></a>

#### Using the Resolve Callback

You may also set a default layout inside the `resolve` callback by mutating the resolved page component. The callback receives the component name and the full page object, which is useful when you need to conditionally apply layouts based on page data.

```js tab=Vue
import Layout from './Layout'

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        let page = pages[`./Pages/${name}.vue`]
        page.default.layout = page.default.layout || Layout
        return page
    },
    // ...
})
```

```jsx tab=React
import Layout from './Layout'

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true })
        let page = pages[`./Pages/${name}.jsx`]
        page.default.layout = page.default.layout || ((page) => <Layout>{page}</Layout>)
        return page
    },
    // ...
})
```

```js tab=Svelte
import Layout from './Layout'

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.svelte', { eager: true })
        let page = pages[`./Pages/${name}.svelte`]
        return { default: page.default, layout: page.layout || Layout }
    },
    // ...
})
```

<a name="layouts-layout-props"></a>

### Layout Props

Persistent layouts often need dynamic data from the current page, such as a page title, the active navigation item, or a sidebar toggle. Layout props provide a way to define defaults in your layout and override them from any page.

<a name="layouts-defining-defaults"></a>

#### Defining Defaults

Layout props are defined as regular component props with default values.

```vue tab=Vue
<script setup>
const props = withDefaults(defineProps<{
    title?: string
    showSidebar?: boolean
}>(), {
    title: 'My App',
    showSidebar: true,
})
</script>

<template>
    <header>{{ title }}</header>
    <aside v-if="showSidebar">Sidebar</aside>
    <main>
        <slot />
    </main>
</template>
```

```jsx tab=React
export default function Layout({ title = 'My App', showSidebar = true, children }) {
    return (
        <>
            <header>{title}</header>
            {showSidebar && <aside>Sidebar</aside>}
            <main>{children}</main>
        </>
    )
}
```

```svelte tab=Svelte
<script>
let { title = 'My App', showSidebar = true, children } = $props()
</script>

<header>{title}</header>
{#if showSidebar}
    <aside>Sidebar</aside>
{/if}
<main>
    {@render children()}
</main>
```

<a name="layouts-static-props"></a>

#### Static Props

You may pass static props directly in your persistent layout definition using a tuple. These props are set once when the layout is defined and don't change between page navigations.

```vue tab=Vue
<script setup>
import Layout from './Layout'

defineProps({ user: Object })
defineOptions({
    layout: [Layout, { title: 'Dashboard' }],
})
</script>

<template>
    <h1>Dashboard</h1>
</template>
```

```jsx tab=React
import Layout from './Layout'

const Dashboard = ({ user }) => {
    return <h1>Dashboard</h1>
}

Dashboard.layout = [Layout, { title: 'Dashboard' }]

export default Dashboard
```

```svelte tab=Svelte
<script module>
    import Layout from './Layout.svelte'

    export const layout = [Layout, { title: 'Dashboard' }]
</script>

<script>
    let { user } = $props()
</script>

<h1>Dashboard</h1>
```

<a name="layouts-callback-props"></a>

#### Callback Props

Sometimes layout props need to be derived from the current page's props. A callback function receives the page props and returns a layout definition with computed static props.

```vue tab=Vue
<script setup>
import Layout from './Layout'

defineOptions({
    layout: (props) => [Layout, { title: 'Profile: ' + props.auth.user.name }],
})
</script>

<template>
    <h1>Profile</h1>
</template>
```

```jsx tab=React
import Layout from './Layout'

const Profile = () => {
    return <h1>Profile</h1>
}

Profile.layout = (props) => [Layout, { title: 'Profile: ' + props.auth.user.name }]

export default Profile
```

```svelte tab=Svelte
<script module>
    import Layout from './Layout.svelte'

    export const layout = (props) => [Layout, { title: 'Profile: ' + props.auth.user.name }]
</script>

<h1>Profile</h1>
```

The callback receives the page's props and may return any valid layout format: a single component, a tuple with static props, an array for nested layouts, or a named layout object. TypeScript users may use the [`LayoutCallback`](#typescript-layout-callbacks) type for type safety.

<a name="layouts-returning-props-only"></a>

##### Returning Props Only

When a [default layout](#layouts-default-layouts) is configured in `createInertiaApp`, callbacks may return a plain props object instead of a full layout definition. Inertia will automatically use the default layout and merge the returned props onto it.

```vue tab=Vue
<script setup>
defineOptions({
    layout: (props) => ({ title: 'Profile: ' + props.auth.user.name, showSidebar: false }),
})
</script>

<template>
    <h1>Profile</h1>
</template>
```

```jsx tab=React
const Profile = () => {
    return <h1>Profile</h1>
}

Profile.layout = (props) => ({ title: 'Profile: ' + props.auth.user.name, showSidebar: false })

export default Profile
```

```svelte tab=Svelte
<script module>
    export const layout = (props) => ({
        title: 'Profile: ' + props.auth.user.name,
        showSidebar: false,
    })
</script>

<h1>Profile</h1>
```

A static object may also be used when the props don't depend on page data.

```js
Dashboard.layout = { title: 'Dashboard', showSidebar: true }
```

<a name="layouts-dynamic-props"></a>

#### Dynamic Props

You may also update layout props dynamically from any page component using the `setLayoutProps` function. TypeScript users may [type these props](#typescript-layout-props) globally.

```vue tab=Vue
<script setup>
import { setLayoutProps } from '@inertiajs/vue3'

setLayoutProps({
    title: 'Dashboard',
    showSidebar: false,
})
</script>

<template>
    <h1>Dashboard</h1>
</template>
```

```jsx tab=React
import { setLayoutProps } from '@inertiajs/react'

export default function Dashboard() {
    setLayoutProps({
        title: 'Dashboard',
        showSidebar: false,
    })

    return <h1>Dashboard</h1>
}
```

```svelte tab=Svelte
<script>
import { setLayoutProps } from '@inertiajs/svelte'

setLayoutProps({
    title: 'Dashboard',
    showSidebar: false,
})
</script>

<h1>Dashboard</h1>
```

<a name="layouts-targeting-named-layouts"></a>

#### Targeting Named Layouts

[Nested layouts](#layouts-nested-layouts) may also be defined as a named object instead of an array, allowing you to target specific layouts with props.

```vue tab=Vue
<script>
import AppLayout from './AppLayout'
import ContentLayout from './ContentLayout'

export default {
    layout: {
        app: AppLayout,
        content: ContentLayout,
    },
}
</script>
```

```jsx tab=React
import AppLayout from './AppLayout'
import ContentLayout from './ContentLayout'

Dashboard.layout = {
    app: AppLayout,
    content: ContentLayout,
}
```

```svelte tab=Svelte
<script module>
    import AppLayout from './AppLayout.svelte'
    import ContentLayout from './ContentLayout.svelte'

    export const layout = {
        app: AppLayout,
        content: ContentLayout,
    }
</script>
```

You may target a specific named layout by passing the layout name as the first argument to `setLayoutProps`.

```js tab=Vue
import { setLayoutProps } from '@inertiajs/vue3'

setLayoutProps('sidebar', {
    collapsed: true,
})
```

```js tab=React
import { setLayoutProps } from '@inertiajs/react'

setLayoutProps('sidebar', {
    collapsed: true,
})
```

```js tab=Svelte
import { setLayoutProps } from '@inertiajs/svelte'

setLayoutProps('sidebar', {
    collapsed: true,
})
```

[Nested layouts](#layouts-nested-layouts) and named layouts may also include static props using the tuple syntax.

```js
// Nested layouts with static props
Dashboard.layout = [
    [AppLayout, { title: 'Dashboard' }],
    [ContentLayout, { padding: 'sm' }],
]

// Named layouts with static props
Dashboard.layout = {
    app: [AppLayout, { theme: 'dark' }],
    content: [ContentLayout, { padding: 'sm' }],
}
```

<a name="layouts-merge-priority"></a>

#### Merge Priority

Layout props are resolved from multiple sources with the following priority (highest to lowest):

1. **Dynamic props** - set via `setLayoutProps()`
2. **Static props** - defined in the persistent layout definition (including [callback props](#layouts-callback-props))
3. **Defaults** - declared as default values on the layout component's props

<a name="layouts-auto-reset-on-navigation"></a>

#### Auto-Reset on Navigation

Dynamic layout props are automatically reset when navigating to a new page (unless `preserveState` is enabled). This ensures each page starts with a clean slate and only the layout props explicitly set by that page are applied.

<a name="layouts-resetting-props"></a>

#### Resetting Props

You may also manually reset all dynamic layout props using `resetLayoutProps`.

```js tab=Vue
import { resetLayoutProps } from '@inertiajs/vue3'

resetLayoutProps()
```

```js tab=React
import { resetLayoutProps } from '@inertiajs/react'

resetLayoutProps()
```

```js tab=Svelte
import { resetLayoutProps } from '@inertiajs/svelte'

resetLayoutProps()
```

<a name="view-transitions"></a>

## View Transitions

Inertia supports the [View Transitions API](https://developer.chrome.com/docs/web-platform/view-transitions), allowing you to animate page transitions.

The View Transitions API is a [relatively new browser feature](https://caniuse.com/view-transitions). Inertia gracefully falls back to standard page transitions in browsers that don't support the API.

<a name="view-transitions-enabling-transitions"></a>

### Enabling Transitions

You may enable view transitions for a visit by setting the `viewTransition` option to `true`. By default, this will apply a cross-fade transition between pages.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit("/another-page", { viewTransition: true });
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit("/another-page", { viewTransition: true });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit("/another-page", { viewTransition: true });
```

<a name="view-transitions-transition-callbacks"></a>

### Transition Callbacks

You may also pass a callback to the `viewTransition` option, which will receive the standard [`ViewTransition`](https://developer.mozilla.org/en-US/docs/Web/API/ViewTransition) instance provided by the browser. This allows you to hook into the various promises provided by the API.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit("/another-page", {
  viewTransition: (transition) => {
    transition.ready.then(() => console.log("Transition ready"));
    transition.updateCallbackDone.then(() => console.log("DOM updated"));
    transition.finished.then(() => console.log("Transition finished"));
  },
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit("/another-page", {
  viewTransition: (transition) => {
    transition.ready.then(() => console.log("Transition ready"));
    transition.updateCallbackDone.then(() => console.log("DOM updated"));
    transition.finished.then(() => console.log("Transition finished"));
  },
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit("/another-page", {
  viewTransition: (transition) => {
    transition.ready.then(() => console.log("Transition ready"));
    transition.updateCallbackDone.then(() => console.log("DOM updated"));
    transition.finished.then(() => console.log("Transition finished"));
  },
});
```

<a name="view-transitions-links"></a>

### Links

The `viewTransition` option is also available on the `Link` component.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/another-page" view-transition>Navigate</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/another-page" viewTransition>
  Navigate
</Link>;
```

```svelte tab=Svelte
import { Link } from '@inertiajs/svelte'

<Link href="/another-page" viewTransition>Navigate</Link>
```

You may also pass a callback to access the `ViewTransition` instance.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link
  href="/another-page"
  :view-transition="
    (transition) => transition.finished.then(() => console.log('Done'))
  "
>
    Navigate
</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link
  href="/another-page"
  viewTransition={(transition) =>
    transition.finished.then(() => console.log("Done"))
  }
>
  Navigate
</Link>;
```

```svelte tab=Svelte
import { Link } from '@inertiajs/svelte'

<Link
    href="/another-page"
    viewTransition={(transition) => transition.finished.then(() => console.log('Done'))}
>
    Navigate
</Link>
```

<a name="view-transitions-global-configuration"></a>

### Global Configuration

You may enable view transitions globally for all visits by configuring the `visitOptions` callback when [initializing your Inertia app](#client-side-setup-configuring-defaults).

```js tab=Vue
import { createInertiaApp } from "@inertiajs/vue3";

createInertiaApp({
  // ...
  defaults: {
    visitOptions: (href, options) => {
      return { viewTransition: true };
    },
  },
});
```

```jsx tab=React
import { createInertiaApp } from "@inertiajs/react";

createInertiaApp({
  // ...
  defaults: {
    visitOptions: (href, options) => {
      return { viewTransition: true };
    },
  },
});
```

```js tab=Svelte
import { createInertiaApp } from "@inertiajs/svelte";

createInertiaApp({
  // ...
  defaults: {
    visitOptions: (href, options) => {
      return { viewTransition: true };
    },
  },
});
```

<a name="view-transitions-customizing-transitions"></a>

### Customizing Transitions

You may customize the transition animations using CSS. The View Transitions API uses several pseudo-elements that you can target with CSS to create custom animations. The following examples are taken from the [Chrome documentation](https://developer.chrome.com/docs/web-platform/view-transitions/same-document#customize_the_transition).

```css
@keyframes fade-in {
  from {
    opacity: 0;
  }
}
@keyframes fade-out {
  to {
    opacity: 0;
  }
}
@keyframes slide-from-right {
  from {
    transform: translateX(30px);
  }
}
@keyframes slide-to-left {
  to {
    transform: translateX(-30px);
  }
}
::view-transition-old(root) {
  animation:
    90ms cubic-bezier(0.4, 0, 1, 1) both fade-out,
    300ms cubic-bezier(0.4, 0, 0.2, 1) both slide-to-left;
}
::view-transition-new(root) {
  animation:
    210ms cubic-bezier(0, 0, 0.2, 1) 90ms both fade-in,
    300ms cubic-bezier(0.4, 0, 0.2, 1) both slide-from-right;
}
```

You may also animate individual elements between pages by assigning them a unique `view-transition-name`. For example, you may animate an avatar from a large size on a profile page to a small size on a dashboard.

```vue tab=Vue
<!-- Profile.vue -->
<template>
  <img src="/avatar.jpg" alt="User" class="avatar-large" />
</template>

<style>
.avatar-large {
  view-transition-name: user-avatar;
  width: auto;
  height: 200px;
}
</style>

<!-- Dashboard.vue -->
<template>
  <img src="/avatar.jpg" alt="User" class="avatar-small" />
</template>

<style>
.avatar-small {
  view-transition-name: user-avatar;
  width: auto;
  height: 40px;
}
</style>
```

```jsx tab=React
// Profile.jsx
export default function Profile() {
    return <img src="/avatar.jpg" alt="User" className="avatar-large" />
}

// CSS
.avatar-large {
    view-transition-name: user-avatar;
    width: auto;
    height: 200px;
}

// Dashboard.jsx
export default function Dashboard() {
    return <img src="/avatar.jpg" alt="User" className="avatar-small" />
}

// CSS
.avatar-small {
    view-transition-name: user-avatar;
    width: auto;
    height: 40px;
}
```

```svelte tab=Svelte
<!-- Profile.svelte -->
<img src="/avatar.jpg" alt="User" class="avatar-large" />

<style>
.avatar-large {
    view-transition-name: user-avatar;
    width: auto;
    height: 200px;
}
</style>

<!-- Dashboard.svelte -->
<img src="/avatar.jpg" alt="User" class="avatar-small" />

<style>
.avatar-small {
    view-transition-name: user-avatar;
    width: auto;
    height: 40px;
}
</style>
```

You may customize view transitions to your liking using any CSS animations you wish. For more information, please consult the [View Transitions API documentation](https://developer.chrome.com/docs/web-platform/view-transitions/same-document#customize_the_transition).

<a name="shared-data"></a>

## Shared Data

Sometimes you need to access specific pieces of data on numerous pages within your application. For example, you may need to display the current user in the site header. Passing this data manually in each response across your entire application is cumbersome. Thankfully, there is a better option: shared data.

<a name="shared-data-sharing-data"></a>

### Sharing Data

All of Inertia's server-side adapters provide a method to make shared data available for every request. This is typically done outside of your controllers. Shared data is automatically merged with the page props provided in your controller.

In Hypervel applications, this is typically handled by the `HandleInertiaRequests` middleware that you register when installing the [server-side adapter](#server-side-setup-register-middleware).

```php
class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            // Synchronously...
            'appName' => config('app.name'),

            // Lazily...
            'auth.user' => fn () => $request->user()
                ? $request->user()->only('id', 'name', 'email')
                : null,
        ]);
    }
}
```

Alternatively, you can manually share data using the `Inertia::share` method.

```php
use Hypervel\Http\Request;
use Hypervel\Inertia\Inertia;

// Synchronously...
Inertia::share('appName', config('app.name'));

// Lazily...
Inertia::share('user', fn (Request $request) => $request->user()
    ? $request->user()->only('id', 'name', 'email')
    : null
);
```

Shared data should be used sparingly as all shared data is included with every response.

Page props and shared data are merged together, so be sure to namespace your shared data appropriately to avoid collisions.

<a name="shared-data-sharing-once-props"></a>

### Sharing Once Props

You may share data that is resolved only once and remembered by the client across subsequent navigations using [once props](#once-props).

```php
class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'countries' => Inertia::once(fn () => Country::all()),
        ]);
    }
}
```

Alternatively, you may define a dedicated `shareOnce()` method in the middleware. The middleware will evaluate both `share()` and `shareOnce()`, merging the results.

```php
class HandleInertiaRequests extends Middleware
{
    public function shareOnce(Request $request): array
    {
        return array_merge(parent::shareOnce($request), [
            'countries' => fn () => Country::all(),
        ]);
    }
}
```

You may also share once props manually using the `Inertia::shareOnce()` method.

```php
Inertia::shareOnce('countries', fn () => Country::all());
```

<a name="shared-data-accessing-shared-data"></a>

### Accessing Shared Data

Once you have shared the data server-side, you will be able to access it within any of your pages or components. Here's an example of how to access shared data in a layout component.

```vue tab=Vue
<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

const page = usePage();

const user = computed(() => page.props.auth.user);
</script>

<template>
  <main>
    <header>You are logged in as: {{ user.name }}</header>
    <article>
      <slot />
    </article>
  </main>
</template>
```

```jsx tab=React
import { usePage } from "@inertiajs/react";

export default function Layout({ children }) {
  const { auth } = usePage().props;

  return (
    <main>
      <header>You are logged in as: {auth.user.name}</header>
      <article>{children}</article>
    </main>
  );
}
```

```svelte tab=Svelte
<script>
    import { page } from '@inertiajs/svelte'
</script>

<main>
    <header>
        You are logged in as: {page.props.auth.user.name}
    </header>
    <article>
        <slot />
    </article>
</main>
```

<a name="shared-data-typescript"></a>

### TypeScript

You may configure the shared props type globally using [TypeScript's declaration merging](#typescript-shared-page-props).

<a name="shared-data-flash-data"></a>

### Flash Data

For one-time notifications like toast messages or success alerts, you may use [flash data](#flash-data). Unlike shared data, flash data is not persisted in the browser's history state, so it won't reappear when navigating through history.

<a name="flash-data"></a>

## Flash Data

Flash data lets you send one-time data to your front end that won't reappear when users navigate through browser history. Unlike regular props, flash data isn't persisted in history state, making it ideal for success messages, newly created IDs, or other temporary values.

<a name="flash-data-flashing-data"></a>

### Flashing Data

You may flash data using the `Inertia::flash()` method, passing a key and value or an array of key-value pairs.

```php
use App\Http\Requests\StoreUserRequest;
use Hypervel\Inertia\Inertia;

public function store(StoreUserRequest $request)
{
    $user = User::create($request->validated());

    Inertia::flash('message', 'User created successfully!');

    // Or flash multiple values at once...
    Inertia::flash([
        'message' => 'User created!',
        'newUserId' => $user->id,
    ]);

    return back();
}
```

Chaining with `back()` is also supported.

```php
return Inertia::flash('newUserId', $user->id)->back();
```

You may also chain `flash()` onto `render()`, or vice versa.

```php
return Inertia::render('Projects/Index', [
    'projects' => $projects,
])->flash('highlight', $project->id);

// Or...

return Inertia::flash('highlight', $project->id)
    ->render('Projects/Index', ['projects' => $projects]);
```

Flash data is scoped to the current request. The middleware automatically persists it to the session when redirecting. After the flash data is sent to the client, it is cleared and will not appear in subsequent requests.

<a name="flash-data-accessing-flash-data"></a>

### Accessing Flash Data

Flash data is available on `page.flash`. You may also listen for the global `flash` event or use the `onFlash` callback.

```vue tab=Vue
<script setup>
import { usePage } from "@inertiajs/vue3";

const page = usePage();
</script>

<template>
  <div v-if="page.flash.toast" class="toast">
    {{ page.flash.toast.message }}
  </div>
</template>
```

```jsx tab=React
import { usePage } from "@inertiajs/react";

export default function Layout({ children }) {
  const { flash } = usePage();

  return (
    <>
      {flash.toast && <div className="toast">{flash.toast.message}</div>}
      {children}
    </>
  );
}
```

```svelte tab=Svelte
<script>
    import { page } from '@inertiajs/svelte'
</script>

{#if page.flash.toast}
    <div class="toast">{page.flash.toast.message}</div>
{/if}
```

<a name="flash-data-the-onflash-callback"></a>

### The onFlash Callback

You may use the `onFlash` callback to handle flash data when making requests.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/users", data, {
  onFlash: ({ newUserId }) => {
    form.userId = newUserId;
  },
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/users", data, {
  onFlash: ({ newUserId }) => {
    form.userId = newUserId;
  },
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/users", data, {
  onFlash: ({ newUserId }) => {
    form.userId = newUserId;
  },
});
```

<a name="flash-data-global-flash-event"></a>

### Global Flash Event

You may use the global `flash` event to handle flash data in a central location, such as a layout component. For more information on events, see the [events documentation](#events).

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("flash", (event) => {
  if (event.detail.flash.toast) {
    showToast(event.detail.flash.toast);
  }
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.on("flash", (event) => {
  if (event.detail.flash.toast) {
    showToast(event.detail.flash.toast);
  }
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("flash", (event) => {
  if (event.detail.flash.toast) {
    showToast(event.detail.flash.toast);
  }
});
```

> [!WARNING]
> Event listeners registered inside components should be cleaned up when the
> component unmounts to prevent them from accumulating and firing multiple
> times. This is especially important in non-persistent layouts. See [removing
> event listeners](#events-removing-listeners) for more information.

Native browser events are also supported.

```js tab=Vue
document.addEventListener("inertia:flash", (event) => {
  console.log(event.detail.flash);
});
```

```js tab=React
document.addEventListener("inertia:flash", (event) => {
  console.log(event.detail.flash);
});
```

```js tab=Svelte
document.addEventListener("inertia:flash", (event) => {
  console.log(event.detail.flash);
});
```

The `flash` event is not cancelable and fires on every response that carries flash data.

<a name="flash-data-client-side-flash"></a>

### Client-Side Flash

You may set flash data on the client without a server request using the `router.flash()` method. Values are merged with existing flash data.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.flash("foo", "bar");
router.flash({ foo: "bar" });
```

```js tab=React
import { router } from "@inertiajs/react";

router.flash("foo", "bar");
router.flash({ foo: "bar" });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.flash("foo", "bar");
router.flash({ foo: "bar" });
```

A callback may also be passed to access the current flash data or replace it entirely.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.flash((current) => ({ ...current, bar: "baz" }));
router.flash(() => ({}));
```

```js tab=React
import { router } from "@inertiajs/react";

router.flash((current) => ({ ...current, bar: "baz" }));
router.flash(() => ({}));
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.flash((current) => ({ ...current, bar: "baz" }));
router.flash(() => ({}));
```

<a name="flash-data-typescript"></a>

### TypeScript

You may configure the flash data type globally using [TypeScript's declaration merging](#typescript-flash-data).

<a name="flash-data-testing"></a>

### Testing

For information on testing flash data, see the [testing documentation](#testing-testing-flash-data).

<a name="partial-reloads"></a>

## Partial Reloads

When making visits to the same page you are already on, it's not always necessary to re-fetch all of the page's data from the server. In fact, selecting only a subset of the data can be a helpful performance optimization if it's acceptable that some page data becomes stale. Inertia makes this possible via its "partial reload" feature.

As an example, consider a "user index" page that includes a list of users, as well as an option to filter the users by their company. On the first request to the page, both the `users` and `companies` props are passed to the page component. However, on subsequent visits to the same page (maybe to filter the users), you can request only the `users` data from the server without requesting the `companies` data. Inertia will then automatically merge the partial data returned from the server with the data it already has in memory client-side.

Partial reloads only work for visits made to the same page component.

<a name="partial-reloads-only-certain-props"></a>

### Only Certain Props

To perform a partial reload, use the `only` visit option to specify which data the server should return. This option should be an array of keys which correspond to the keys of the props.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit(url, {
  only: ["users"],
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit(url, {
  only: ["users"],
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit(url, {
  only: ["users"],
});
```

<a name="partial-reloads-except-certain-props"></a>

### Except Certain Props

In addition to the `only` visit option you can also use the `except` option to specify which data the server should exclude. This option should also be an array of keys which correspond to the keys of the props.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit(url, {
  except: ["users"],
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit(url, {
  except: ["users"],
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit(url, {
  except: ["users"],
});
```

<a name="partial-reloads-router-shorthand"></a>

### Router Shorthand

Since partial reloads can only be made to the same page component the user is already on, it almost always makes sense to just use the `router.reload()` method, which automatically uses the current URL.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.reload({ only: ["users"] });
```

```js tab=React
import { router } from "@inertiajs/react";

router.reload({ only: ["users"] });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.reload({ only: ["users"] });
```

<a name="partial-reloads-using-links"></a>

### Using Links

It's also possible to perform partial reloads with Inertia links using the `only` property.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/users?active=true" :only="['users']">Show active</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/users?active=true" only={["users"]}>
  Show active
</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<a href="/users?active=true" use:inertia={{ only: ['users'] }}>Show active</a>

<Link href="/users?active=true" only={['users']}>Show active</Link>
```

<a name="partial-reloads-lazy-data-evaluation"></a>

### Lazy Data Evaluation

For partial reloads to be most effective, be sure to also use lazy data evaluation when returning props from your server-side routes or controllers. This can be accomplished by wrapping all optional page data in a closure.

```php
return Inertia::render('Users/Index', [
    'users' => fn () => User::all(),
    'companies' => fn () => Company::all(),
]);
```

When Inertia performs a request, it will determine which data is required and only then will it evaluate the closure. This can significantly increase the performance of pages that contain a lot of optional data.

Additionally, Inertia provides an `Inertia::optional()` method to specify that a prop should never be included unless explicitly requested using the `only` option:

```php
return Inertia::render('Users/Index', [
    'users' => Inertia::optional(fn () => User::all()),
]);
```

On the inverse, you can use the `Inertia::always()` method to specify that a prop should always be included, even if it has not been explicitly required in a partial reload.

```php
return Inertia::render('Users/Index', [
    'users' => Inertia::always(User::all()),
]);
```

Here's a summary of each approach:

| Approach                                  | Standard Visits | Partial Reloads | Evaluated        |
| :---------------------------------------- | :-------------- | :-------------- | :--------------- |
| `User::all()`                             | Always          | Optionally      | Always           |
| `fn () => User::all()`                    | Always          | Optionally      | Only when needed |
| `Inertia::optional(fn () => User::all())` | Never           | Optionally      | Only when needed |
| `Inertia::always(fn () => User::all())`   | Always          | Always          | Always           |

<a name="partial-reloads-preserving-errors"></a>

### Preserving Errors

Since the Hypervel adapter shares errors using `Inertia::always()`, they are included in every response, even partial reloads where no validation runs. This means an empty error bag from the server will overwrite any existing client-side validation errors. You may use the `preserveErrors` option to keep existing errors when the server doesn't return new ones.

```js tab=Vue
import { router } from '@inertiajs/vue3'

router.reload({
    only: ['posts'],
    preserveErrors: true,
})
```

```js tab=React
import { router } from '@inertiajs/react'

router.reload({
    only: ['posts'],
    preserveErrors: true,
})
```

```js tab=Svelte
import { router } from '@inertiajs/svelte'

router.reload({
    only: ['posts'],
    preserveErrors: true,
})
```

<a name="partial-reloads-combining-with-once-props"></a>

### Combining with Once Props

You may chain the `once()` modifier onto an optional prop to ensure the data is resolved only once and remembered by the client across subsequent navigations.

```php
return Inertia::render('Users/Index', [
    'users' => Inertia::optional(fn () => User::all())->once(),
]);
```

For more information on once props, see the [once props](#once-props) documentation.

<a name="deferred-props"></a>

## Deferred Props

Inertia's deferred props feature allows you to defer the loading of certain page data until after the initial page render. This can be useful for improving the perceived performance of your app by allowing the initial page render to happen as quickly as possible.

<a name="deferred-props-server-side"></a>

### Server-Side

To defer a prop, you can use the `Inertia::defer()` method when returning your response. This method receives a callback that returns the prop data. The callback will be executed in a separate request after the initial page render.

```php
Route::get('/users', function () {
    return Inertia::render('Users/Index', [
        'users' => User::all(),
        'roles' => Role::all(),
        'permissions' => Inertia::defer(fn () => Permission::all()),
    ]);
});
```

<a name="deferred-props-grouping-requests"></a>

#### Grouping Requests

By default, all deferred props are fetched in a single request after the initial page is rendered. However, you may fetch props in parallel by grouping them.

```php
Route::get('/users', function () {
    return Inertia::render('Users/Index', [
        'users' => User::all(),
        'roles' => Role::all(),
        'permissions' => Inertia::defer(fn () => Permission::all()),
        'teams' => Inertia::defer(fn () => Team::all(), 'attributes'),
        'projects' => Inertia::defer(fn () => Project::all(), 'attributes'),
        'tasks' => Inertia::defer(fn () => Task::all(), 'attributes'),
    ]);
});
```

In the example above, the `teams`, `projects`, and `tasks` props will be fetched in one request, while the `permissions` prop will be fetched in a separate request in parallel. Group names are arbitrary strings and can be anything you choose.

<a name="deferred-props-client-side"></a>

### Client-Side

On the client side, Inertia provides the `Deferred` component to help you manage deferred props. This component will automatically wait for the specified deferred props to be available before rendering its children.

```vue tab=Vue
<script setup>
import { Deferred } from "@inertiajs/vue3";
</script>

<template>
  <Deferred data="permissions">
    <template #fallback>
      <div>Loading...</div>
    </template>

    <div v-for="permission in permissions">
      <!-- ... -->
    </div>
  </Deferred>
</template>
```

```jsx tab=React
import { Deferred } from "@inertiajs/react";

export default () => (
  <Deferred data="permissions" fallback={<div>Loading...</div>}>
    <PermissionsChildComponent />
  </Deferred>
);
```

```svelte tab=Svelte
<script>
    import { Deferred } from '@inertiajs/svelte'

    let { permissions } = $props()
</script>

<Deferred data="permissions">
    {#snippet fallback()}
        <div>Loading...</div>
    {/snippet}

    {#each permissions as permission}
        <!-- ... -->
    {/each}
</Deferred>
```

<a name="deferred-props-multiple-deferred-props"></a>

### Multiple Deferred Props

If you need to wait for multiple deferred props to become available, you can specify an array to the `data` prop.

```vue tab=Vue
<script setup>
import { Deferred } from "@inertiajs/vue3";
</script>

<template>
  <Deferred :data="['teams', 'users']">
    <template #fallback>
      <div>Loading...</div>
    </template>

    <!-- Props are now loaded -->
  </Deferred>
</template>
```

```jsx tab=React
import { Deferred } from "@inertiajs/react";

export default () => (
  <Deferred data={["teams", "users"]} fallback={<div>Loading...</div>}>
    <ChildComponent />
  </Deferred>
);
```

```svelte tab=Svelte
<script>
    import { Deferred } from '@inertiajs/svelte'

    let { teams, users } = $props()
</script>

<Deferred data={['teams', 'users']}>
    {#snippet fallback()}
        <div>Loading...</div>
    {/snippet}

    <!-- Props are now loaded -->
</Deferred>
```

<a name="deferred-props-reloading-indicator"></a>

### Reloading Indicator

When deferred props are being reloaded via a partial reload, the `Deferred` component exposes a `reloading` boolean through its slot. This allows you to show a loading indicator while still displaying the previously loaded data.

```vue tab=Vue
<script setup>
import { Deferred } from "@inertiajs/vue3";
</script>

<template>
  <Deferred data="permissions" #default="{ reloading }">
    <template #fallback>
      <div>Loading...</div>
    </template>

    <div :class="{ 'opacity-50': reloading }">
      <div v-for="permission in permissions">
        <!-- ... -->
      </div>
    </div>
  </Deferred>
</template>
```

```jsx tab=React
import { Deferred } from "@inertiajs/react";

export default () => (
  <Deferred data="permissions" fallback={<div>Loading...</div>}>
    {({ reloading }) => (
      <div className={reloading ? "opacity-50" : ""}>
        <PermissionsChildComponent />
      </div>
    )}
  </Deferred>
);
```

```svelte tab=Svelte
<script>
    import { Deferred } from '@inertiajs/svelte'

    let { permissions } = $props()
</script>

<Deferred data="permissions">
    {#snippet fallback()}
        <div>Loading...</div>
    {/snippet}

    {#snippet children({ reloading })}
        <div class:opacity-50={reloading}>
            {#each permissions as permission}
                <!-- ... -->
            {/each}
        </div>
    {/snippet}
</Deferred>
```

The `reloading` prop is `false` on the initial load and becomes `true` whenever a partial reload is in progress for the deferred keys. It returns to `false` once the reload completes.

<a name="deferred-props-error-handling"></a>

### Error Handling

By default, exceptions thrown while resolving a deferred prop result in an error response. You may instruct Inertia to rescue these exceptions by passing `rescue: true` to `Inertia::defer()`.

```php
Route::get('/users', function () {
    return Inertia::render('Users/Index', [
        'permissions' => Inertia::defer(fn () => Permission::all(), rescue: true),
    ]);
});
```

When a deferred prop is rescued, it is omitted from the response and the exception is reported via Hypervel's exception handler.

On the client side, you may provide a `rescue` slot to the `Deferred` component to render a fallback UI when a prop fails to load.

```vue tab=Vue
<script setup>
import { Deferred, router } from "@inertiajs/vue3";
</script>

<template>
  <Deferred data="permissions">
    <template #fallback>
      <div>Loading...</div>
    </template>

    <template #rescue="{ reloading }">
      <div>
        <p>Failed to load permissions.</p>
        <button :disabled="reloading" @click="router.reload({ only: ['permissions'] })">Retry</button>
      </div>
    </template>

    <div v-for="permission in permissions">
      <!-- ... -->
    </div>
  </Deferred>
</template>
```

```jsx tab=React
import { Deferred, router } from "@inertiajs/react";

export default () => (
  <Deferred
    data="permissions"
    fallback={<div>Loading...</div>}
    rescue={({ reloading }) => (
      <div>
        <p>Failed to load permissions.</p>
        <button disabled={reloading} onClick={() => router.reload({ only: ["permissions"] })}>Retry</button>
      </div>
    )}
  >
    <PermissionsChildComponent />
  </Deferred>
);
```

```svelte tab=Svelte
<script>
    import { Deferred, router } from '@inertiajs/svelte'

    let { permissions } = $props()
</script>

<Deferred data="permissions">
    {#snippet fallback()}
        <div>Loading...</div>
    {/snippet}

    {#snippet rescue({ reloading })}
        <div>
            <p>Failed to load permissions.</p>
            <button disabled={reloading} onclick={() => router.reload({ only: ['permissions'] })}>Retry</button>
        </div>
    {/snippet}

    {#each permissions as permission}
        <!-- ... -->
    {/each}
</Deferred>
```

The rescue state is preserved until you explicitly reload the rescued prop. The `rescue` slot receives a `reloading` boolean, allowing you to disable the retry button or show a loading indicator while the reload is in progress.

<a name="deferred-props-combining-with-once-props"></a>

### Combining with Once Props

You may chain the `once()` modifier onto a deferred prop to ensure the data is resolved only once and remembered by the client across subsequent navigations.

```php
return Inertia::render('Dashboard', [
    'stats' => Inertia::defer(fn () => Stats::generate())->once(),
]);
```

For more information on once props, see the [once props](#once-props) documentation.

<a name="merging-props"></a>

## Merging Props

Inertia overwrites props with the same name when reloading a page. However, you may need to merge new data with existing data instead. For example, when implementing a "load more" button for paginated results. The [Infinite scroll](#infinite-scroll) component uses prop merging under the hood.

Prop merging only works during [partial reloads](#partial-reloads). Full page visits will always replace props entirely, even if you've marked them for merging.

<a name="merging-props-merge-methods"></a>

### Merge Methods

To merge a prop instead of overwriting it, you may use the `Inertia::merge()` method when returning your response.

```php
Route::get('/items', function () {
    // Static array of tags...
    $allTags = [
        'Hypervel', 'React', 'Vue', 'Tailwind', 'Inertia',
        'PHP', 'JavaScript', 'TypeScript', 'Docker', 'Vite',
    ];

    // Get chunk of tags by page...
    $page = request()->input('page', 1);
    $perPage = 5;
    $offset = ($page - 1) * $perPage;
    $tags = array_slice($allTags, $offset, $perPage);

    return Inertia::render('Tags/Index', [
        'tags' => Inertia::merge($tags),
    ]);
});
```

The `Inertia::merge()` method will append new items to existing arrays at the root level. You may change this behavior to prepend items instead.

```php
// Append at root level (default)...
Inertia::merge($items);

// Prepend at root level...
Inertia::merge($items)->prepend();
```

For more precise control, you can target specific nested properties for merging while replacing the rest of the object.

```php
// Only append to the 'data' array, replace everything else...
Inertia::merge(User::paginate())->append('data');

// Prepend to the 'messages' array...
Inertia::merge($chatData)->prepend('messages');
```

You can combine multiple operations and target several properties at once.

```php
Inertia::merge($forumData)
    ->append('posts')
    ->prepend('announcements');

// Target multiple properties...
Inertia::merge($dashboardData)
    ->append(['notifications', 'activities']);
```

On the client side, Inertia handles all the merging automatically according to your server-side configuration.

<a name="merging-props-matching-items"></a>

### Matching Items

When merging arrays, you may use the `matchOn` parameter to match existing items by a specific field and update them instead of appending new ones.

```php
// Match posts by ID, update existing ones...
Inertia::merge($postData)->append('data', matchOn: 'id');

// Multiple properties with different match fields...
Inertia::merge($complexData)->append([
    'users.data' => 'id',
    'messages' => 'uuid',
]);
```

In the first example, Inertia will iterate over the `data` array and attempt to match each item by its `id` field. If a match is found, the existing item will be replaced. If no match is found, the new item will be appended.

<a name="merging-props-deep-merge"></a>

### Deep Merge

Instead of specifying which nested paths should be merged, you may use `Inertia::deepMerge()` to ensure a deep merge of the entire structure.

```php
Route::get('/chat', function () {
    $chatData = [
        'messages' => [
            ['id' => 4, 'text' => 'Hello there!', 'user' => 'Alice'],
            ['id' => 5, 'text' => 'How are you?', 'user' => 'Bob'],
        ],
        'online' => 12,
    ];

    return Inertia::render('Chat', [
        'chat' => Inertia::deepMerge($chatData)->matchOn('messages.id'),
    ]);
});
```

`Inertia::deepMerge()` was introduced before `Inertia::merge()` had support for prepending and targeting nested paths. In most cases, `Inertia::merge()` with its append and prepend methods should be sufficient.

<a name="merging-props-client-side-visits"></a>

### Client-Side Visits

You can also merge props directly on the client side without making a server request using [client side visits](#manual-visits-client-side-visits). Inertia provides [prop helper methods](#manual-visits-prop-helpers) that allow you to append, prepend, or replace prop values.

<a name="merging-props-combining-with-deferred-props"></a>

### Combining with Deferred Props

You may combine [deferred props](#deferred-props) with mergeable props to defer the loading of the prop and ultimately mark it as mergeable once it's loaded.

```php
Route::get('/users', function () {
    $page = request()->integer('page', 1);
    $perPage = request()->integer('per_page', 10);

    return Inertia::render('Users/Index', [
        'results' => Inertia::defer(fn() => User::paginate($perPage, page: $page))->deepMerge(),
    ]);
});
```

<a name="merging-props-combining-with-once-props"></a>

### Combining with Once Props

You may chain the `once()` modifier onto a merge prop to ensure the data is resolved only once and remembered by the client across subsequent navigations.

```php
return Inertia::render('Users/Index', [
    'activity' => Inertia::merge(fn () => $user->recentActivity())->once(),
]);
```

For more information on once props, see the [once props](#once-props) documentation.

<a name="merging-props-resetting-props"></a>

### Resetting Props

On the client side, you can indicate to the server that you would like to reset the prop. This is useful when you want to clear the prop value before merging new data, such as when the user enters a new search query on a paginated list.

The `reset` request option accepts an array of the prop keys you would like to reset.

```js
router.reload({
  reset: ["results"],
  // ...
});
```

<a name="once-props"></a>

## Once Props

Some data rarely changes, is expensive to compute, or is simply large. Rather than including this data in every response, you may use once props. These props are remembered by the client and reused on subsequent pages that include the same prop. This makes them ideal for [shared data](#shared-data).

<a name="once-props-creating-once-props"></a>

### Creating Once Props

To create a once prop, use the `Inertia::once()` method when returning your response. This method receives a callback that returns the prop data.

```php
return Inertia::render('Billing', [
    'plans' => Inertia::once(fn () => Plan::all()),
]);
```

After the client has received this prop, subsequent requests will skip resolving the callback and exclude the prop from the response. The client only remembers once props while navigating between pages that include them.

Navigating to a page without the once prop will forget the remembered value, and it will be resolved again on the next page that has it. In practice, this is rarely an issue since once props are typically used as shared data or within a specific section of your application.

<a name="once-props-forcing-a-refresh"></a>

### Forcing a Refresh

You may force a once prop to be refreshed using the `fresh()` method.

```php
return Inertia::render('Billing', [
    'plans' => Inertia::once(fn () => Plan::all())->fresh(),
]);
```

This method also accepts a boolean, allowing you to conditionally refresh the prop.

```php
return Inertia::render('Billing', [
    'plans' => Inertia::once(fn () => Plan::all())->fresh($condition),
]);
```

<a name="once-props-refreshing-from-the-client"></a>

### Refreshing from the Client

You may refresh a once prop from the client-side using a [partial reload](#partial-reloads). The server will always resolve a once prop when explicitly requested.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.reload({ only: ["plans"] });
```

```js tab=React
import { router } from "@inertiajs/react";

router.reload({ only: ["plans"] });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.reload({ only: ["plans"] });
```

<a name="once-props-expiration"></a>

### Expiration

You may set an expiration time using the `until()` method. This method accepts a `DateTimeInterface`, `DateInterval`, or an integer (seconds). The prop will be refreshed on a subsequent visit after the expiration time has passed.

```php
return Inertia::render('Dashboard', [
    'rates' => Inertia::once(fn () => ExchangeRate::all())->until(now()->addDay()),
]);
```

<a name="once-props-custom-keys"></a>

### Custom Keys

You may assign a custom key to the prop using the `as()` method. This is useful when you want to share data across multiple pages while using different prop names.

```php
// Team member list...
return Inertia::render('Team/Index', [
    'memberRoles' => Inertia::once(fn () => Role::all())->as('roles'),
]);

// Invite form...
return Inertia::render('Team/Invite', [
    'availableRoles' => Inertia::once(fn () => Role::all())->as('roles'),
]);
```

Both pages share the same underlying data because they use the same custom key, so the prop is only resolved for whichever page you visit first.

<a name="once-props-sharing-once-props"></a>

### Sharing Once Props

You may share once props globally using the `Inertia::share()` method.

```php
Inertia::share('countries', Inertia::once(fn () => Country::all()));
```

Or, for convenience, you may use the `shareOnce()` method.

```php
Inertia::shareOnce('countries', fn () => Country::all());
```

You may also chain `as()`, `fresh()`, and `until()` onto the `shareOnce` method.

```php
Inertia::shareOnce('countries', fn () => Country::all())->until(now()->addDay());
```

Additionally, you may define a dedicated `shareOnce()` method in your middleware. The middleware will evaluate both `share()` and `shareOnce()`, merging the results.

```php
class HandleInertiaRequests extends Middleware
{
    public function shareOnce(Request $request): array
    {
        return array_merge(parent::shareOnce($request), [
            'countries' => fn () => Country::all(),
        ]);
    }
}
```

<a name="once-props-conditional-once-props"></a>

### Conditional Once Props

Some shared data is mostly stable but changes at specific moments, like the authenticated user. You may return a once prop while a user is authenticated, and `null` otherwise. Sign in and sign out usually end with a redirect to a page that does not explicitly request the `auth` prop, so a remembered once value would go stale.

Returning `null` on signed-out requests overwrites the remembered user on every response, while returning a once prop when authenticated lets the client remember the user across visits.

```php
class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => $request->user()
                ? Inertia::once(fn () => $request->user())
                : null,
        ];
    }
}
```

<a name="once-props-prefetching"></a>

### Prefetching

Once props are compatible with [prefetching](#prefetching). The client automatically includes any remembered once props in prefetched responses, so navigating to a prefetched page will already have the once props available.

Prefetched pages containing an expired once prop will be invalidated from the cache.

<a name="once-props-combining-with-other-prop-types"></a>

### Combining with Other Prop Types

The `once()` modifier may be chained onto [deferred](#deferred-props), [merge](#merging-props), and [optional](#partial-reloads-lazy-data-evaluation) props.

```php
return Inertia::render('Dashboard', [
    'permissions' => Inertia::defer(fn () => Permission::all())->once(),
    'activity' => Inertia::merge(fn () => $user->recentActivity())->once(),
    'categories' => Inertia::optional(fn () => Category::all())->once(),
]);
```

<a name="polling"></a>

## Polling

<a name="polling-poll-helper"></a>

### Poll Helper

Polling your server for new information on the current page is common, so Inertia provides a helper to reduce boilerplate. It automatically stops polling when the page is unmounted.

The only required argument is the polling interval in milliseconds.

```js tab=Vue
import { usePoll } from '@inertiajs/vue3'

usePoll(2000)
```

```jsx tab=React
import { usePoll } from '@inertiajs/react'

usePoll(2000)
```

```js tab=Svelte
import { usePoll } from '@inertiajs/svelte'

usePoll(2000)
```

If you need to pass additional request options to the poll helper, you can pass any of the `router.reload` options as the second parameter.

```js tab=Vue
import { usePoll } from '@inertiajs/vue3'

usePoll(2000, {
    onStart() {
        console.log('Polling request started')
    },
    onFinish() {
        console.log('Polling request finished')
    },
})
```

```jsx tab=React
import { usePoll } from '@inertiajs/react'

usePoll(2000, {
    onStart() {
        console.log('Polling request started')
    },
    onFinish() {
        console.log('Polling request finished')
    },
})
```

```js tab=Svelte
import { usePoll } from '@inertiajs/svelte'

usePoll(2000, {
    onStart() {
        console.log('Polling request started')
    },
    onFinish() {
        console.log('Polling request finished')
    },
})
```

You may also pass a function that returns the request options. The function is evaluated on every tick, allowing the poll to reflect the latest component state.

```vue tab=Vue
<script setup>
import { usePoll } from '@inertiajs/vue3'

const props = defineProps({ counter: Number })

usePoll(2000, () => ({
    data: { counter_seen: props.counter },
    only: ['last_received'],
}))
</script>
```

```jsx tab=React
import { usePoll } from '@inertiajs/react'

export default ({ counter }) => {
    usePoll(2000, () => ({
        data: { counter_seen: counter },
        only: ['last_received'],
    }))
}
```

```js tab=Svelte
import { usePoll } from '@inertiajs/svelte'

let { counter } = $props()

usePoll(2000, () => ({
    data: { counter_seen: counter },
    only: ['last_received'],
}))
```

If you'd like more control over the polling behavior, the poll helper provides `stop` and `start` methods that allow you to manually start and stop polling. You can pass the `autoStart: false` option to the poll helper to prevent it from automatically starting polling when the component is mounted.

```vue tab=Vue
<script setup>
import { usePoll } from '@inertiajs/vue3'

const { start, stop } = usePoll(2000, {}, {
    autoStart: false,
})
</script>

<template>
    <button @click="start">Start polling</button>
    <button @click="stop">Stop polling</button>
</template>
```

```jsx tab=React
import { usePoll } from '@inertiajs/react'

export default () => {
    const { start, stop } = usePoll(2000, {}, {
        autoStart: false,
    })

    return (
        <div>
            <button onClick={start}>Start polling</button>
            <button onClick={stop}>Stop polling</button>
        </div>
    )
}
```

```js tab=Svelte
import { usePoll } from '@inertiajs/svelte'

const { start, stop } = usePoll(2000, {}, {
    autoStart: false,
})
```

**v3.7.0+**

The poll helper also returns a reactive `polling` value that reflects whether the poll is running, making it ideal for rendering a pause or resume button. The value doesn't indicate whether a request is in flight.

```vue tab=Vue
<script setup>
import { usePoll } from '@inertiajs/vue3'

const { start, stop, polling } = usePoll(2000)
</script>

<template>
    <button v-if="polling" @click="stop">Pause updates</button>
    <button v-else @click="start">Resume updates</button>
</template>
```

```jsx tab=React
import { usePoll } from '@inertiajs/react'

export default () => {
    const { start, stop, polling } = usePoll(2000)

    return polling
        ? <button onClick={stop}>Pause updates</button>
        : <button onClick={start}>Resume updates</button>
}
```

```svelte tab=Svelte
<script>
import { usePoll } from '@inertiajs/svelte'

const poll = usePoll(2000)
</script>

{#if poll.polling}
    <button onclick={poll.stop}>Pause updates</button>
{:else}
    <button onclick={poll.start}>Resume updates</button>
{/if}
```

<a name="polling-concurrency-mode"></a>

### Concurrency Mode

By default, the poll helper fires a new request on every tick, even when the previous request is still in flight. You may control this behavior with the `mode` option, which accepts one of three values: `overlap`, `cancel`, or `rest`.

The default `overlap` mode allows requests to run in parallel. The `cancel` mode aborts any in-flight request when the next tick fires. The `rest` mode treats the interval as the time between the end of the previous request and the start of the next one, so requests never overlap.

```js tab=Vue
import { usePoll } from '@inertiajs/vue3'

usePoll(2000, {}, {
    mode: 'rest',
})
```

```jsx tab=React
import { usePoll } from '@inertiajs/react'

usePoll(2000, {}, {
    mode: 'rest',
})
```

```js tab=Svelte
import { usePoll } from '@inertiajs/svelte'

usePoll(2000, {}, {
    mode: 'rest',
})
```

<a name="polling-background-tabs"></a>

### Background Tabs

**v3.9.0+**

By default, the poll helper throttles requests by 90% when the browser tab is in the background. You may control this behavior with the `background` option, which accepts one of three values: `throttle`, `pause`, or `continue`.

The `pause` mode stops polling entirely while the tab is hidden. Once the tab becomes visible again, polling resumes, firing a request immediately if the interval has already elapsed since the last one. The `continue` mode keeps polling at the regular interval, even while the tab is hidden.

```js tab=Vue
import { usePoll } from '@inertiajs/vue3'

usePoll(2000, {}, {
    background: 'pause',
})
```

```jsx tab=React
import { usePoll } from '@inertiajs/react'

usePoll(2000, {}, {
    background: 'pause',
})
```

```js tab=Svelte
import { usePoll } from '@inertiajs/svelte'

usePoll(2000, {}, {
    background: 'pause',
})
```

> [!NOTE]
> The `keepAlive` option is deprecated in favor of `background: 'continue'`.

<a name="prefetching"></a>

## Prefetching

Inertia supports prefetching data for pages that are likely to be visited next. This can be useful for improving the perceived performance of your app by allowing the data to be fetched in the background while the user is still interacting with the current page.

<a name="prefetching-link-prefetching"></a>

### Link Prefetching

To prefetch data for a page, you can add the `prefetch` prop to the Inertia link component. By default, Inertia will prefetch the data for the page when the user hovers over the link for more than 75ms. You may customize this hover delay by setting the `prefetch.hoverDelay` option in your [application defaults](#client-side-setup-configuring-defaults).

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/users" prefetch>Users</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/users" prefetch>
  Users
</Link>;
```

```svelte tab=Svelte
import { inertia } from '@inertiajs/svelte'

<a href="/users" use:inertia={{ prefetch: true }}>Users</a>
```

By default, data is cached for 30 seconds before being evicted. You may customize this default value by setting the `prefetch.cacheFor` option in your [application defaults](#client-side-setup-configuring-defaults). You may also customize the cache duration on a per-link basis by passing a `cacheFor` prop to the `Link` component.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/users" prefetch cache-for="1m">Users</Link>
<Link href="/users" prefetch cache-for="10s">Users</Link>
<Link href="/users" prefetch :cache-for="5000">Users</Link>
```

```jsx tab=React
import { Link } from '@inertiajs/react'

<Link href="/users" prefetch cacheFor="1m">Users</Link>
<Link href="/users" prefetch cacheFor="10s">Users</Link>
<Link href="/users" prefetch cacheFor={5000}>Users</Link>
```

```svelte tab=Svelte
import { inertia } from '@inertiajs/svelte'

<a href="/users" use:inertia={{ prefetch: true, cacheFor: '1m' }}>Users</a>
<a href="/users" use:inertia={{ prefetch: true, cacheFor: '10s' }}>Users</a>
<a href="/users" use:inertia={{ prefetch: true, cacheFor: 5000 }}>Users</a>
```

Instead of prefetching on hover, you can also start prefetching on `mousedown` by passing the `click` value to the `prefetch` prop.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/users" prefetch="click">Users</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/users" prefetch="click">
  Users
</Link>;
```

```svelte tab=Svelte
import { inertia } from '@inertiajs/svelte'

<a href="/users" use:inertia={{ prefetch: 'click' }}>Users</a>
```

If you're confident that the user will visit a page next, you can prefetch the data on mount as well.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/users" prefetch="mount">Users</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/users" prefetch="mount">
  Users
</Link>;
```

```svelte tab=Svelte
import { inertia } from '@inertiajs/svelte'

<a href="/users" use:inertia={{ prefetch: 'mount' }}>Users</a>
```

You can also combine prefetch strategies by passing an array of values to the `prefetch` prop.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/users" :prefetch="['mount', 'hover']">Users</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/users" prefetch={["mount", "hover"]}>
  Users
</Link>;
```

```svelte tab=Svelte
import { inertia } from '@inertiajs/svelte'

<a href="/users" use:inertia={{ prefetch: ['mount', 'hover'] }}>Users</a>
```

<a name="prefetching-programmatic-prefetching"></a>

### Programmatic Prefetching

You can prefetch data programmatically using `router.prefetch`. This method's signature is identical to `router.visit` with the exception of a third argument that allows you to specify prefetch options.

When the `cacheFor` option is not specified, it defaults to 30 seconds.

```js
router.prefetch("/users", { method: "get", data: { page: 2 } });

router.prefetch(
  "/users",
  { method: "get", data: { page: 2 } },
  { cacheFor: "1m" },
);
```

Inertia also provides a `usePrefetch` hook that allows you to track the prefetch state for the current page. It returns information about whether the page is currently prefetching, has been prefetched, when it was last updated, and a `flush` method that flushes the cache for the current page only.

```js tab=Vue
import { usePrefetch } from "@inertiajs/vue3";

const { lastUpdatedAt, isPrefetching, isPrefetched, flush } = usePrefetch();
```

```js tab=React
import { usePrefetch } from "@inertiajs/react";

const { lastUpdatedAt, isPrefetching, isPrefetched, flush } = usePrefetch();
```

```js tab=Svelte
import { usePrefetch } from "@inertiajs/svelte";

const { lastUpdatedAt, isPrefetching, isPrefetched, flush } = usePrefetch();
```

You can also pass visit options when you need to differentiate between different request configurations for the same URL.

```js tab=Vue
import { usePrefetch } from "@inertiajs/vue3";

const { lastUpdatedAt, isPrefetching, isPrefetched, flush } = usePrefetch({
  headers: { "X-Custom-Header": "value" },
});
```

```js tab=React
import { usePrefetch } from "@inertiajs/react";

const { lastUpdatedAt, isPrefetching, isPrefetched, flush } = usePrefetch({
  headers: { "X-Custom-Header": "value" },
});
```

```js tab=Svelte
import { usePrefetch } from "@inertiajs/svelte";

const { lastUpdatedAt, isPrefetching, isPrefetched, flush } = usePrefetch({
  headers: { "X-Custom-Header": "value" },
});
```

<a name="prefetching-cache-tags"></a>

### Cache Tags

Cache tags allow you to group related prefetched data and invalidate all cached data with that tag when specific events occur.

To tag cached data, pass a `cacheTags` prop to your `Link` component.

```vue tab=Vue
<script setup>
import { Link } from "@inertiajs/vue3";
</script>

<template>
  <Link href="/users" prefetch cache-tags="users">Users</Link>
  <Link href="/dashboard" prefetch :cache-tags="['dashboard', 'stats']"
    >Dashboard</Link
  >
</template>
```

```jsx tab=React
import { Link } from '@inertiajs/react'

<Link href="/users" prefetch cacheTags="users">Users</Link>
<Link href="/dashboard" prefetch cacheTags={['dashboard', 'stats']}>Dashboard</Link>
```

```svelte tab=Svelte
import { inertia } from '@inertiajs/svelte'

<a href="/users" use:inertia={{ prefetch: true, cacheTags: 'users' }}>Users</a>
<a href="/dashboard" use:inertia={{ prefetch: true, cacheTags: ['dashboard', 'stats'] }}>Dashboard</a>
```

When prefetching programmatically, pass `cacheTags` in the third argument to `router.prefetch`.

```js
router.prefetch("/users", {}, { cacheTags: "users" });
router.prefetch("/dashboard", {}, { cacheTags: ["dashboard", "stats"] });
```

<a name="prefetching-cache-invalidation"></a>

### Cache Invalidation

You can manually flush the prefetch cache by calling `router.flushAll` to remove all cached data, or `router.flush` to remove cache for a specific page.

```js
// Flush all prefetch cache
router.flushAll();

// Flush cache for a specific page
router.flush("/users", { method: "get", data: { page: 2 } });

// Using the usePrefetch hook
const { flush } = usePrefetch();

// Flush cache for the current page
flush();
```

For more granular control, you can flush cached data by their tags using `router.flushByCacheTags`. This removes any cached response that contains _any_ of the specified tags.

```js
// Flush all responses tagged with 'users'
router.flushByCacheTags("users");

// Flush all responses tagged with 'dashboard' OR 'stats'
router.flushByCacheTags(["dashboard", "stats"]);
```

<a name="prefetching-automatic-cache-flushing"></a>

#### Automatic Cache Flushing

By default, Inertia does not automatically flush the prefetch cache when you navigate to new pages. Cached data is only evicted when it expires based on the cache duration. If you want to flush all cached data on every navigation, you can set up an event listener.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("navigate", () => router.flushAll());
```

```js tab=React
import { router } from "@inertiajs/react";

router.on("navigate", () => router.flushAll());
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("navigate", () => router.flushAll());
```

<a name="prefetching-invalidate-on-requests"></a>

#### Invalidate on Requests

To automatically invalidate caches when making requests, pass an `invalidateCacheTags` prop to the `Form` component. The specified tags will be flushed when the form submission succeeds.

```vue tab=Vue
<script setup>
import { Form } from "@inertiajs/vue3";
</script>

<template>
  <Form
    action="/users"
    method="post"
    :invalidate-cache-tags="['users', 'dashboard']"
  >
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Create User</button>
  </Form>
</template>
```

```jsx tab=React
import { Form } from "@inertiajs/react";

export default () => (
  <Form
    action="/users"
    method="post"
    invalidateCacheTags={["users", "dashboard"]}
  >
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Create User</button>
  </Form>
);
```

```svelte tab=Svelte
<script>
    import { Form } from '@inertiajs/svelte'
</script>

<Form action="/users" method="post" invalidateCacheTags={['users', 'dashboard']}>
    <input type="text" name="name" />
    <input type="email" name="email" />
    <button type="submit">Create User</button>
</Form>
```

When using the `useForm` helper, you can include `invalidateCacheTags` in the visit options.

```js tab=Vue
import { useForm } from "@inertiajs/vue3";

const form = useForm({
  name: "",
  email: "",
});

const submit = () => {
  form.post("/users", {
    invalidateCacheTags: ["users", "dashboard"],
  });
};
```

```js tab=React
import { useForm } from "@inertiajs/react";

const { data, setData, post } = useForm({
  name: "",
  email: "",
});

function submit(e) {
  e.preventDefault();
  post("/users", {
    invalidateCacheTags: ["users", "dashboard"],
  });
}
```

```js tab=Svelte
import { useForm } from "@inertiajs/svelte";

const form = useForm({
  name: "",
  email: "",
});

function submit() {
  form.post("/users", {
    invalidateCacheTags: ["users", "dashboard"],
  });
}
```

You can also invalidate cache tags with programmatic visits by including `invalidateCacheTags` in the options.

```js
router.delete(`/users/${userId}`, {
  invalidateCacheTags: ["users", "dashboard"],
});

router.post("/posts", postData, {
  invalidateCacheTags: ["posts", "recent-posts"],
});
```

<a name="prefetching-stale-while-revalidate"></a>

### Stale While Revalidate

By default, Inertia will fetch a fresh copy of the data when the user visits the page if the cached data is older than the cache duration. You can customize this behavior by passing a tuple to the `cacheFor` prop.

The first value in the array represents the number of seconds the cache is considered fresh, while the second value defines how long it can be served as stale data before fetching data from the server is necessary.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/users" prefetch :cacheFor="['30s', '1m']">Users</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link href="/users" prefetch cacheFor={["30s", "1m"]}>
  Users
</Link>;
```

```svelte tab=Svelte
import { inertia } from '@inertiajs/svelte'

<a href="/users" use:inertia={{ prefetch: true, cacheFor: ['30s', '1m'] }}>Users</a>
```

If a request is made within the fresh period (before the first value), the cache is returned immediately without making a request to the server.

If a request is made during the stale period (between the two values), the stale value is served to the user, and a request is made in the background to refresh the cached data. Once the fresh data is returned, it is merged into the page so the user has the most recent data.

If a request is made after the second value, the cache is considered expired, and the page and data are fetched from the server as a regular request.

<a name="load-when-visible"></a>

## Load When Visible

Inertia supports lazy loading data on scroll using the Intersection Observer API. It provides the `WhenVisible` component as a convenient way to load data when an element becomes visible in the viewport.

The `WhenVisible` component accepts a `data` prop that specifies the key of the prop to load. It also accepts a `fallback` prop that specifies a component to render while the data is loading. The `WhenVisible` component should wrap the component that depends on the data.

```vue tab=Vue
<script setup>
import { WhenVisible } from "@inertiajs/vue3";
</script>

<template>
  <WhenVisible data="permissions">
    <template #fallback>
      <div>Loading...</div>
    </template>

    <div v-for="permission in permissions">
      <!-- ... -->
    </div>
  </WhenVisible>
</template>
```

```jsx tab=React
import { WhenVisible } from "@inertiajs/react";

export default () => (
  <WhenVisible data="permissions" fallback={() => <div>Loading...</div>}>
    <PermissionsChildComponent />
  </WhenVisible>
);
```

```svelte tab=Svelte
<script>
    import { WhenVisible } from '@inertiajs/svelte'

    let { permissions } = $props()
</script>

<WhenVisible data="permissions">
    {#snippet fallback()}
        <div>Loading...</div>
    {/snippet}

    {#each permissions as permission}
        <!-- ... -->
    {/each}
</WhenVisible>
```

If you'd like to load multiple props when an element becomes visible, you can provide an array to the `data` prop.

```vue tab=Vue
<script setup>
import { WhenVisible } from "@inertiajs/vue3";
</script>

<template>
  <WhenVisible :data="['teams', 'users']">
    <template #fallback>
      <div>Loading...</div>
    </template>

    <!-- Props are now loaded -->
  </WhenVisible>
</template>
```

```jsx tab=React
import { WhenVisible } from "@inertiajs/react";

export default () => (
  <WhenVisible data={["teams", "users"]} fallback={() => <div>Loading...</div>}>
    <ChildComponent />
  </WhenVisible>
);
```

```svelte tab=Svelte
<script>
    import { WhenVisible } from '@inertiajs/svelte'

    let { teams, users } = $props()
</script>

<WhenVisible data={['teams', 'users']}>
    {#snippet fallback()}
        <div>Loading...</div>
    {/snippet}

    <!-- Props are now loaded -->
</WhenVisible>
```

<a name="load-when-visible-loading-before-visible"></a>

### Loading Before Visible

If you'd like to start loading data before the element is visible, you can provide a value to the `buffer` prop. The buffer value is a number that represents the number of pixels before the element is visible.

```vue tab=Vue
<script setup>
import { WhenVisible } from "@inertiajs/vue3";
</script>

<template>
  <WhenVisible data="permissions" :buffer="500">
    <template #fallback>
      <div>Loading...</div>
    </template>

    <div v-for="permission in permissions">
      <!-- ... -->
    </div>
  </WhenVisible>
</template>
```

```jsx tab=React
import { WhenVisible } from "@inertiajs/react";

export default () => (
  <WhenVisible
    data="permissions"
    buffer={500}
    fallback={() => <div>Loading...</div>}
  >
    <PermissionsChildComponent />
  </WhenVisible>
);
```

```svelte tab=Svelte
<script>
    import { WhenVisible } from '@inertiajs/svelte'

    let { permissions } = $props()
</script>

<WhenVisible data="permissions" buffer={500}>
    {#snippet fallback()}
        <div>Loading...</div>
    {/snippet}

    {#each permissions as permission}
        <!-- ... -->
    {/each}
</WhenVisible>
```

In the above example, the data will start loading 500 pixels before the element is visible.

By default, the `WhenVisible` component wraps the fallback template in a `div` element so it can ensure the element is visible in the viewport. If you want to customize the wrapper element, you can provide the `as` prop.

```vue tab=Vue
<script setup>
import { WhenVisible } from "@inertiajs/vue3";
</script>

<template>
  <WhenVisible data="products" as="span">
    <!-- ... -->
  </WhenVisible>
</template>
```

```jsx tab=React
import { WhenVisible } from "@inertiajs/react";

export default () => (
  <WhenVisible data="products" as="span">
    <ProductsChildComponent />
  </WhenVisible>
);
```

```svelte tab=Svelte
<script>
    import { WhenVisible } from '@inertiajs/svelte'

    let { products } = $props()
</script>

<WhenVisible data="products" as="span">
    <!-- ... -->
</WhenVisible>
```

<a name="load-when-visible-always-trigger"></a>

### Always Trigger

By default, the `WhenVisible` component will only trigger once when the element becomes visible. If you want to always trigger the data loading when the element is visible, you can provide the `always` prop.

This is useful when you want to load data every time the element becomes visible, such as when the element is at the end of an infinite scroll list and you want to load more data. Alternatively, you can use the [Infinite scroll](#infinite-scroll) component which handles this use case for you.

Note that if the data loading request is already in flight, the component will wait until it is finished to start the next request if the element is still visible in the viewport.

```vue tab=Vue
<script setup>
import { WhenVisible } from "@inertiajs/vue3";
</script>

<template>
  <WhenVisible data="products" always>
    <!-- ... -->
  </WhenVisible>
</template>
```

```jsx tab=React
import { WhenVisible } from "@inertiajs/react";

export default () => (
  <WhenVisible data="products" always>
    <ProductsChildComponent />
  </WhenVisible>
);
```

```svelte tab=Svelte
<script>
    import { WhenVisible } from '@inertiajs/svelte'

    let { products } = $props()
</script>

<WhenVisible data="products" always>
    <!-- ... -->
</WhenVisible>
```

<a name="load-when-visible-fetching-state"></a>

#### Fetching State

The `WhenVisible` component exposes a `fetching` slot prop that you may use to display a loading indicator during subsequent requests. This is useful because the `fallback` is only shown on the initial load, while `fetching` allows you to indicate that data is being refreshed on subsequent loads.

```vue tab=Vue
<script setup>
import { WhenVisible } from "@inertiajs/vue3";
</script>

<template>
  <WhenVisible data="permissions" always>
    <template #default="{ fetching }">
      <PermissionsChildComponent />
      <div v-if="fetching">Refreshing...</div>
    </template>

    <template #fallback>
      <div>Loading...</div>
    </template>
  </WhenVisible>
</template>
```

```jsx tab=React
import { WhenVisible } from "@inertiajs/react";

export default () => (
  <WhenVisible data="permissions" always fallback={() => <div>Loading...</div>}>
    {({ fetching }) => (
      <>
        <PermissionsChildComponent />
        {fetching && <div>Refreshing...</div>}
      </>
    )}
  </WhenVisible>
);
```

```svelte tab=Svelte
<script>
    import { WhenVisible } from '@inertiajs/svelte'

    let { permissions } = $props()
</script>

<WhenVisible data="permissions" always>
    {#snippet children({ fetching })}
        <PermissionsChildComponent />
        {#if fetching}
            <div>Refreshing...</div>
        {/if}
    {/snippet}

    {#snippet fallback()}
        <div>Loading...</div>
    {/snippet}
</WhenVisible>
```

<a name="load-when-visible-preserving-errors"></a>

### Preserving Errors

The `WhenVisible` component sets `preserveErrors: true` by default, ensuring that validation errors are not cleared when it triggers a reload. You may override this via the `params` prop.

```vue tab=Vue
<WhenVisible data="comments" :params="{ preserveErrors: false }">
    <!-- ... -->
</WhenVisible>
```

```jsx tab=React
<WhenVisible data="comments" params={{ preserveErrors: false }}>
    {/* ... */}
</WhenVisible>
```

```svelte tab=Svelte
<WhenVisible data="comments" params={{ preserveErrors: false }}>
    <!-- ... -->
</WhenVisible>
```

<a name="load-when-visible-form-submissions"></a>

### Form Submissions

When submitting forms, you may want to use the `except` option to exclude the props that are being used by the `WhenVisible` component. This prevents the props from being reloaded when you get redirected back to the current page because of validation errors.

```vue tab=Vue
<script setup>
import { useForm, WhenVisible } from "@inertiajs/vue3";

const form = useForm({
  name: "",
  email: "",
});

function submit() {
  form.post("/users", {
    except: ["permissions"],
  });
}
</script>

<template>
  <form @submit.prevent="submit">
    <!-- ... -->
  </form>

  <WhenVisible data="permissions">
    <!-- ... -->
  </WhenVisible>
</template>
```

```jsx tab=React
import { useForm, WhenVisible } from "@inertiajs/react";

export default function CreateUser() {
  const { data, setData, post } = useForm({
    name: "",
    email: "",
  });

  function submit(e) {
    e.preventDefault();
    post("/users", {
      except: ["permissions"],
    });
  }

  return (
    <>
      <form onSubmit={submit}>{/* ... */}</form>

      <WhenVisible data="permissions">{/* ... */}</WhenVisible>
    </>
  );
}
```

```svelte tab=Svelte
<script>
    import { useForm, WhenVisible } from '@inertiajs/svelte'

    const form = useForm({
        name: '',
        email: '',
    })

    function submit() {
        form.post('/users', {
            except: ['permissions'],
        })
    }
</script>

<form onsubmit={submit}>
    <!-- ... -->
</form>

<WhenVisible data="permissions">
    <!-- ... -->
</WhenVisible>
```

<a name="infinite-scroll"></a>

## Infinite Scroll

Inertia's infinite scroll feature loads additional pages of content as users scroll, replacing traditional pagination controls. This is great for applications like chat interfaces, social feeds, photo grids, and product listings.

<a name="infinite-scroll-server-side"></a>

### Server-Side

To configure your paginated data for infinite scrolling, you should use the `Inertia::scroll()` method when returning your response. This method automatically configures the proper merge behavior and normalizes pagination metadata for the front-end component.

```php
Route::get('/users', function () {
    return Inertia::render('Users/Index', [
        'users' => Inertia::scroll(fn () => User::paginate())
    ]);
});
```

The `Inertia::scroll()` method works with Hypervel's `paginate()`, `simplePaginate()`, and `cursorPaginate()` methods, as well as pagination data wrapped in [Eloquent API resources](/docs/{{version}}/eloquent-resources). For more details, check out the [Inertia::scroll() method](#infinite-scroll-inertiascroll-method) documentation.

<a name="infinite-scroll-client-side"></a>

### Client-Side

On the client side, Inertia provides the `<InfiniteScroll>` component to automatically load additional pages of content. The component accepts a `data` prop that specifies the key of the prop containing your paginated data. The `<InfiniteScroll>` component should wrap the content that depends on the paginated data.

```vue tab=Vue
<script setup>
import { InfiniteScroll } from "@inertiajs/vue3";

defineProps(["users"]);
</script>

<template>
  <InfiniteScroll data="users">
    <div v-for="user in users.data" :key="user.id">
      {{ user.name }}
    </div>
  </InfiniteScroll>
</template>
```

```jsx tab=React
import { InfiniteScroll } from "@inertiajs/react";

export default function Users({ users }) {
  return (
    <InfiniteScroll data="users">
      {users.data.map((user) => (
        <div key={user.id}>{user.name}</div>
      ))}
    </InfiniteScroll>
  );
}
```

```svelte tab=Svelte
<script>
    import { InfiniteScroll } from '@inertiajs/svelte'
    let { users } = $props()
</script>

<InfiniteScroll data="users">
    {#each users.data as user (user.id)}
        <div>{user.name}</div>
    {/each}
</InfiniteScroll>
```

The component uses [intersection observers](https://developer.mozilla.org/en-US/docs/Web/API/Intersection_Observer_API) to detect when users scroll near the end of the content and automatically triggers requests to load the next page. New data is merged with existing content rather than replacing it.

<a name="infinite-scroll-loading-buffer"></a>

### Loading Buffer

You can control how early content begins loading by setting a buffer distance. The buffer specifies how many pixels before the end of the content loading should begin.

```vue tab=Vue
<InfiniteScroll data="users" :buffer="500">
    <!-- ... -->
</InfiniteScroll>
```

```jsx tab=React
<InfiniteScroll data="users" buffer={500}>
  {/* ... */}
</InfiniteScroll>
```

```svelte tab=Svelte
<InfiniteScroll data="users" buffer={500}>
    <!-- ... -->
</InfiniteScroll>
```

In the example above, content will start loading 500 pixels before reaching the end of the current content. A larger buffer loads content earlier but potentially loads content that users may never see.

<a name="infinite-scroll-url-synchronization"></a>

### URL Synchronization

The infinite scroll component updates the browser URL's query string (`?page=...`) as users scroll through content. The URL reflects which page has the most visible items on screen, updating in both directions as users scroll up or down. This allows users to bookmark or share links to specific pages. You can disable this behavior to maintain the original page URL.

```vue tab=Vue
<InfiniteScroll data="users" preserve-url>
    <!-- ... -->
</InfiniteScroll>
```

```jsx tab=React
<InfiniteScroll data="users" preserveUrl>
  {/* ... */}
</InfiniteScroll>
```

```svelte tab=Svelte
<InfiniteScroll data="users" preserve-url>
    <!-- ... -->
</InfiniteScroll>
```

This is useful when infinite scroll is used for secondary content that shouldn't affect the main page URL, such as comments on a blog post or related products on a product page.

<a name="infinite-scroll-resetting"></a>

### Resetting

When filters or other parameters change, you may need to reset the infinite scroll data to start from the beginning. Without resetting, new results will merge with existing content instead of replacing it.

You can reset data using the `reset` visit option.

```vue tab=Vue
<script setup>
import { router } from "@inertiajs/vue3";

const show = (role) => {
  router.visit(route("users"), {
    data: { filter: { role } },
    only: ["users"],
    reset: ["users"],
  });
};
</script>

<template>
  <button @click="show('admin')">Show admins</button>
  <button @click="show('customer')">Show customers</button>

  <InfiniteScroll data="users">
    <div v-for="user in users.data" :key="user.id">
      {{ user.name }}
    </div>
  </InfiniteScroll>
</template>
```

```jsx tab=React
import { InfiniteScroll, router } from '@inertiajs/react'

export default function Users({ users }) {
    const show = (role) => {
        router.visit(route('users'), {
            data: { filter: { role } },
            only: ['users'],
            reset: ['users'],
        })
    }

    return (

            <button onClick={() => show('admin')}>Show admins</button>
            <button onClick={() => show('customer')}>Show customers</button>

            <InfiniteScroll data="users">
                {users.data.map(user => (
                    <div key={user.id}>
                        {user.name}
                    </div>
                ))}
            </InfiniteScroll>

    )
}
```

```svelte tab=Svelte
<script>
    import { InfiniteScroll, router } from '@inertiajs/svelte'
    let { users } = $props()

    const show = (role) => {
        router.visit(route('users'), {
            data: { filter: { role } },
            only: ['users'],
            reset: ['users'],
        })
    }
</script>

<button onclick={() => show('admin')}>Show admins</button>
<button onclick={() => show('customer')}>Show customers</button>

<InfiniteScroll data="users">
    {#each users.data as user (user.id)}
        <div>{user.name}</div>
    {/each}
</InfiniteScroll>
```

For more information about the reset option, see the [Resetting props](#merging-props-resetting-props) documentation.

<a name="infinite-scroll-loading-direction"></a>

### Loading Direction

The infinite scroll component loads content in both directions when you scroll near the start or end. You can control this behavior using the `only-next` and `only-previous` props.

```vue tab=Vue
<!-- Only load the next page -->
<InfiniteScroll data="users" only-next>
    <!-- ... -->
</InfiniteScroll>

<!-- Only load the previous page -->
<InfiniteScroll data="messages" only-previous>
    <!-- ... -->
</InfiniteScroll>

<!-- Load in both directions (default) -->
<InfiniteScroll data="posts">
    <!-- ... -->
</InfiniteScroll>
```

```jsx tab=React
{
  /* Only load the next page */
}
<InfiniteScroll data="users" onlyNext>
  {/* ... */}
</InfiniteScroll>;

{
  /* Only load the previous page */
}
<InfiniteScroll data="messages" onlyPrevious>
  {/* ... */}
</InfiniteScroll>;

{
  /* Load in both directions (default) */
}
<InfiniteScroll data="posts">{/* ... */}</InfiniteScroll>;
```

```svelte tab=Svelte
<!-- Only load the next page -->
<InfiniteScroll data="users" only-next>
    <!-- ... -->
</InfiniteScroll>

<!-- Only load the previous page -->
<InfiniteScroll data="messages" only-previous>
    <!-- ... -->
</InfiniteScroll>

<!-- Load in both directions (default) -->
<InfiniteScroll data="posts">
    <!-- ... -->
</InfiniteScroll>
```

The default option is particularly useful when users start on a middle page and need to scroll in both directions to access all content.

<a name="infinite-scroll-reverse-mode"></a>

### Reverse Mode

For chat applications, timelines, or interfaces where content is sorted descendingly (newest items at the bottom), you can enable reverse mode. This configures the component to load older content when scrolling upward.

```vue tab=Vue
<InfiniteScroll data="messages" reverse>
    <!-- ... -->
</InfiniteScroll>
```

```jsx tab=React
<InfiniteScroll data="messages" reverse>
  {/* ... */}
</InfiniteScroll>
```

```svelte tab=Svelte
<InfiniteScroll data="messages" reverse>
    <!-- ... -->
</InfiniteScroll>
```

In reverse mode, the component flips the loading directions so that scrolling up loads the next page (older content) and scrolling down loads the previous page (newer content). The component handles the loading positioning, but you are responsible for reversing your content to display in the correct order.

Reverse mode also enables automatic scrolling to the bottom on initial load, which you can disable with `:auto-scroll="false"`.

```vue tab=Vue
<InfiniteScroll data="messages" reverse :auto-scroll="false">
    <!-- ... -->
</InfiniteScroll>
```

```jsx tab=React
<InfiniteScroll data="messages" reverse autoScroll={false}>
  {/* ... */}
</InfiniteScroll>
```

```svelte tab=Svelte
<InfiniteScroll data="messages" reverse auto-scroll={false}>
    <!-- ... -->
</InfiniteScroll>
```

<a name="infinite-scroll-manual-mode"></a>

### Manual Mode

Manual mode disables automatic loading when scrolling and allows you to control when content loads through the `next` and `previous` slots. For more details about available slot properties and customization options, see the [Slots](#infinite-scroll-slots) documentation.

```vue tab=Vue
<template>
  <InfiniteScroll data="users" manual>
    <template #previous="{ loading, fetch, hasMore }">
      <button v-if="hasMore" @click="fetch" :disabled="loading">
        {{ loading ? "Loading..." : "Load previous" }}
      </button>
    </template>

    <!-- Your content -->

    <template #next="{ loading, fetch, hasMore }">
      <button v-if="hasMore" @click="fetch" :disabled="loading">
        {{ loading ? "Loading..." : "Load more" }}
      </button>
    </template>
  </InfiniteScroll>
</template>
```

```jsx tab=React
import { InfiniteScroll } from "@inertiajs/react";

export default ({ users }) => (
  <InfiniteScroll
    data="users"
    manual
    previous={({ loading, fetch, hasMore }) =>
      hasMore && (
        <button onClick={fetch} disabled={loading}>
          {loading ? "Loading..." : "Load previous"}
        </button>
      )
    }
    next={({ loading, fetch, hasMore }) =>
      hasMore && (
        <button onClick={fetch} disabled={loading}>
          {loading ? "Loading..." : "Load more"}
        </button>
      )
    }
  >
    {users.data.map((user) => (
      <div key={user.id}>{user.name}</div>
    ))}
  </InfiniteScroll>
);
```

```svelte tab=Svelte
<script>
    import { InfiniteScroll } from '@inertiajs/svelte'
    let { users } = $props()
</script>

<InfiniteScroll data="users" manual>
    {#snippet previous({ hasMore, fetch, loading })}
        {#if hasMore}
            <button onclick={fetch} disabled={loading}>
                {loading ? 'Loading...' : 'Load previous'}
            </button>
        {/if}
    {/snippet}

    {#each users.data as user (user.id)}
        <div>{user.name}</div>
    {/each}

    {#snippet next({ hasMore, fetch, loading })}
        {#if hasMore}
            <button onclick={fetch} disabled={loading}>
                {loading ? 'Loading...' : 'Load more'}
            </button>
        {/if}
    {/snippet}
</InfiniteScroll>
```

You can also configure the component to automatically switch to manual mode after a certain number of pages using the `manualAfter` prop.

```vue tab=Vue
<InfiniteScroll data="users" :manual-after="3">
    <!-- ... -->
</InfiniteScroll>
```

```jsx tab=React
<InfiniteScroll data="users" manualAfter={3}>
  {/* ... */}
</InfiniteScroll>
```

```svelte tab=Svelte
<InfiniteScroll data="users" manual-after={3}>
    <!-- ... -->
</InfiniteScroll>
```

<a name="infinite-scroll-slots"></a>

### Slots

The infinite scroll component provides several slots to customize the loading experience. These slots allow you to display custom loading indicators and create manual load controls. Each slot receives properties that provide loading state information and functions to trigger content loading.

<a name="infinite-scroll-default-slot"></a>

#### Default Slot

The main content area where you render your data items. This slot receives loading state information.

```vue tab=Vue
<InfiniteScroll
  data="users"
  #default="{ loading, loadingPrevious, loadingNext }"
>
    <!-- Your content with access to loading states -->
</InfiniteScroll>
```

```jsx tab=React
<InfiniteScroll data="users">
  {({ loading, loadingPrevious, loadingNext }) => (
    <div>{/* Your content with access to loading states */}</div>
  )}
</InfiniteScroll>
```

```svelte tab=Svelte
<InfiniteScroll data="users">
    {#snippet children({ loading, loadingPrevious, loadingNext })}
        <!-- Your content with access to loading states -->
    {/snippet}
</InfiniteScroll>
```

<a name="infinite-scroll-loading-slot"></a>

#### Loading Slot

The loading slot is used as a fallback when loading content and no custom `before` or `after` slots are provided. This creates a default loading indicator.

```vue tab=Vue
<template>
  <InfiniteScroll data="users">
    <!-- Your content -->

    <template #loading> Loading more users... </template>
  </InfiniteScroll>
</template>
```

```jsx tab=React
<InfiniteScroll data="users" loading={() => "Loading more users..."}>
  {/* Your content */}
</InfiniteScroll>
```

```svelte tab=Svelte
<InfiniteScroll data="users">
    <!-- Your content -->

    {#snippet loading()}
        Loading more users...
    {/snippet}
</InfiniteScroll>
```

<a name="infinite-scroll-previous-and-next-slots"></a>

#### Previous and Next Slots

The `previous` and `next` slots are rendered above and below the main content, typically used for manual load controls. These slots receive several properties including loading states, fetch functions, and mode indicators.

```vue
<template>
  <InfiniteScroll data="users" :manual-after="3">
    <template #previous="{ loading, fetch, hasMore, manualMode }">
      <button v-if="manualMode && hasMore" @click="fetch" :disabled="loading">
        {{ loading ? "Loading..." : "Load previous" }}
      </button>
    </template>

    <!-- Your content -->

    <template #next="{ loading, fetch, hasMore, manualMode }">
      <button v-if="manualMode && hasMore" @click="fetch" :disabled="loading">
        {{ loading ? "Loading..." : "Load more" }}
      </button>
    </template>
  </InfiniteScroll>
</template>
```

The `loading`, `previous`, and `next` slots receive the following properties:

| Property          | Description                                    |
| :---------------- | :--------------------------------------------- |
| `loading`         | Whether the slot is currently loading content  |
| `loadingPrevious` | Whether previous content is loading            |
| `loadingNext`     | Whether next content is loading                |
| `fetch`           | Function to trigger loading for the slot       |
| `hasMore`         | Whether more content is available for the slot |
| `hasPrevious`     | Whether more previous content is available     |
| `hasNext`         | Whether more next content is available         |
| `manualMode`      | Whether manual mode is active                  |
| `autoMode`        | Whether automatic loading is active            |

<a name="infinite-scroll-custom-element"></a>

### Custom Element

The `InfiniteScroll` component renders as a `<div>` element. You may customize this to use any HTML element using the `as` prop.

```vue tab=Vue
<template>
  <InfiniteScroll data="products" as="ul">
    <li v-for="product in products.data" :key="product.id">
      {{ product.name }}
    </li>
  </InfiniteScroll>
</template>
```

```jsx tab=React
<InfiniteScroll data="products" as="ul">
  {products.data.map((product) => (
    <li key={product.id}>{product.name}</li>
  ))}
</InfiniteScroll>
```

```svelte tab=Svelte
<InfiniteScroll data="products" as="ul">
    {#each products.data as product (product.id)}
        <li>{product.name}</li>
    {/each}
</InfiniteScroll>
```

<a name="infinite-scroll-element-targeting"></a>

### Element Targeting

The infinite scroll component automatically tracks content and assigns page numbers to elements for [URL synchronization](#infinite-scroll-url-synchronization). When your data items are not direct children of the component's root element, you need to specify which element contains the actual data items using the `itemsElement` prop.

```vue tab=Vue
<template>
  <InfiniteScroll data="users" items-element="#table-body">
    <table>
      <thead>
        <tr>
          <th>Name</th>
        </tr>
      </thead>
      <tbody id="table-body">
        <tr v-for="user in users.data" :key="user.id">
          <td>{{ user.name }}</td>
        </tr>
      </tbody>
    </table>
  </InfiniteScroll>
</template>
```

```jsx tab=React
<InfiniteScroll data="users" itemsElement="#table-body">
  <table>
    <thead>
      <tr>
        <th>Name</th>
      </tr>
    </thead>
    <tbody id="table-body">
      {users.data.map((user) => (
        <tr key="{user.id}">
          <td>{user.name}</td>
        </tr>
      ))}
    </tbody>
  </table>
</InfiniteScroll>
```

```svelte tab=Svelte
<InfiniteScroll data="users" items-element="#table-body">
    <table>
        <thead>
            <tr><th>Name</th></tr>
        </thead>
        <tbody id="table-body">
            {#each users.data as user (user.id)}
            <tr>
                <td>{user.name}</td>
            </tr>
            {/each}
        </tbody>
    </table>
</InfiniteScroll>
```

In this example, the component monitors the `#table-body` element and automatically tags each `<tr>` with a page number as new content loads. This enables proper URL updates based on which page's content is most visible in the viewport.

You can also specify custom trigger elements for loading more content using CSS selectors. This prevents the default trigger elements from being rendered and uses intersection observers on your custom elements instead.

```vue tab=Vue
<template>
  <InfiniteScroll
    data="users"
    items-element="#table-body"
    start-element="#table-header"
    end-element="#table-footer"
  >
    <table>
      <thead id="table-header">
        <tr>
          <th>Name</th>
        </tr>
      </thead>
      <tbody id="table-body">
        <tr v-for="user in users.data" :key="user.id">
          <td>{{ user.name }}</td>
        </tr>
      </tbody>
      <tfoot id="table-footer">
        <tr>
          <td>Footer</td>
        </tr>
      </tfoot>
    </table>
  </InfiniteScroll>
</template>
```

```jsx tab=React
<InfiniteScroll
  data="users"
  itemsElement="#table-body"
  startElement="#table-header"
  endElement="#table-footer"
>
  <table>
    <thead id="table-header">
      <tr>
        <th>Name</th>
      </tr>
    </thead>
    <tbody id="table-body">
      {users.data.map((user) => (
        <tr key={user.id}>
          <td>{user.name}</td>
        </tr>
      ))}
    </tbody>
    <tfoot id="table-footer">
      <tr>
        <td>Footer</td>
      </tr>
    </tfoot>
  </table>
</InfiniteScroll>
```

```svelte tab=Svelte
<InfiniteScroll
    data="users"
    items-element="#table-body"
    start-element="#table-header"
    end-element="#table-footer"
>
    <table>
        <thead id="table-header">
            <tr><th>Name</th></tr>
        </thead>
        <tbody id="table-body">
            {#each users.data as user (user.id)}
                <tr>
                    <td>{user.name}</td>
                </tr>
            {/each}
        </tbody>
        <tfoot id="table-footer">
            <tr><td>Footer</td></tr>
        </tfoot>
    </table>
</InfiniteScroll>
```

Alternatively, you can use template refs instead of CSS selectors. This avoids adding HTML attributes and provides direct element references.

```vue tab=Vue
<script setup>
import { ref } from "vue";
const tableHeader = ref();
const tableFooter = ref();
const tableBody = ref();
</script>

<template>
  <InfiniteScroll
    data="users"
    :items-element="() => tableBody"
    :start-element="() => tableHeader"
    :end-element="() => tableFooter"
  >
    <table>
      <thead ref="tableHeader">
        <tr>
          <th>Name</th>
        </tr>
      </thead>
      <tbody ref="tableBody">
        <tr v-for="user in users.data" :key="user.id">
          <td>{{ user.name }}</td>
        </tr>
      </tbody>
      <tfoot ref="tableFooter">
        <tr>
          <td>Footer</td>
        </tr>
      </tfoot>
    </table>
  </InfiniteScroll>
</template>
```

```jsx tab=React
import { useRef } from "react";

export default ({ users }) => {
  const tableHeader = useRef();
  const tableFooter = useRef();
  const tableBody = useRef();

  return (
    <InfiniteScroll
      data="users"
      itemsElement={tableBody}
      startElement={tableHeader}
      endElement={tableFooter}
    >
      <table>
        <thead ref={tableHeader}>
          <tr>
            <th>Name</th>
          </tr>
        </thead>
        <tbody ref={tableBody}>
          {users.data.map((user) => (
            <tr key={user.id}>
              <td>{user.name}</td>
            </tr>
          ))}
        </tbody>
        <tfoot ref={tableFooter}>
          <tr>
            <td>Footer</td>
          </tr>
        </tfoot>
      </table>
    </InfiniteScroll>
  );
};
```

```svelte tab=Svelte
<script>
    import { InfiniteScroll } from '@inertiajs/svelte'
    let { users } = $props()

    let tableHeader
    let tableFooter
    let tableBody
</script>

<InfiniteScroll
    data="users"
    items-element={() => tableBody}
    start-element={() => tableHeader}
    end-element={() => tableFooter}
>
    <table>
        <thead bind:this={tableHeader}>
            <tr><th>Name</th></tr>
        </thead>
        <tbody bind:this={tableBody}>
            {#each users.data as user (user.id)}
                <tr>
                    <td>{user.name}</td>
                </tr>
            {/each}
        </tbody>
        <tfoot bind:this={tableFooter}>
            <tr><td>Footer</td></tr>
        </tfoot>
    </table>
</InfiniteScroll>
```

<a name="infinite-scroll-scroll-containers"></a>

### Scroll Containers

The infinite scroll component works within any scrollable container, not just the main document. The component automatically adapts to use the custom scroll container for trigger detection and calculations instead of the main document scroll.

```vue tab=Vue
<template>
  <div style="height: 400px; overflow-y: auto;">
    <InfiniteScroll data="users">
      <div v-for="user in users.data" :key="user.id">
        {{ user.name }}
      </div>
    </InfiniteScroll>
  </div>
</template>
```

```jsx tab=React
<div style={{ height: "400px", overflowY: "auto" }}>
  <InfiniteScroll data="users">
    {users.data.map((user) => (
      <div key={user.id}>{user.name}</div>
    ))}
  </InfiniteScroll>
</div>
```

```svelte tab=Svelte
<div style="height: 400px; overflow-y: auto;">
    <InfiniteScroll data="users">
        {#each users.data as user (user.id)}
            <div>{user.name}</div>
        {/each}
    </InfiniteScroll>
</div>
```

<a name="infinite-scroll-multiple-scroll-containers"></a>

#### Multiple Scroll Containers

Sometimes you may need to render multiple infinite scroll components on a single page. However, if both components use the default `page` query parameter for [URL synchronization](#infinite-scroll-url-synchronization), they will conflict with each other. To resolve this, instruct each paginator to use a custom `pageName`.

```php
Route::get('/dashboard', function () {
    return Inertia::render('Dashboard', [
        'users' => Inertia::scroll(
            fn() => User::paginate(pageName: 'users')
        ),
        'orders' => Inertia::scroll(
            fn() => Order::paginate(pageName: 'orders')
        ),
    ]);
});
```

The `Inertia::scroll()` method automatically detects the `pageName` from each paginator, allowing both scroll containers to maintain independent pagination state. This results in URLs like `?users=2&orders=3` instead of conflicting `?page=` parameters.

For more information about pagination page names, see [Hypervel's documentation](/docs/{{version}}/pagination#multiple-paginator-instances-per-page).

<a name="infinite-scroll-programmatic-access"></a>

### Programmatic Access

When you need to trigger loading actions programmatically, you may use a template ref.

```vue tab=Vue
<script setup>
import { ref } from "vue";
const infiniteScrollRef = ref(null);

const fetchNext = () => {
  infiniteScrollRef.value?.fetchNext();
};
</script>

<template>
  <button @click="fetchNext">Load More</button>

  <InfiniteScroll ref="infiniteScrollRef" data="users" manual>
    <!-- Your content -->
  </InfiniteScroll>
</template>
```

```jsx tab=React
import { InfiniteScroll } from '@inertiajs/react'
import { useRef } from 'react'

export default ({ users }) => {
    const infiniteScrollRef = useRef(null)

    const fetchNext = () => {
        infiniteScrollRef.current?.fetchNext()
    }

    return (

            <button onClick={fetchNext}>Load More</button>

            <InfiniteScroll ref={infiniteScrollRef} data="users" manual>
                {users.data.map(user => (
                    <div key={user.id}>{user.name}</div>
                ))}
            </InfiniteScroll>

    )
}
```

```svelte tab=Svelte
<script>
    import { InfiniteScroll } from '@inertiajs/svelte'
    let { users } = $props()

    let infiniteScrollRef

    const fetchNext = () => {
        infiniteScrollRef?.fetchNext()
    }
</script>

<button onclick={fetchNext}>Load More</button>

<InfiniteScroll bind:this={infiniteScrollRef} data="users" manual>
    {#each users.data as user (user.id)}
        <div>{user.name}</div>
    {/each}
</InfiniteScroll>
```

The component exposes the following methods:

- `fetchNext()` - Manually fetch the next page
- `fetchPrevious()` - Manually fetch the previous page
- `hasNext()` - Whether there is a next page
- `hasPrevious()` - Whether there is a previous page

<a name="infinite-scroll-inertiascroll-method"></a>

### Inertia::scroll() Method

The `Inertia::scroll()` method provides server-side configuration for infinite scrolling. It automatically configures the proper merge behavior so that new data is appended or prepended to existing content instead of replacing it, and normalizes pagination metadata for the front-end component.

```php
// Works with all Hypervel pagination methods...
Inertia::scroll(User::paginate(20));
Inertia::scroll(User::simplePaginate(20));
Inertia::scroll(User::cursorPaginate(20));

// Works with API resources...
Inertia::scroll(UserResource::collection(User::paginate(20)));
```

If you don't use Hypervel's paginator or use a different transformation layer, you may use the additional arguments that `scroll()` accepts.

```php
// Customize the data wrapper key (defaults to 'data')...
Inertia::scroll($customPaginatedData, wrapper: 'items');

// Provide custom metadata resolution...
Inertia::scroll($data, metadata: $metadataProvider);
```

The metadata parameter accepts an instance of `ProvidesScrollMetadata` or a callback that returns such an instance. The callback receives the `$data` parameter. This is useful when integrating with third-party pagination libraries like Fractal. Implement the metadata methods in the following outline for your paginator.

```php
use Hypervel\Inertia\ProvidesScrollMetadata;
use League\Fractal\Resource\Collection;

class FractalScrollMetadata implements ProvidesScrollMetadata
{
    public function __construct(protected Collection $resource) {}
    public function getPageName(): string {}
    public function getPreviousPage(): int|string|null {}
    public function getNextPage(): int|string|null {}
    public function getCurrentPage(): int|string|null {}
}
```

You may then use this custom metadata provider in your scroll function.

```php
// Using an instance directly
Inertia::scroll($data, metadata: new FractalScrollMetadata($data));

// Using a callback
Inertia::scroll(
    fn() => $this->transformData($data),
    metadata: fn($data) => new FractalScrollMetadata($data)
);
```

To avoid repeating this setup in multiple controllers, you may define a macro.

```php
// In your AppServiceProvider's boot method
Inertia::macro('fractalScroll', function (Collection $data) {
    return Inertia::scroll(
        $data,
        metadata: fn(Collection $data) => new FractalScrollMetadata($data)
    );
});

// Then use it in your controllers
return Inertia::render('Users/Index', [
    'users' => Inertia::fractalScroll($fractalCollection)
]);
```

<a name="remembering-state"></a>

## Remembering State

When navigating browser history, Inertia restores pages using prop data cached in history state. However, Inertia does not restore local page component state since this is beyond its reach. This can lead to outdated pages in your browser history.

For example, if a user partially completes a form, then navigates away, and then returns back, the form will be reset and their work will be lost.

To mitigate this issue, you can tell Inertia which local component state to save in the browser history.

<a name="remembering-state-saving-local-state"></a>

### Saving Local State

To save local component state to the history state, use the `useRemember` feature to tell Inertia which data it should remember.

```js tab=Vue
import { useRemember } from "@inertiajs/vue3";

const form = useRemember({
  first_name: null,
  last_name: null,
});
```

```jsx tab=React
import { useRemember } from "@inertiajs/react";

export default function Profile() {
  const [formState, setFormState] = useRemember({
    first_name: null,
    last_name: null,
    // ...
  });

  // ...
}
```

```js tab=Svelte
import { useRemember } from "@inertiajs/svelte";

const form = useRemember({
  first_name: null,
  last_name: null,
});

// ...
```

Now, whenever your local `form` state changes, Inertia will automatically save this data to the history state and will also restore it on history navigation.

<a name="remembering-state-multiple-components"></a>

### Multiple Components

If your page contains multiple components that use the remember functionality provided by Inertia, you need to provide a unique key for each component so that Inertia knows which data to restore to each component.

```js tab=Vue
import { useRemember } from "@inertiajs/vue3";

const form = useRemember(
  {
    first_name: null,
    last_name: null,
  },
  "Users/Create",
);
```

```jsx tab=React
import { useRemember } from "@inertiajs/react";

export default function Profile() {
  const [formState, setFormState] = useRemember(
    {
      first_name: null,
      last_name: null,
    },
    "Users/Create",
  );
}
```

```js tab=Svelte
import { page, useRemember } from "@inertiajs/svelte";

const form = useRemember(
  {
    first_name: null,
    last_name: null,
  },
  "Users/Create",
);
```

If you have multiple instances of the same component on the page using the remember functionality, be sure to also include a unique key for each component instance, such as a model identifier.

```js tab=Vue
import { useRemember } from "@inertiajs/vue3";

const props = defineProps({ user: Object });

const form = useRemember(
  {
    first_name: null,
    last_name: null,
  },
  `Users/Edit:${props.user.id}`,
);
```

```jsx tab=React
import { useRemember } from "@inertiajs/react";

export default function Profile() {
  const [formState, setFormState] = useRemember(
    {
      first_name: props.user.first_name,
      last_name: props.user.last_name,
    },
    `Users/Edit:${this.user.id}`,
  );
}
```

```js tab=Svelte
import { page, useRemember } from "@inertiajs/svelte";

const form = useRemember(
  {
    first_name: page.props.user.first_name,
    last_name: page.props.user.last_name,
  },
  `Users/Edit:${page.props.user.id}`,
);
```

<a name="remembering-state-form-helper"></a>

### Form Helper

If you're using the [Inertia form helper](#forms-form-helper), you can pass a unique form key as the first argument when instantiating your form. This will cause the form data and errors to automatically be remembered.

```js tab=Vue
import { useForm } from "@inertiajs/vue3";

const form = useForm("CreateUser", data);
const form = useForm(`EditUser:${props.user.id}`, data);
```

```js tab=React
import { useForm } from "@inertiajs/react";

const form = useForm("CreateUser", data);
const form = useForm(`EditUser:${user.id}`, data);
```

```js tab=Svelte
import { useForm } from "@inertiajs/svelte";

const form = useForm("CreateUser", data);
const form = useForm(`EditUser:${user.id}`, data);
```

You may [exclude specific fields](#forms-excluding-fields) from being remembered using the `dontRemember()` method. This is useful for sensitive fields like passwords that should not be stored in history state.

<a name="remembering-state-manually-saving-state"></a>

### Manually Saving State

The `useRemember` hook watches for data changes and automatically saves those changes to the history state. Then, Inertia will restore the data on page load.

However, it's also possible to manage this manually using the underlying `remember()` and `restore()` methods exposed by Inertia.

```js tab=Vue
import { router } from "@inertiajs/vue3";

// Save local component state to history state
router.remember(data, "my-key");

// Restore local component state from history state
let data = router.restore("my-key");
```

```js tab=React
import { router } from "@inertiajs/react";

// Save local component state to history state
router.remember(data, "my-key");

// Restore local component state from history state
let data = router.restore("my-key");
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

// Save local component state to history state
router.remember(data, "my-key");

// Restore local component state from history state
let data = router.restore("my-key");
```

> [!WARNING]
> Some browsers limit the number of `history.replaceState()` calls allowed
> within a short time period. Inertia catches this error and logs it to the
> console, but the state update will be lost. Avoid calling `router.remember()`
> too frequently, and consider debouncing or batching state updates in
> high-frequency scenarios.

<a name="authentication"></a>

## Authentication

One benefit of using Inertia is that you don't need a special authentication system, such as OAuth, to connect to your API. Since your data is provided through your controllers and served from the same domain as your JavaScript components, you don't need to set up CORS.

Instead, you can use Hypervel's [session-based authentication](/docs/{{version}}/authentication).

Hypervel's [React starter kit](/docs/{{version}}/starter-kits) provides out-of-the-box scaffolding for new Inertia applications, including authentication.

<a name="authorization"></a>

## Authorization

When using Inertia, authorization is best handled server-side in your application's authorization policies. You may still need to perform authorization checks in your Inertia page components, where your framework's server-side helpers are unavailable.

The simplest approach is to pass the results of your authorization checks directly to your page components as props.

```php
class UsersController extends Controller
{
    public function index()
    {
        return Inertia::render('Users/Index', [
            'can' => [
                'create_user' => Auth::user()->can('create', User::class),
            ],
            'users' => User::all()->map(function ($user) {
                return [
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'can' => [
                        'edit_user' => Auth::user()->can('edit', $user),
                    ]
                ];
            }),
        ]);
    }
}
```

<a name="csrf-protection"></a>

## CSRF Protection

<a name="csrf-protection-making-requests"></a>

### Making Requests

Hypervel's [CSRF middleware](/docs/{{version}}/csrf) includes an `XSRF-TOKEN` cookie on each response. Inertia's HTTP client automatically checks for the existence of this cookie and, when present, includes the token in an `X-XSRF-TOKEN` header for every request it makes. No additional configuration is required when using the `web` middleware group.

Be sure to omit the `csrf-token` meta tag from your project, as this will prevent the CSRF token from refreshing properly.

You may customize the cookie and header names via the `http` option in `createInertiaApp`.

```js
createInertiaApp({
  http: {
    xsrfCookieName: 'MY-XSRF-TOKEN',
    xsrfHeaderName: 'X-MY-XSRF-TOKEN',
  },
  // ...
})
```

<a name="csrf-protection-handling-mismatches"></a>

### Handling Mismatches

When a CSRF token mismatch occurs, Hypervel throws a `TokenMismatchException`, which results in a `419` error page. Since that isn't a valid Inertia response, the error is shown in a modal.

Obviously, this isn't a great user experience. A better way to handle these errors is to return a redirect back to the previous page, along with a flash message that the page expired. This will result in a valid Inertia response with [flash data](#flash-data) which you can then display to the user.

You may modify your application's exception handler to automatically redirect the user back to the page they were previously on while flashing a message to the session. To accomplish this, you may use the `respond` exception method in your application's `bootstrap/app.php` file.

```php
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->respond(function (Response $response) {
        if ($response->getStatusCode() === 419) {
            return Inertia::flash([
                'message' => 'The page expired, please try again.',
            ])->back();
        }

        return $response;
    });
});
```

The result is a much better experience: instead of seeing the error modal, users see a message that the page has expired and are asked to try again.

<a name="history-encryption"></a>

## History Encryption

Imagine a scenario where your user is authenticated, browses privileged information on your site, then logs out. If they press the back button, they can still see the privileged information that is stored in the window's history state. This is a security risk. To prevent this, Inertia.js provides a history encryption feature.

<a name="history-encryption-how-it-works"></a>

### How It Works

When you instruct Inertia to encrypt your app's history, it uses the browser's built-in [`Crypto` API](https://developer.mozilla.org/en-US/docs/Web/API/Crypto) to encrypt the current page's data before pushing it to the history state. We store the corresponding key in the browser's session storage. When the user navigates back to a page, we decrypt the data using the key stored in the session storage.

Once you instruct Inertia to clear your history state, it clears the existing key from session storage and generates a new one. If it attempts to decrypt the history state with the new key, it will fail and Inertia will make a fresh request back to your server for the page data.

History encryption relies on `window.crypto.subtle` which is only available in secure environments (sites with SSL enabled).

<a name="history-encryption-opting-in"></a>

### Opting in

History encryption is an opt-in feature. There are several methods for enabling it:

<a name="history-encryption-global-encryption"></a>

#### Global Encryption

If you'd like to enable history encryption globally, set the `inertia.history.encrypt` config value to `true`.

You are able to opt out of encryption on specific pages by calling the `Inertia::encryptHistory()` method before returning the response.

```php
Inertia::encryptHistory(false);
```

<a name="history-encryption-per-request-encryption"></a>

#### Per-request Encryption

To encrypt the history of an individual request, simply call the `Inertia::encryptHistory()` method before returning the response.

```php
Inertia::encryptHistory();
```

<a name="history-encryption-encrypt-middleware"></a>

#### Encrypt Middleware

To encrypt a group of routes, you may use Inertia's included `EncryptHistory` middleware.

```php
use Hypervel\Inertia\Middleware\EncryptHistory;
use Hypervel\Support\Facades\Route;

Route::middleware([EncryptHistory::class])->get('/', function() {
    //
});

Route::middleware(['inertia.encrypt'])->get('/', function() {
    //
});
```

<a name="history-encryption-clearing-history"></a>

### Clearing History

To clear the history state, you can call the `Inertia::clearHistory()` method before returning the response.

```php
Inertia::clearHistory();
```

Once the response has rendered on the client, the encryption key will be rotated, rendering the previous history state unreadable.

You can also clear history on the client side by calling `router.clearHistory()`.

<a name="asset-versioning"></a>

## Asset Versioning

One common challenge when building single-page apps is refreshing site assets when they've been changed. Thankfully, Inertia makes this easy by optionally tracking the current version of your site assets. When an asset changes, Inertia will automatically make a full-page visit instead of an XHR visit on the next request.

<a name="asset-versioning-configuration"></a>

### Configuration

To enable automatic asset refreshing, you need to tell Inertia the current version of your assets. This can be any arbitrary string (letters, numbers, or a file hash), as long as it changes when your assets have been updated.

Typically, your application's asset version can be specified within the `version` method of the Inertia `HandleInertiaRequests` middleware.

```php
class HandleInertiaRequests extends Middleware
{
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }
}
```

Alternatively, the asset version can be provided manually using the `Inertia::version()` method.

```php
use Hypervel\Inertia\Inertia;

Inertia::version($version);
Inertia::version(fn () => $version); // Lazily...
```

<a name="asset-versioning-cache-busting"></a>

### Cache Busting

Asset refreshing in Inertia works on the assumption that a full-page visit will trigger your assets to reload. However, Inertia doesn't actually do anything to force this. Typically this is done with some form of cache busting. For example, appending a version query parameter to the end of your asset URLs.

With Hypervel's [Vite integration](/docs/{{version}}/vite), asset versioning is done automatically. The default middleware caches the asset version for the worker lifetime, so [reload your workers](/docs/{{version}}/deployment#reloading-services) after deploying new assets.

<a name="asset-versioning-background-requests"></a>

### Background Requests

**v3.6.0+**

A version change detected on a background request, such as polling or a `router.reload()`, does not force a full-page navigation. Reloading a request the user never initiated could wipe out unsaved state, so the current page stays untouched and the next user-initiated visit picks up the new assets. You may hook into the [`location` event](#events-location) to detect an asset version change and show a notification instead of reloading.

<a name="asset-versioning-manual-refreshing"></a>

### Manual Refreshing

If you want to take control of asset refreshing, you can return a fixed value from the `version` method in the `HandleInertiaRequests` middleware. This disables Inertia's automatic asset versioning.

For example, if you want to notify users when a new version of your front end is available, you can still expose the actual asset version to the front end by including it as [shared data](#shared-data).

```php
class HandleInertiaRequests extends Middleware
{
    public function version(Request $request): ?string
    {
        return null;
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'version' => parent::version($request),
        ]);
    }
}
```

On the front end, you can watch the `version` property and show a notification when a new version is detected.

<a name="big-integers"></a>

## Big Integers

**v3.8.0+**

Sometimes you may wish to pass integers that exceed JavaScript's safe integer range, such as IDs from a 64-bit database column. JavaScript rounds those values while parsing JSON, so `900719925474099988` reaches your components as `900719925474100000`. Big integer support keeps them exact by delivering them as native `BigInt` values, following the JSON convention [documented on MDN](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/BigInt#use_within_json).

<a name="big-integers-enabling-big-integers"></a>

### Enabling Big Integers

Big integer support is disabled by default and is enabled entirely on the server, so there is nothing to configure on the client. You may enable it for every response in your `config/inertia.php` file, or through the `INERTIA_PRESERVE_BIG_INTEGERS` environment variable.

```php
'preserve_big_integers' => true,
```

Alternatively, you may enable it for a single response using the `preserveBigIntegers` method. This is useful when only a few pages of your application deal with large integers.

```php
return Inertia::render('Orders/Show', [
    'order' => $order,
])->preserveBigIntegers();
```

Passing `false` opts a single response out, which is useful once the feature is enabled in your configuration file.

```php
return Inertia::render('Reports/Index', $props)->preserveBigIntegers(false);
```

<a name="big-integers-how-values-are-transported"></a>

### How Values Are Transported

Integers outside the safe range are wrapped in a marker object before the response is encoded. The client turns that marker back into a `BigInt`, so your component receives the exact value.

```php
return Inertia::render('Users/Show', [
    'id' => 900719925474099988,
]);

// The response carries {"$bigint": "900719925474099988"}
```

```vue tab=Vue
<script setup>
defineProps({ id: BigInt });
</script>

<template>
  <p>User {{ id }}</p>
  <!-- User 900719925474099988 -->
</template>
```

```jsx tab=React
export default function Show({ id }) {
  return <p>User {String(id)}</p>;
  // User 900719925474099988
}
```

```svelte tab=Svelte
<script>
  let { id } = $props();
</script>

<p>User {id}</p>
<!-- User 900719925474099988 -->
```

Integers within the safe range are left as regular numbers, so only the values that need it become a `BigInt`. The same prop may therefore arrive as a number or a `BigInt`, depending on its value. [Flash data](#flash-data) receives the same treatment as props.

Markers are only revived on pages that enable big integer support, so applications that already send their own `{"$bigint": "..."}` objects continue to receive them as plain objects everywhere else. When big integer support is enabled, avoid using objects with a string `$bigint` property in your own props and flash data, since the client converts the entire object to a `BigInt`.

<a name="big-integers-objects-and-classes"></a>

### Objects and Classes

Arrays, plain objects, Eloquent models, collections, API resources, and values implementing `JsonSerializable` are all inspected for large integers, as are the public properties of your own classes. Built-in PHP classes such as `DateTime`, along with classes extending them, are passed through untouched, since they serialize through their own handlers.

<a name="big-integers-submitting-big-integers"></a>

### Submitting Big Integers

A `BigInt` submitted through the router, a form, or [Precognition](#forms-precognition) is sent as its digits, the same way form data and query strings send numbers. Your controller receives a numeric string, which validation rules such as `integer` and the request's `integer` method handle as usual.

```js
router.post("/orders", { id: props.order.id });
```

```php
public function store(Request $request)
{
    $request->validate(['id' => ['required', 'integer']]);

    $request->integer('id'); // 900719925474099988
}
```

The [`useHttp`](#http-requests) hook does not handle `BigInt` values, since it is commonly used to call third-party APIs. Convert those values to a string before sending them.

<a name="big-integers-testing"></a>

### Testing

Inertia's [testing helpers](#testing-endpoint-tests) turn markers back into integers, so you may assert against the same values you passed to the response.

```php
$response->assertInertia(fn (Assert $page) => $page
    ->where('order.id', 900719925474099988)
);

$response->inertiaProps('order.id'); // 900719925474099988
```

<a name="code-splitting"></a>

## Code Splitting

By default, Inertia lazy-loads page components, splitting each page into its own bundle that is loaded on demand. This reduces the initial JavaScript bundle size but requires additional requests when visiting new pages.

You may disable lazy loading to eagerly bundle all pages into a single file. Eager loading eliminates per-page requests but increases the initial bundle size.

<a name="code-splitting-vite-plugin"></a>

### Vite Plugin

The `lazy` option in the `pages` shorthand controls how page components are loaded. It defaults to `true`.

```js
createInertiaApp({
    pages: {
        lazy: false, // Bundle all pages into a single file
    },
    // ...
})
```

<a name="code-splitting-manual-vite"></a>

### Manual Vite

You may configure code splitting manually using Vite's `import.meta.glob()` function when not using the Inertia Vite plugin. Pass `{ eager: true }` to bundle all pages, or omit it to lazy-load them.

```js tab=Vue
resolve: name => {
    const pages = import.meta.glob('./Pages/**/*.vue') // [tl! remove]
    return pages[`./Pages/${name}.vue`]() // [tl! remove]
    const pages = import.meta.glob('./Pages/**/*.vue', { eager: true }) // [tl! add]
    return pages[`./Pages/${name}.vue`] // [tl! add]
},
```

```js tab=React
resolve: name => {
    const pages = import.meta.glob('./Pages/**/*.jsx') // [tl! remove]
    return pages[`./Pages/${name}.jsx`]() // [tl! remove]
    const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true }) // [tl! add]
    return pages[`./Pages/${name}.jsx`] // [tl! add]
},
```

```js tab=Svelte
resolve: name => {
    const pages = import.meta.glob('./Pages/**/*.svelte') // [tl! remove]
    return pages[`./Pages/${name}.svelte`]() // [tl! remove]
    const pages = import.meta.glob('./Pages/**/*.svelte', { eager: true }) // [tl! add]
    return pages[`./Pages/${name}.svelte`] // [tl! add]
},
```

<a name="devtools"></a>

## DevTools

Inertia DevTools is a browser extension that records every Inertia visit and displays it in a dedicated DevTools panel, showing exactly what each visit sent and received: which props were returned, whether they were deferred or merged, what the request headers were, and which route and controller handled it.

DevTools is a local development tool, designed to run alongside your local development server. The recorder is disabled outside your local environment by default, so DevTools stays out of production unless you explicitly allow it.

<a name="devtools-installation"></a>

### Installation

<a name="devtools-bump-the-client-side-and-server-side-libraries"></a>

#### Bump the client-side and server-side libraries

There is no separate package to install in your app. DevTools ships inside Hypervel's Inertia adapter, so you only need the client-side adapter at `^3.6`.

<a name="devtools-install-the-browser-extension"></a>

#### Install the browser extension

Install Inertia DevTools from the [Chrome Web Store](https://chromewebstore.google.com/detail/inertiajs-devtools/cbaffpghpcbmgbnlpamegieokkpdlnih) or [Firefox Add-ons](https://addons.mozilla.org/en-US/firefox/addon/inertia-js-devtools/), or download the latest release from [GitHub](https://github.com/inertiajs/inertia-devtools/releases) and load it unpacked.

<a name="devtools-enable-or-disable-optional"></a>

#### Enable or disable (optional)

DevTools is enabled automatically in development, so most applications need no configuration to get started. To turn it on or off explicitly, or to access it from another environment, see [Enabling DevTools](#devtools-enabling-devtools).

<a name="devtools-open-the-panel"></a>

#### Open the panel

Open your browser's DevTools on your Inertia app and select the "Inertia" panel. Navigate around your application and each visit will appear in the timeline.

> [!NOTE]
> Run the Vite development server for full functionality. In dev mode Inertia exposes the hooks the panel relies on for client-side detail such as visit options and request grouping. Without it, requests are still recorded, but some client-side features are unavailable.

<a name="devtools-the-timeline"></a>

### The Timeline

Every Inertia request is listed in the timeline on the left, with a live indicator while it is in flight. You may filter by method, request type, or status, or search by URL and component. Request types include full visits, [partial reloads](#partial-reloads), [deferred prop loads](#deferred-props), [polls](#polling), [prefetches](#prefetching), and [Precognition validation](#forms-precognition). Related requests are grouped rather than listed separately, so a page visit and its follow-up requests appear together. The grouping is determined on the client because only the browser can distinguish a poll from a full navigation or know whether a prefetch was served from cache.

<a name="devtools-inspecting-a-request"></a>

### Inspecting a Request

Selecting a request opens its detail, split into four tabs.

The **Props** tab lists every prop as a tree, with a badge indicating whether it is deferred, optional, merged, or shared with `share()`, and a `file:line` link into your editor. Values expand inline. Alongside it, the **HTTP** tab shows the request and response headers and bodies with sensitive values redacted, and the **Route** tab shows the matched route name, URI, and controller action.

The **Page** tab shows the full page state after Inertia has handled the response. A partial reload might send only one prop over the wire, but Inertia merges it into the existing page, so this tab shows the complete result, not only what arrived in the response.

<a name="devtools-client-side-events"></a>

### Client-Side Events

Some interactions never reach the server, so the Network tab shows nothing for them. The extension records them as their own timeline entries: [Client Side Visits](#manual-visits-client-side-visits) and requests served from the [prefetch](#prefetching) cache.

<a name="devtools-editor-links"></a>

### Editor Links

File references throughout the panel link directly to your editor. You may use the editor picker in the toolbar to choose your editor. VS Code, PhpStorm, Cursor, and Zed register their URL schemes on install and work out of the box. Sublime Text ships no URL-scheme handler, so its links do nothing until you install one such as [SublimeUrl](https://github.com/inopinatus/sublime_url).

<a name="devtools-how-it-works"></a>

### How It Works

DevTools consists of two pieces that split the work across each request. The Hypervel adapter records the server side as the response is prepared, while the browser extension captures the client side, and the two are paired into a single timeline entry. Entries are stored locally over the same origin, so nothing is sent to a remote service and no account or cloud sync is required.

<a name="devtools-enabling-devtools"></a>

### Enabling DevTools

DevTools has two independent switches, one server-side and one client-side, both of which default to on in development and off in production.

The server recorder is controlled by the `INERTIA_DEVTOOLS_ENABLED` environment variable, enabled automatically in your local environment. You may set it explicitly to override that default.

```env
INERTIA_DEVTOOLS_ENABLED=false
```

The client hooks are controlled by the `dev` option in `createInertiaApp()`, which defaults to Vite's development mode. These hooks expose the visit options and request grouping described above, so turning them off leaves the timeline recording requests without that client-side detail.

```js
createInertiaApp({
    // ...
    dev: import.meta.env.DEV,
})
```

To disable the panel itself, toggle the extension off from `chrome://extensions`.

<a name="devtools-configuration"></a>

### Configuration

The recorder is configured under the `devtools` key in your `config/inertia.php` file, with environment variables for the most common options.

<a name="devtools-redaction"></a>

#### Redaction

Sensitive prop keys and headers are redacted before an entry is stored. You may customize the redacted keys and headers under `devtools.redact` in your configuration file. Redaction applies to props, request data, JSON bodies, URL query strings, and configured headers. Other request and response bodies, such as HTML or plain text, are stored as they were sent, so exclude any paths whose responses contain secrets.

Certain paths are excluded from recording entirely, including the DevTools endpoints themselves and common tooling routes such as Telescope and Horizon. You may adjust the `devtools.except` array to record or ignore additional paths.

<a name="devtools-storage"></a>

#### Storage

Entries are written to `storage/inertia-devtools` and pruned automatically. You may adjust the retention, limit, and storage path under `devtools.storage` in your configuration file.

<a name="devtools-enabling-outside-local"></a>

#### Enabling Outside Local

The recorder is restricted to your local environment by default. To allow access beyond it, define a gate and reference it with the `INERTIA_DEVTOOLS_GATE` environment variable.

```php
// app/Providers/AppServiceProvider.php
use Hypervel\Support\Facades\Gate;

Gate::define('viewInertiaDevTools', function ($user) {
    return $user->isAdmin();
});
```

```env
INERTIA_DEVTOOLS_ENABLED=true
INERTIA_DEVTOOLS_GATE=viewInertiaDevTools
```

Outside your local environment, the DevTools endpoints authorize each request against the gate, refusing access when no gate is configured. Your local environment is always allowed, so a gate can never lock you out of DevTools while you work locally.

The endpoints run the `web` middleware group before they authorize, so the session has resolved the authenticated user by the time the gate runs. You may adjust that stack under `devtools.middleware` in your configuration file if your application authenticates its users another way.

The gate only controls who may view entries. Requests are recorded no matter who makes them, so only enable the recorder outside your local environment when untrusted visitors can't reach the application.

<a name="error-handling"></a>

## Error Handling

<a name="error-handling-development"></a>

### Development

One of the advantages to working with a robust server-side framework is the built-in exception handling you get for free. For example, Hypervel displays a nicely formatted stack trace in local development.

The challenge is, if you're making an XHR request (which Inertia does) and you hit a server-side error, you're typically left digging through the network tab in your browser's devtools to diagnose the problem.

Inertia solves this issue by showing all non-Inertia responses in a modal. This means you get the same beautiful error-reporting you're accustomed to, even though you've made that request over XHR.

For applications using a [Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP) that restricts inline styles, you may pass a `nonce` to `createInertiaApp()` to permit the inline styles the error modal injects. See [Content Security Policy](#client-side-setup-content-security-policy) for details.

<a name="error-handling-production"></a>

### Production

In production you will want to return a proper Inertia error response instead of relying on the modal-driven error reporting that is present during development. To accomplish this, you may use the `Inertia::handleExceptionsUsing()` method in your application's service provider.

```php
// app/Providers/AppServiceProvider.php
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\ExceptionResponse;

public function boot(): void
{
    Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
        if (in_array($response->statusCode(), [403, 404, 500, 503])) {
            return $response->render('ErrorPage', [
                'status' => $response->statusCode(),
            ])->withSharedData();
        }
    });
}
```

Since exceptions like 404s occur outside of the Inertia middleware (the request never reaches your routes), the error response won't have access to shared data or the root view by default. Calling `withSharedData()` explicitly resolves the Inertia middleware and includes your shared props in the error page.

The `ExceptionResponse` instance provides the `exception`, `request`, and `response` as public readonly properties, along with the following methods:

- `render($component, $props)` - Render an Inertia page component with the given props
- `withSharedData()` - Include shared data from the Inertia middleware
- `usingMiddleware($class)` - Specify which Inertia middleware to use for shared data and root view resolution
- `rootView($view)` - Set a custom root view for the error response
- `statusCode()` - Get the HTTP status code of the original response

The Inertia middleware is auto-resolved from the matched route or the kernel's middleware groups, so `withSharedData()` typically works without specifying a middleware class. Returning `null` from the callback falls through to Hypervel's default exception rendering.

<a name="error-handling-error-page-example"></a>

#### Error Page Example

You'll need to create the error page components referenced above. Here's an example you may use as a starting point.

```vue tab=Vue
<script setup>
import { computed } from "vue";

const props = defineProps({ status: Number });

const title = computed(() => {
  return {
    503: "503: Service Unavailable",
    500: "500: Server Error",
    404: "404: Page Not Found",
    403: "403: Forbidden",
  }[props.status];
});

const description = computed(() => {
  return {
    503: "Sorry, we are doing some maintenance. Please check back soon.",
    500: "Whoops, something went wrong on our servers.",
    404: "Sorry, the page you are looking for could not be found.",
    403: "Sorry, you are forbidden from accessing this page.",
  }[props.status];
});
</script>

<template>
  <div>
    <h1>{{ title }}</h1>
    <div>{{ description }}</div>
  </div>
</template>
```

```jsx tab=React
export default function ErrorPage({ status }) {
  const title = {
    503: "503: Service Unavailable",
    500: "500: Server Error",
    404: "404: Page Not Found",
    403: "403: Forbidden",
  }[status];

  const description = {
    503: "Sorry, we are doing some maintenance. Please check back soon.",
    500: "Whoops, something went wrong on our servers.",
    404: "Sorry, the page you are looking for could not be found.",
    403: "Sorry, you are forbidden from accessing this page.",
  }[status];

  return (
    <div>
      <h1>{title}</h1>
      <div>{description}</div>
    </div>
  );
}
```

```svelte tab=Svelte
<script>
    let { status } = $props()

    const title = {
        503: '503: Service Unavailable',
        500: '500: Server Error',
        404: '404: Page Not Found',
        403: '403: Forbidden',
    }

    const description = {
        503: 'Sorry, we are doing some maintenance. Please check back soon.',
        500: 'Whoops, something went wrong on our servers.',
        404: 'Sorry, the page you are looking for could not be found.',
        403: 'Sorry, you are forbidden from accessing this page.',
    }
</script>

<div>
    <h1>{title[status]}</h1>
    <div>{description[status]}</div>
</div>
```

<a name="error-handling-manual-exception-handling"></a>

#### Manual Exception Handling

Under the hood, `handleExceptionsUsing()` registers a response callback on Hypervel's exception handler. You may register a callback manually in your application's `bootstrap/app.php` file if you prefer.

```php
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Hypervel\Inertia\Inertia;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
        if (! app()->environment(['local', 'testing']) && in_array($response->getStatusCode(), [500, 503, 404, 403])) {
            return Inertia::render('ErrorPage', ['status' => $response->getStatusCode()])
                ->toResponse($request)
                ->setStatusCode($response->getStatusCode());
        }

        return $response;
    });
})
```

<a name="events"></a>

## Events

Inertia provides an event system that allows you to "hook into" the various lifecycle events of the library.

<a name="events-registering-listeners"></a>

### Registering Listeners

To register an event listener, use the `router.on()` method.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

Under the hood, Inertia uses native browser events, so you can also interact with Inertia events using the typical event methods you may already be familiar with. Just be sure to prepend `inertia:` to the event name.

```js tab=Vue
import { router } from "@inertiajs/vue3";

document.addEventListener("inertia:start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

document.addEventListener("inertia:start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

document.addEventListener("inertia:start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

<a name="events-removing-listeners"></a>

### Removing Listeners

When you register an event listener, Inertia automatically returns a callback that can be invoked to remove the event listener.

```js tab=Vue
import { router } from "@inertiajs/vue3";

let removeStartEventListener = router.on("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});

// Remove the listener...
removeStartEventListener();
```

```jsx tab=React
import { router } from "@inertiajs/react";

let removeStartEventListener = router.on("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});

// Remove the listener...
removeStartEventListener();
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

let removeStartEventListener = router.on("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});

// Remove the listener...
removeStartEventListener();
```

Combined with hooks, you can automatically remove the event listener when components unmount.

```vue tab=Vue
<script setup>
import { router } from '@inertiajs/vue3'
import { onUnmounted } from 'vue'

onUnmounted(
  router.on('start', (event) => {
    console.log(`Starting a visit to ${event.detail.visit.url}`)
  })
)
</script>
```

```jsx tab=React
import { useEffect } from "react";
import { router } from "@inertiajs/react";

useEffect(() => {
  return router.on("start", (event) => {
    console.log(`Starting a visit to ${event.detail.visit.url}`);
  });
}, []);
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

$effect(() => {
  return router.on("start", (event) => {
    console.log(`Starting a visit to ${event.detail.visit.url}`);
  });
});
```

Alternatively, if you're using native browser events, you can remove the event listener using `removeEventListener()`.

```js tab=Vue
import { router } from "@inertiajs/vue3";

let startEventListener = (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
};

document.addEventListener("inertia:start", startEventListener);

// Remove the listener...
document.removeEventListener("inertia:start", startEventListener);
```

```jsx tab=React
import { router } from "@inertiajs/react";

let startEventListener = (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
};

document.addEventListener("inertia:start", startEventListener);

// Remove the listener...
document.removeEventListener("inertia:start", startEventListener);
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

let startEventListener = (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
};

document.addEventListener("inertia:start", startEventListener);

// Remove the listener...
document.removeEventListener("inertia:start", startEventListener);
```

<a name="events-one-time-listeners"></a>

### One-time Listeners

You may register a listener that fires only once using the `router.once()` method. The listener is automatically removed after the first invocation.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.once("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.once("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.once("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

Like `router.on()`, this method returns a callback you may invoke to remove the listener before it fires.

```js tab=Vue
import { router } from "@inertiajs/vue3";

let removeStartEventListener = router.once("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});

// Remove the listener before it fires...
removeStartEventListener();
```

```jsx tab=React
import { router } from "@inertiajs/react";

let removeStartEventListener = router.once("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});

// Remove the listener before it fires...
removeStartEventListener();
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

let removeStartEventListener = router.once("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});

// Remove the listener before it fires...
removeStartEventListener();
```

<a name="events-canceling-events"></a>

### Canceling Events

Some events, such as `before`, `networkError`, `httpException`, and `location`, support cancellation, allowing you to prevent Inertia's default behavior. Just like native events, the event will be canceled if only one event listener calls `event.preventDefault()`.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("before", (event) => {
  if (!confirm("Are you sure you want to navigate away?")) {
    event.preventDefault();
  }
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("before", (event) => {
  if (!confirm("Are you sure you want to navigate away?")) {
    event.preventDefault();
  }
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("before", (event) => {
  if (!confirm("Are you sure you want to navigate away?")) {
    event.preventDefault();
  }
});
```

For convenience, if you register your event listener using `router.on()`, you can cancel the event by returning `false` from the listener.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("before", (event) => {
  return confirm("Are you sure you want to navigate away?");
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("before", (event) => {
  return confirm("Are you sure you want to navigate away?");
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("before", (event) => {
  return confirm("Are you sure you want to navigate away?");
});
```

Note that browsers do not allow canceling the native `popstate` event, so preventing forward and back history visits with Inertia is not possible.

<a name="events-before"></a>

### Before

The `before` event fires when a request is about to be made to the server. This is useful for intercepting visits.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("before", (event) => {
  console.log(`About to make a visit to ${event.detail.visit.url}`);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("before", (event) => {
  console.log(`About to make a visit to ${event.detail.visit.url}`);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("before", (event) => {
  console.log(`About to make a visit to ${event.detail.visit.url}`);
});
```

The primary purpose of this event is to allow you to prevent a visit from happening.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("before", (event) => {
  return confirm("Are you sure you want to navigate away?");
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("before", (event) => {
  return confirm("Are you sure you want to navigate away?");
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("before", (event) => {
  return confirm("Are you sure you want to navigate away?");
});
```

<a name="events-start"></a>

### Start

The `start` event fires when a request to the server has started. This is useful for displaying loading indicators.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("start", (event) => {
  console.log(`Starting a visit to ${event.detail.visit.url}`);
});
```

The `start` event is not cancelable.

<a name="events-progress"></a>

### Progress

The `progress` event fires as progress increments during file uploads.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("progress", (event) => {
  this.form.progress = event.detail.progress.percentage;
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("progress", (event) => {
  this.form.progress = event.detail.progress.percentage;
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("progress", (event) => {
  this.form.progress = event.detail.progress.percentage;
});
```

The `progress` event is not cancelable.

<a name="events-success"></a>

### Success

The `success` event fires on successful page visits, unless validation errors are present. However, this does _not_ include history visits.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("success", (event) => {
  console.log(`Successfully made a visit to ${event.detail.page.url}`);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("success", (event) => {
  console.log(`Successfully made a visit to ${event.detail.page.url}`);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("success", (event) => {
  console.log(`Successfully made a visit to ${event.detail.page.url}`);
});
```

The `success` event is not cancelable.

<a name="events-flash"></a>

### Flash

The `flash` event fires when [flash data](#flash-data) is received from the server. This is useful for displaying toast notifications or handling temporary data in a central location.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("flash", (event) => {
  if (event.detail.flash.toast) {
    showToast(event.detail.flash.toast);
  }
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("flash", (event) => {
  if (event.detail.flash.toast) {
    showToast(event.detail.flash.toast);
  }
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("flash", (event) => {
  if (event.detail.flash.toast) {
    showToast(event.detail.flash.toast);
  }
});
```

The `flash` event is not cancelable and fires on every response that carries flash data.

<a name="events-error"></a>

### Error

The `error` event fires when validation errors are present on "successful" page visits.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("error", (event) => {
  console.log(event.detail.errors);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("error", (event) => {
  console.log(event.detail.errors);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("error", (event) => {
  console.log(event.detail.errors);
});
```

The `error` event is not cancelable.

<a name="events-http-exception"></a>

### HTTP Exception

The `httpException` event fires when a non-Inertia response is received from the server, such as an HTML or JSON response. A valid Inertia response has the `X-Inertia` header set to `true` and a JSON payload containing [the page object](https://inertiajs.com/docs/v3/core-concepts/the-protocol#the-page-object).

This event is fired for all response types, including `200`, `400`, and `500` response codes.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("httpException", (event) => {
  console.log(`An invalid Inertia response was received.`);
  console.log(event.detail.response);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("httpException", (event) => {
  console.log(`An invalid Inertia response was received.`);
  console.log(event.detail.response);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("httpException", (event) => {
  console.log(`An invalid Inertia response was received.`);
  console.log(event.detail.response);
});
```

You may cancel the `httpException` event to prevent Inertia from showing the non-Inertia response modal.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("httpException", (event) => {
  event.preventDefault();

  // Handle the invalid response yourself...
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("httpException", (event) => {
  event.preventDefault();

  // Handle the invalid response yourself...
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("httpException", (event) => {
  event.preventDefault();

  // Handle the invalid response yourself...
});
```

<a name="events-network-error"></a>

### Network Error

The `networkError` event fires on unexpected XHR errors such as network interruptions. In addition, this event fires for errors generated when resolving page components.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("networkError", (event) => {
  console.log(`An unexpected error occurred during an Inertia visit.`);
  console.log(event.detail.error);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("networkError", (event) => {
  console.log(`An unexpected error occurred during an Inertia visit.`);
  console.log(event.detail.error);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("networkError", (event) => {
  console.log(`An unexpected error occurred during an Inertia visit.`);
  console.log(event.detail.error);
});
```

You may cancel the `networkError` event to prevent the error from being thrown.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("networkError", (event) => {
  event.preventDefault();
  // Handle the error yourself
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("networkError", (event) => {
  event.preventDefault();
  // Handle the error yourself
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("networkError", (event) => {
  event.preventDefault();
  // Handle the error yourself
});
```

This event will _not_ fire for XHR requests that receive `400`- and `500`-level responses or for non-Inertia responses, as these situations are handled in other ways by Inertia. Please consult the [error handling documentation](#error-handling) for more information.

<a name="events-finish"></a>

### Finish

The `finish` event fires after an XHR request has completed for both "successful" and "unsuccessful" responses. This event is useful for hiding loading indicators.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("finish", (event) => {
  NProgress.done();
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("finish", (event) => {
  NProgress.done();
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("finish", (event) => {
  NProgress.done();
});
```

The `finish` event is not cancelable.

<a name="events-navigate"></a>

### Navigate

The `navigate` event fires on successful page visits, as well as when navigating through history.

**v3.9.0+**

The event detail includes a `type` value describing how the navigation happened: `initial` for the first page load, `visit` for Inertia visits, and `history` for back and forward navigation.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("navigate", (event) => {
  console.log(`Navigated to ${event.detail.page.url}`);
  console.log(`Navigation type: ${event.detail.type}`);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("navigate", (event) => {
  console.log(`Navigated to ${event.detail.page.url}`);
  console.log(`Navigation type: ${event.detail.type}`);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("navigate", (event) => {
  console.log(`Navigated to ${event.detail.page.url}`);
  console.log(`Navigation type: ${event.detail.type}`);
});
```

The `navigate` event is not cancelable.

<a name="events-location"></a>

### Location

**v3.6.0+**

The `location` event fires whenever Inertia is about to force a full-page navigation, either because the [asset version](#asset-versioning) changed or because the server returned an `Inertia::location()` redirect. The event detail includes a `versionChange` boolean so you may tell an automatic asset version change apart from a manual redirect.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("location", (event) => {
  console.log(`Navigating to ${event.detail.url}`);
  console.log(`Triggered by a version change: ${event.detail.versionChange}`);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("location", (event) => {
  console.log(`Navigating to ${event.detail.url}`);
  console.log(`Triggered by a version change: ${event.detail.versionChange}`);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("location", (event) => {
  console.log(`Navigating to ${event.detail.url}`);
  console.log(`Triggered by a version change: ${event.detail.versionChange}`);
});
```

You may cancel the `location` event to take over the navigation yourself, for example to show a "new version available" banner instead of reloading.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("location", (event) => {
  if (event.detail.versionChange) {
    event.preventDefault();
    showBanner("A new version is available.");
  }
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("location", (event) => {
  if (event.detail.versionChange) {
    event.preventDefault();
    showBanner("A new version is available.");
  }
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("location", (event) => {
  if (event.detail.versionChange) {
    event.preventDefault();
    showBanner("A new version is available.");
  }
});
```

<a name="events-prefetching"></a>

### Prefetching

The `prefetching` event fires when the router starts prefetching a page.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("prefetching", (event) => {
  console.log(`Prefetching ${event.detail.visit.url}`);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("prefetching", (event) => {
  console.log(`Prefetching ${event.detail.visit.url}`);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("prefetching", (event) => {
  console.log(`Prefetching ${event.detail.visit.url}`);
});
```

The `prefetching` event is not cancelable.

<a name="events-prefetched"></a>

### Prefetched

The `prefetched` event fires when the router has successfully prefetched a page.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.on("prefetched", (event) => {
  console.log(`Prefetched ${event.detail.visit.url}`);
});
```

```jsx tab=React
import { router } from "@inertiajs/react";

router.on("prefetched", (event) => {
  console.log(`Prefetched ${event.detail.visit.url}`);
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.on("prefetched", (event) => {
  console.log(`Prefetched ${event.detail.visit.url}`);
});
```

The `prefetched` event is not cancelable.

<a name="events-event-callbacks"></a>

### Event Callbacks

In addition to the global events described throughout this page, Inertia also provides a number of [event callbacks](#manual-visits-event-callbacks) that fire when you manually make Inertia visits.

<a name="progress-indicators"></a>

## Progress Indicators

Since Inertia requests are made via XHR, there would typically not be a browser loading indicator when navigating from one page to another. To solve this, Inertia displays a progress indicator at the top of the page whenever you make an Inertia visit. However, [asynchronous requests](#progress-indicators-visit-options) do not show the progress indicator unless explicitly configured.

Of course, if you prefer, you can disable Inertia's default loading indicator and provide your own custom implementation. We'll discuss both approaches below.

<a name="progress-indicators-default"></a>

### Default

Inertia's default progress indicator is a light-weight wrapper around the [NProgress](https://ricostacruz.com/nprogress/) library. You can customize it via the `progress` property of the `createInertiaApp()` function.

```js
createInertiaApp({
  progress: {
    // The delay after which the progress bar will appear, in milliseconds...
    delay: 250,
    // The color of the progress bar...
    color: "#29d",
    // Whether to include the default NProgress styles...
    includeCSS: true,
    // Whether the NProgress spinner will be shown...
    showSpinner: false,
  },
  // ...
});
```

For applications using a [Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP) that restricts inline styles, you may pass a `nonce` to `createInertiaApp()` to permit the styles Inertia injects for the progress bar. See [Content Security Policy](#client-side-setup-content-security-policy) for details.

You may disable Inertia's default loading indicator by setting the `progress` property to `false`.

```js
createInertiaApp({
  progress: false,
  // ...
});
```

<a name="progress-indicators-programmatic-access"></a>

### Programmatic Access

When you need to control the progress indicator outside of Inertia requests, for example, when making requests with Axios or other libraries, you can use Inertia's progress methods directly.

```js tab=Vue
import { progress } from "@inertiajs/vue3";

progress.start(); // Begin progress animation
progress.set(0.25); // Set to 25% complete
progress.finish(); // Complete and fade out
progress.reset(); // Reset to start
progress.remove(); // Complete and remove from DOM
progress.hide(); // Hide progress bar
progress.reveal(); // Show progress bar

progress.isStarted(); // Returns boolean
progress.getStatus(); // Returns current percentage or null
```

```js tab=React
import { progress } from "@inertiajs/react";

progress.start(); // Begin progress animation
progress.set(0.25); // Set to 25% complete
progress.finish(); // Complete and fade out
progress.reset(); // Reset to start
progress.remove(); // Complete and remove from DOM
progress.hide(); // Hide progress bar
progress.reveal(); // Show progress bar

progress.isStarted(); // Returns boolean
progress.getStatus(); // Returns current percentage or null
```

```js tab=Svelte
import { progress } from "@inertiajs/svelte";

progress.start(); // Begin progress animation
progress.set(0.25); // Set to 25% complete
progress.finish(); // Complete and fade out
progress.reset(); // Reset to start
progress.remove(); // Complete and remove from DOM
progress.hide(); // Hide progress bar
progress.reveal(); // Show progress bar

progress.isStarted(); // Returns boolean
progress.getStatus(); // Returns current percentage or null
```

The `hide()` and `reveal()` methods work together to prevent conflicts when separate parts of your code need to control progress visibility. Each `hide()` call increments an internal counter, while `reveal()` decrements it. The progress bar only appears when this counter reaches zero.

However, `reveal()` accepts an optional `force` parameter that bypasses this counter. Inertia uses this pattern internally to hide progress during prefetching while ensuring it appears for actual navigation.

```js
progress.hide(); // Counter = 1, bar hidden
progress.hide(); // Counter = 2, bar still hidden
progress.reveal(); // Counter = 1, bar still hidden
progress.reveal(); // Counter = 0, bar now visible

// Force reveal bypasses the counter
progress.reveal(true);
```

If you've disabled the progress indicator with `progress: false` in `createInertiaApp()`, these programmatic methods will not work.

<a name="progress-indicators-custom"></a>

### Custom

It's also possible to set up your own custom page loading indicators using Inertia [events](#events). Let's explore how to do this using the [NProgress](https://ricostacruz.com/nprogress/) library as an example.

First, disable Inertia's default loading indicator.

```js
createInertiaApp({
  progress: false,
  // ...
});
```

Next, install the NProgress library.

```bash
npm install nprogress
```

After installation, you'll need to add the NProgress [styles](https://github.com/rstacruz/nprogress/blob/master/nprogress.css) to your project. You can do this using a CDN hosted copy of the styles.

```html
<link
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/nprogress/0.2.0/nprogress.min.css"
/>
```

Then, import both `NProgress` and the Inertia `router` into your application.

```js tab=Vue
import NProgress from "nprogress";
import { router } from "@inertiajs/vue3";
```

```js tab=React
import NProgress from "nprogress";
import { router } from "@inertiajs/react";
```

```js tab=Svelte
import NProgress from "nprogress";
import { router } from "@inertiajs/svelte";
```

Next, add a `start` event listener. We'll use this listener to show the progress bar when a new Inertia visit begins.

```js
router.on("start", () => NProgress.start());
```

Finally, add a `finish` event listener to hide the progress bar when the page visit finishes.

```js
router.on("finish", () => NProgress.done());
```

That's it! Now, as you navigate from one page to another, the progress bar will be added and removed from the page.

<a name="progress-indicators-handling-canceled-visits"></a>

#### Handling Canceled Visits

While this custom progress implementation works great for page visits that finish properly, it would be nice to handle canceled visits as well. First, for interrupted visits (those that get canceled as a result of a new visit), the progress bar should simply be reset back to the start position. Second, for manually canceled visits, the progress bar should be immediately removed from the page.

We can accomplish this by inspecting the `event.detail.visit` object that's provided to the finish event.

```js
router.on("finish", (event) => {
  if (event.detail.visit.completed) {
    NProgress.done();
  } else if (event.detail.visit.interrupted) {
    NProgress.set(0);
  } else if (event.detail.visit.cancelled) {
    NProgress.done();
    NProgress.remove();
  }
});
```

<a name="progress-indicators-file-upload-progress"></a>

#### File Upload Progress

Let's take this a step further. When files are being uploaded, it would be great to update the loading indicator to reflect the upload progress. This can be done using the `progress` event.

```js
router.on("progress", (event) => {
  if (event.detail.progress.percentage) {
    NProgress.set((event.detail.progress.percentage / 100) * 0.9);
  }
});
```

Now, instead of the progress bar "trickling" while the files are being uploaded, it will actually update its position based on the progress of the request. We limit the progress here to 90%, since we still need to wait for a response from the server.

<a name="progress-indicators-loading-indicator-delay"></a>

#### Loading Indicator Delay

The last thing we're going to implement is a loading indicator delay. It's often preferable to delay showing the loading indicator until a request has taken longer than 250-500 milliseconds. This prevents the loading indicator from appearing constantly on quick page visits, which can be visually distracting.

To implement the delay behavior, we'll use the `setTimeout` and `clearTimeout` functions. Let's start by defining a variable to keep track of the timeout.

```js
let timeout = null;
```

Next, let's update the `start` event listener to start a new timeout that will show the progress bar after 250 milliseconds.

```js
router.on("start", () => {
  timeout = setTimeout(() => NProgress.start(), 250);
});
```

Next, we'll update the `finish` event listener to clear any existing timeouts in the event that the page visit finishes before the timeout does.

```js
router.on("finish", (event) => {
  clearTimeout(timeout);
  // ...
});
```

In the `finish` event listener, we need to determine if the progress bar has actually started displaying progress, otherwise we'll inadvertently cause it to show before the timeout has finished.

```js
router.on("finish", (event) => {
  clearTimeout(timeout);

  if (!NProgress.isStarted()) {
    return;
  }
  // ...
});
```

And, finally, we need to do the same check in the `progress` event listener.

```js
router.on("progress", (event) => {
  if (!NProgress.isStarted()) {
    return;
  }
  // ...
});
```

That's it, you now have a beautiful custom page loading indicator!

<a name="progress-indicators-complete-example"></a>

#### Complete Example

For convenience, here is the full source code of the final version of our custom loading indicator.

```js tab=Vue
import NProgress from "nprogress";
import { router } from "@inertiajs/vue3";

let timeout = null;

router.on("start", () => {
  timeout = setTimeout(() => NProgress.start(), 250);
});

router.on("progress", (event) => {
  if (NProgress.isStarted() && event.detail.progress.percentage) {
    NProgress.set((event.detail.progress.percentage / 100) * 0.9);
  }
});

router.on("finish", (event) => {
  clearTimeout(timeout);

  if (!NProgress.isStarted()) {
    return;
  }

  if (event.detail.visit.completed) {
    NProgress.done();
  } else if (event.detail.visit.interrupted) {
    NProgress.set(0);
  } else if (event.detail.visit.cancelled) {
    NProgress.done();
    NProgress.remove();
  }
});
```

```js tab=React
import NProgress from "nprogress";
import { router } from "@inertiajs/react";

let timeout = null;

router.on("start", () => {
  timeout = setTimeout(() => NProgress.start(), 250);
});

router.on("progress", (event) => {
  if (NProgress.isStarted() && event.detail.progress.percentage) {
    NProgress.set((event.detail.progress.percentage / 100) * 0.9);
  }
});

router.on("finish", (event) => {
  clearTimeout(timeout);

  if (!NProgress.isStarted()) {
    return;
  }

  if (event.detail.visit.completed) {
    NProgress.done();
  } else if (event.detail.visit.interrupted) {
    NProgress.set(0);
  } else if (event.detail.visit.cancelled) {
    NProgress.done();
    NProgress.remove();
  }
});
```

```js tab=Svelte
import NProgress from "nprogress";
import { router } from "@inertiajs/svelte";

let timeout = null;

router.on("start", () => {
  timeout = setTimeout(() => NProgress.start(), 250);
});

router.on("progress", (event) => {
  if (NProgress.isStarted() && event.detail.progress.percentage) {
    NProgress.set((event.detail.progress.percentage / 100) * 0.9);
  }
});

router.on("finish", (event) => {
  clearTimeout(timeout);

  if (!NProgress.isStarted()) {
    return;
  }

  if (event.detail.visit.completed) {
    NProgress.done();
  } else if (event.detail.visit.interrupted) {
    NProgress.set(0);
  } else if (event.detail.visit.cancelled) {
    NProgress.done();
    NProgress.remove();
  }
});
```

<a name="progress-indicators-visit-options"></a>

### Visit Options

In addition to these configurations, Inertia provides two visit options to control the loading indicator on a per-request basis: `showProgress` and `async`. These options offer greater control over how Inertia handles asynchronous requests and manages progress indicators.

<a name="progress-indicators-show-progress"></a>

#### Show Progress

The `showProgress` option provides fine-grained control over the visibility of the loading indicator during requests.

```js
router.get("/settings", {}, { showProgress: false });
```

<a name="progress-indicators-async"></a>

#### Async

The `async` option allows you to perform asynchronous requests without displaying the default progress indicator. It can be used in combination with the `showProgress` option.

```js
// Disable the progress indicator
router.get("/settings", {}, { async: true });
// Enable the progress indicator with async requests
router.get("/settings", {}, { async: true, showProgress: true });
```

<a name="scroll-management"></a>

## Scroll Management

<a name="scroll-management-scroll-resetting"></a>

### Scroll Resetting

When navigating between pages, Inertia mimics default browser behavior by automatically resetting the scroll position of the document body (as well as any [scroll regions](#scroll-management-scroll-regions) you've defined) back to the top.

In addition, Inertia keeps track of the scroll position of each page and automatically restores that scroll position as you navigate forward and back in history.

<a name="scroll-management-scroll-preservation"></a>

### Scroll Preservation

Sometimes it's desirable to prevent the default scroll resetting when making visits. You can disable this behavior by setting the `preserveScroll` option to `true`.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit(url, { preserveScroll: true });
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit(url, { preserveScroll: true });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit(url, { preserveScroll: true });
```

If you'd like to only preserve the scroll position if the response includes validation errors, set the `preserveScroll` option to "errors".

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.visit(url, { preserveScroll: "errors" });
```

```js tab=React
import { router } from "@inertiajs/react";

router.visit(url, { preserveScroll: "errors" });
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.visit(url, { preserveScroll: "errors" });
```

You can also lazily evaluate the `preserveScroll` option based on the response by providing a callback.

```js tab=Vue
import { router } from "@inertiajs/vue3";

router.post("/users", data, {
  preserveScroll: (page) => page.props.someProp === "value",
});
```

```js tab=React
import { router } from "@inertiajs/react";

router.post("/users", data, {
  preserveScroll: (page) => page.props.someProp === "value",
});
```

```js tab=Svelte
import { router } from "@inertiajs/svelte";

router.post("/users", data, {
  preserveScroll: (page) => page.props.someProp === "value",
});
```

When using an [Inertia link](#links), you can preserve the scroll position using the `preserveScroll` prop.

```vue tab=Vue
import { Link } from '@inertiajs/vue3'

<Link href="/" preserve-scroll>Home</Link>
```

```jsx tab=React
import { Link } from "@inertiajs/react";

<Link preserveScroll href="/">
  Home
</Link>;
```

```svelte tab=Svelte
import { inertia, Link } from '@inertiajs/svelte'

<a href="/" use:inertia={{ preserveScroll: true }}>Home</a>

<Link href="/" preserveScroll>Home</Link>
```

<a name="scroll-management-scroll-regions"></a>

### Scroll Regions

If your app doesn't use document body scrolling, but instead has scrollable elements (using the `overflow` CSS property), scroll resetting will not work.

In these situations, you must tell Inertia which scrollable elements to manage by adding the `scroll-region` attribute to the element.

```html
<div class="overflow-y-auto" scroll-region="">
  <!-- Your page content -->
</div>
```

<a name="scroll-management-text-fragments"></a>

### Text Fragments

[Text fragments](https://developer.mozilla.org/en-US/docs/Web/URI/Reference/Fragment/Text_fragments) allow you to link directly to specific text on a page using a special URL syntax like `#:~:text=term`. However, the browser removes the fragment directive before any JavaScript runs, so text fragments only work if the targeted text is present in the initial HTML response.

To use text fragments with your Inertia pages, enable [server-side rendering](#server-side-rendering).

<a name="server-side-rendering"></a>

## Server-Side Rendering (SSR)

Server-side rendering pre-renders your JavaScript pages on the server, allowing your visitors to receive fully rendered HTML when they visit your application. Since fully rendered HTML is served by your application, it's also easier for search engines to index your site.

Server-side rendering uses Node.js to render your pages in a background process; therefore, Node must be available on your server for server-side rendering to function properly. Inertia's SSR server requires Node.js 22 or higher.

<a name="server-side-rendering-starter-kits"></a>

### Starter Kits

If you are using Hypervel's [React starter kit](/docs/{{version}}/starter-kits), Inertia SSR is [supported](/docs/{{version}}/starter-kits#inertia-ssr) through a build command:

```bash
npm run build:ssr
```

<a name="server-side-rendering-vite-plugin-setup"></a>

### Vite Plugin Setup

The recommended way to configure SSR is with the [`@inertiajs/vite` plugin](#client-side-setup-installation). This approach handles SSR configuration automatically, including development mode SSR without a separate Node.js server.

<a name="server-side-rendering-install-the-vite-plugin"></a>

#### Install the Vite plugin

```bash
npm install @inertiajs/vite
```

<a name="server-side-rendering-configure-vite"></a>

#### Configure Vite

Add the Inertia plugin to your `vite.config.js` file. The plugin will automatically detect your SSR entry point.

```js
import inertia from '@inertiajs/vite'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        inertia(),
    ],
})
```

You may also configure SSR options explicitly.

```js
inertia({
    ssr: {
        entry: 'resources/js/ssr.js',
        port: 13714,
        host: '127.0.0.1',
        cluster: true,
    },
})
```

You may pass `false` to opt out of the plugin's automatic SSR handling, for example if you prefer to [configure SSR manually](#server-side-rendering-manual-setup) or [disable SSR entirely](#server-side-rendering-disabling-ssr).

```js
inertia({
    ssr: false,
})
```

<a name="server-side-rendering-update-your-build-script"></a>

#### Update your build script

Update the `build` script in your `package.json` to build both bundles.

```json
"scripts": {
    "dev": "vite",
   "build": "vite build" // [tl! remove]
   "build": "vite build && vite build --ssr" // [tl! add]
},
```

<a name="server-side-rendering-development-mode"></a>

#### Development Mode

The Vite plugin handles SSR automatically during development. There is no need to build your SSR bundle or start a separate Node.js server. Simply run your Vite dev server as usual:

```bash
npm run dev
```

The Vite plugin exposes a server endpoint that Hypervel uses for rendering, complete with HMR support. Hypervel reads the server URL from Vite's hot file. You may override it using `inertia.ssr.hot_url` or the `INERTIA_SSR_HOT_URL` environment variable.

<a name="server-side-rendering-production"></a>

#### Production

For production, build both bundles and start the SSR server.

```bash
npm run build
php artisan inertia:start-ssr
```

<a name="server-side-rendering-clustering"></a>

#### Clustering

By default, the SSR server runs on a single thread. You may enable clustering to start multiple Node servers on the same port, with requests handled by each thread in a round-robin fashion.

```js
inertia({
    ssr: {
        cluster: true,
    },
})
```

<a name="server-side-rendering-host"></a>

#### Host

By default, the SSR server binds to `0.0.0.0`, making it accessible on all network interfaces. You may restrict it to a specific interface using the `host` option.

```js
inertia({
    ssr: {
        host: '127.0.0.1',
    },
})
```

<a name="server-side-rendering-manual-setup"></a>

### Manual Setup

The Vite plugin reuses your `app.js` entry point for SSR by default, so no separate file is needed. Most customizations may be handled using the [`withApp` callback](#client-side-setup-customizing-the-app).

For more control, such as providing a [manual `setup` callback](#client-side-setup-manual-setup), you may create a separate `resources/js/ssr.js` entry point and update your `app.js` to use [client-side hydration](#server-side-rendering-client-side-hydration).

<a name="server-side-rendering-ssr-entry-point"></a>

#### SSR Entry Point

```js tab=Vue
import { createInertiaApp } from '@inertiajs/vue3'
import createServer from '@inertiajs/vue3/server'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

createServer(page =>
    createInertiaApp({
        page,
        render: renderToString,
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.vue')
            return pages[`./Pages/${name}.vue`]()
        },
        setup({ App, props, plugin }) {
            return createSSRApp({
                render: () => h(App, props),
            }).use(plugin)
        },
    }),
)
```

```jsx tab=React
import { createInertiaApp } from '@inertiajs/react'
import createServer from '@inertiajs/react/server'
import ReactDOMServer from 'react-dom/server'

createServer(page =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.jsx')
            return pages[`./Pages/${name}.jsx`]()
        },
        setup: ({ App, props }) => <App {...props} />,
    }),
)
```

```js tab=Svelte
import { createInertiaApp } from '@inertiajs/svelte'
import createServer from '@inertiajs/svelte/server'
import { render } from 'svelte/server'

createServer(page =>
    createInertiaApp({
        page,
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.svelte')
            return pages[`./Pages/${name}.svelte`]()
        },
        setup({ App, props }) {
            return render(App, { props })
        },
    }),
)
```

Be sure to add anything that's missing from your `app.js` file that makes sense to run in SSR mode, such as plugins or custom mixins.

<a name="server-side-rendering-client-side-hydration"></a>

#### Client-Side Hydration

You should also update your `app.js` to use hydration instead of normal rendering. This allows Vue, React, or Svelte to pick up the server-rendered HTML and make it interactive without re-rendering it.

```js tab=Vue
import { createApp, h } from 'vue' // [tl! remove]
import { createSSRApp, h } from 'vue' // [tl! add]
import { createInertiaApp } from '@inertiajs/vue3'

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.vue')
        return pages[`./Pages/${name}.vue`]()
    },
    setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) }) // [tl! remove]
    createSSRApp({ render: () => h(App, props) }) // [tl! add]
        .use(plugin)
        .mount(el)
    },
})
```

```js tab=React
import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client' // [tl! remove]
import { hydrateRoot } from 'react-dom/client' // [tl! add]

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.jsx')
        return pages[`./Pages/${name}.jsx`]()
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />) // [tl! remove]
        hydrateRoot(el, <App {...props} />) // [tl! add]
    },
})
```

```js tab=Svelte
import { createInertiaApp } from '@inertiajs/svelte'
import { mount } from 'svelte' // [tl! remove]
import { hydrate, mount } from 'svelte' // [tl! add]

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.svelte')
        return pages[`./Pages/${name}.svelte`]()
    },
    setup({ el, App, props }) {
        mount(App, { target: el, props }) // [tl! remove]
        if (el.dataset.serverRendered === 'true') { // [tl! add]
            hydrate(App, { target: el, props }) // [tl! add]
        } else { // [tl! add]
            mount(App, { target: el, props }) // [tl! add]
        } // [tl! add]
    },
})
```

<a name="server-side-rendering-opting-out-of-the-vite-plugin"></a>

#### Opting Out of the Vite Plugin

You may pass `ssr: false` to the Inertia plugin to disable its automatic SSR handling and manage the SSR build yourself. You should also add the `ssr` property to the Laravel Vite plugin configuration so it knows about your entry point.

```js tab=Vue
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            ssr: 'resources/js/ssr.js', // [tl! add]
            refresh: true,
        }),
        inertia({
            ssr: false, // [tl! add]
        }),
    ],
})
```

```js tab=React
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.jsx'],
            ssr: 'resources/js/ssr.jsx', // [tl! add]
            refresh: true,
        }),
        inertia({
            ssr: false, // [tl! add]
        }),
    ],
})
```

```js tab=Svelte
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            ssr: 'resources/js/ssr.js', // [tl! add]
            refresh: true,
        }),
        inertia({
            ssr: false, // [tl! add]
        }),
    ],
})
```

<a name="server-side-rendering-clustering-2"></a>

#### Clustering

You may pass the `cluster` option to `createServer` to start multiple Node servers on the same port, with requests handled by each thread in a round-robin fashion.

```js tab=Vue
createServer(page =>
    createInertiaApp({
        // ...
    }),
    { cluster: true },
)
```

```jsx tab=React
createServer(page =>
    createInertiaApp({
        // ...
    }),
    { cluster: true },
)
```

```js tab=Svelte
createServer(page =>
    createInertiaApp({
        // ...
    }),
    { cluster: true },
)
```

<a name="server-side-rendering-host-2"></a>

#### Host

By default, the SSR server binds to `0.0.0.0`, making it accessible on all network interfaces. You may pass the `host` option to `createServer` to restrict it to a specific interface.

```js tab=Vue
createServer(page =>
    createInertiaApp({
        // ...
    }),
    { host: '127.0.0.1' },
)
```

```jsx tab=React
createServer(page =>
    createInertiaApp({
        // ...
    }),
    { host: '127.0.0.1' },
)
```

```js tab=Svelte
createServer(page =>
    createInertiaApp({
        // ...
    }),
    { host: '127.0.0.1' },
)
```

<a name="server-side-rendering-running-the-ssr-server"></a>

### Running the SSR Server

> [!NOTE]
> The SSR server is only required in production. During development, the [Vite plugin](#server-side-rendering-development-mode) handles SSR automatically.

Once you have built both your client-side and server-side bundles, you may start the SSR server using the following Artisan command.

```bash
php artisan inertia:start-ssr
```

By default, the SSR server uses `node` as its runtime. You may change this by setting the `runtime` option in your `config/inertia.php` file. An absolute path to the runtime binary is also supported.

```php
'ssr' => [
    'runtime' => env('INERTIA_SSR_RUNTIME', 'node'),
],
```

The `--runtime` flag on the Artisan command overrides the configured value for a single invocation.

```bash
php artisan inertia:start-ssr --runtime=bun
```

You may also enable the `ensure_runtime_exists` option to verify the runtime binary exists before attempting to start the SSR server. The command will exit with an error if the binary cannot be found.

```php
'ssr' => [
    'ensure_runtime_exists' => (bool) env('INERTIA_SSR_ENSURE_RUNTIME_EXISTS', false),
],
```

With the server running, you should be able to access your app in the browser with server-side rendering enabled. In fact, you should be able to disable JavaScript entirely and still navigate around your application.

<a name="server-side-rendering-error-handling"></a>

### Error Handling

When SSR rendering fails, Inertia gracefully falls back to client-side rendering. The Vite plugin logs detailed error information to the console, including the component name, request URL, source location, and a tailored hint to help you resolve the issue.

Common SSR errors are automatically classified. Browser API errors (such as referencing `window` or `document` in server-rendered code) include guidance on moving the code to a lifecycle hook. Components that depend on browser APIs may also be wrapped in [`WhenMounted`](#server-side-rendering-client-only-components). Component resolution errors suggest checking file paths and casing.

Inertia also dispatches an `SsrRenderFailed` event on the server. You may listen for this event to log failures or send them to an error tracking service.

```php
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Log;
use Hypervel\Inertia\Ssr\SsrRenderFailed;

Event::listen(SsrRenderFailed::class, function (SsrRenderFailed $event) {
    Log::warning('SSR failed', $event->toArray());
});
```

<a name="server-side-rendering-throwing-on-error"></a>

#### Throwing on Error

Since Inertia gracefully falls back to client-side rendering, SSR failures may go unnoticed. Your tests pass because the client-side render succeeds, but your users never receive server-rendered HTML. This is especially common in end-to-end browser tests.

You may set the `throw_on_error` option in your `config/inertia.php` file to throw an exception instead of falling back silently, allowing you to catch SSR issues early.

```php
'ssr' => [
    'throw_on_error' => (bool) env('INERTIA_SSR_THROW_ON_ERROR', false),
],
```

> [!WARNING]
> This option is not recommended for production, as it will cause SSR failures to return an error response instead of falling back to client-side rendering.

You may set the environment variable in your `phpunit.xml` to enable this only during testing.

```xml
<env name="INERTIA_SSR_THROW_ON_ERROR" value="true"/>
```

<a name="server-side-rendering-configuring-the-ssr-request"></a>

### Configuring the SSR Request

Requests to the SSR server default to a 2-second connect timeout and a 5-second total timeout. You may set the `connect_timeout` and `timeout` options in your `config/inertia.php` file so a slow or unresponsive SSR server doesn't delay your responses, as Inertia falls back to client-side rendering when the request fails. Set either option to `null` to use the HTTP client's global timeout instead.

You may also configure these timeouts using the `INERTIA_SSR_CONNECT_TIMEOUT` and `INERTIA_SSR_TIMEOUT` environment variables.

```php
'ssr' => [
    'connect_timeout' => 2.0,
    'timeout' => 5.0,
],
```

The `inertia.ssr.backoff` option determines how long a worker skips SSR after a connection failure or malformed response. It defaults to 5 seconds, preventing concurrent requests from repeatedly contacting an unavailable SSR server.

For more control, you may use the `Inertia::configureSsrRequestUsing()` method, typically from a service provider. The callback receives the `PendingRequest` before it is sent, so you may add retries, headers, or any other option supported by Hypervel's [HTTP client](/docs/{{version}}/http-client).

```php
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Inertia\Inertia;

Inertia::configureSsrRequestUsing(function (PendingRequest $request) {
    $request->timeout(5)->retry(2, throw: false);
});
```

Both the timeouts and the callback apply to render, health-check and shutdown requests. Since SSR requests use Hypervel's HTTP client, `Http::fake()` and `Http::preventStrayRequests()` also apply to them in tests.

<a name="server-side-rendering-disabling-ssr"></a>

### Disabling SSR

SSR has two layers: the **Vite plugin** serves SSR during development and builds the SSR bundle for production, while the **Hypervel adapter** dispatches rendering requests to the SSR server. To fully disable SSR, you should disable both.

```js
inertia({
    ssr: false,
})
```

```php
'ssr' => [
    'enabled' => false,
],
```

You may also prevent the Hypervel adapter from dispatching SSR requests programmatically using the `Inertia::disableSsr()` method. This is useful when you want to keep SSR in your build but [disable it during tests](#testing-disabling-ssr-during-tests) or in specific environments.

```php
use Hypervel\Inertia\Inertia;

Inertia::disableSsr();
```

A boolean or closure may be provided to disable SSR conditionally.

```php
Inertia::disableSsr(app()->runningUnitTests());

Inertia::disableSsr(fn () => app()->runningUnitTests());
```

<a name="server-side-rendering-excluding-routes-from-ssr"></a>

### Excluding Routes from SSR

Sometimes you may wish to skip server-side rendering for certain routes while keeping SSR enabled for the rest of your application.

<a name="server-side-rendering-via-middleware"></a>

#### Via Middleware

You may use the `$withoutSsr` property on your Inertia middleware to disable SSR for specific route patterns.

```php
use Hypervel\Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Defines the routes that should not use SSR.
     *
     * @var array<int, string>
     */
    protected array $withoutSsr = [
        'admin/*',
        'dashboard',
    ];
}
```

<a name="server-side-rendering-via-facade"></a>

#### Via Facade

You may also exclude specific routes using the `Inertia::withoutSsr()` method, typically called from a service provider.

```php
use Hypervel\Inertia\Inertia;

Inertia::withoutSsr(['admin/*', 'dashboard']);
```

<a name="server-side-rendering-per-request"></a>

#### Per-Request

You may disable SSR for the current request by calling `Inertia::disableSsr()`.

```php
if (request()->is('admin/*')) {
    Inertia::disableSsr();
}
```

Do not change application configuration during a request. Hypervel's configuration is shared by concurrent requests in the worker; `Inertia::disableSsr()` changes only the current request's Inertia state.

<a name="server-side-rendering-client-only-components"></a>

### Client-Only Components

**v3.8.0+**

Sometimes, a component relies on browser APIs such as `window` or `document` and cannot be rendered on the server. Instead of disabling SSR for the entire page, you may wrap the component in `WhenMounted`, which renders its children only after mounting in the browser. The `fallback` is rendered on the server and during hydration instead.

```vue tab=Vue
<script setup>
import { WhenMounted } from "@inertiajs/vue3";
import Chart from "@/Components/Chart.vue";
</script>

<template>
  <WhenMounted>
    <template #fallback>
      <div>Loading chart...</div>
    </template>

    <Chart />
  </WhenMounted>
</template>
```

```jsx tab=React
import { WhenMounted } from "@inertiajs/react";
import Chart from "@/Components/Chart";

export default () => (
  <WhenMounted fallback={<div>Loading chart...</div>}>
    <Chart />
  </WhenMounted>
);
```

```svelte tab=Svelte
<script>
  import { WhenMounted } from "@inertiajs/svelte";
  import Chart from "@/Components/Chart.svelte";
</script>

<WhenMounted>
  {#snippet fallback()}
    <div>Loading chart...</div>
  {/snippet}

  <Chart />
</WhenMounted>
```

The fallback only appears on the initial server-rendered page load. Subsequent visits, as well as applications not using SSR, render the children immediately.

**React:**

JSX children are evaluated before `WhenMounted` renders, so expressions that access `window` directly still run on the server. You may pass a function as the children to defer their evaluation until the component mounts.

```jsx
<WhenMounted fallback={<div>Loading chart...</div>}>
  {() => <Chart width={window.innerWidth} />}
</WhenMounted>
```

Note that `WhenMounted` only skips rendering on the server. The wrapped component is still part of your SSR bundle, so its module must be safe to import on the server.

<a name="server-side-rendering-deployment"></a>

### Deployment

When deploying your SSR-enabled app to production, you'll need to build both the client-side (`app.js`) and server-side bundles (`ssr.js`), and then run the SSR server as a background process, typically using a process monitoring tool such as Supervisor.

```bash
php artisan inertia:start-ssr
```

To stop the SSR server, for instance when you deploy a new version of your website, you may utilize the `inertia:stop-ssr` Artisan command. Your process monitor (such as Supervisor) should be responsible for automatically restarting the SSR server after it has stopped.

```bash
php artisan inertia:stop-ssr
```

By default, the command fails when the SSR server is not running. You may pass the `--graceful` option to return a successful exit code instead, making it ideal for deployment scripts where the SSR server is not yet running.

```bash
php artisan inertia:stop-ssr --graceful
```

You may use the `inertia:check-ssr` Artisan command to verify that the SSR server is running. This can be helpful after deployment and works well as a Docker health check to ensure the server is responding as expected.

```bash
php artisan inertia:check-ssr
```

By default, a check is performed to ensure the server-side bundle exists before dispatching a request to the SSR server. In some cases, such as when your app runs on multiple servers or is containerized, the web server may not have access to the SSR bundle. To disable this check, you may set the `inertia.ssr.ensure_bundle_exists` configuration value to `false`.

<a name="testing"></a>

## Testing

There are many different ways to test an Inertia application. This page provides a quick overview of the tools available.

<a name="testing-end-to-end-tests"></a>

### End-to-end Tests

One popular approach to testing your JavaScript page components is to use an end-to-end testing tool like [Cypress](https://www.cypress.io/) or [Pest](https://pestphp.com). These are browser automation tools that allow you to run real simulations of your app in the browser. These tests are known to be slower; however, since they test your application at the same layer as your end users, they can provide a lot of confidence that your app is working correctly. And, since these tests are run in the browser, your JavaScript code is actually executed and tested as well.

<a name="testing-client-side-unit-tests"></a>

### Client-Side Unit Tests

Another approach to testing your page components is to use a client-side unit testing framework, such as [Vitest](https://vitest.dev/), [Jest](https://jestjs.io/), or [Mocha](https://mochajs.org/). This approach allows you to test your JavaScript page components in isolation using Node.js.

<a name="testing-endpoint-tests"></a>

### Endpoint Tests

In addition to testing your JavaScript page components, you will likely want to also test the Inertia responses that are returned by your server-side framework. A popular approach to doing this is using endpoint tests, where you make requests to your application and examine the responses. Hypervel [provides tooling](/docs/{{version}}/http-tests) for executing these types of tests.

However, to make this process even easier, Inertia's Hypervel adapter provides additional HTTP testing tools. Let's take a look at an example.

```php
use Hypervel\Inertia\Testing\AssertableInertia as Assert;

class PodcastsControllerTest extends TestCase
{
    public function test_can_view_podcast()
    {
        $this->get('/podcasts/41')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Podcasts/Show')
                ->has('podcast', fn (Assert $page) => $page
                    ->where('id', 41)
                    ->where('subject', 'The Laravel Podcast')
                    ->where('description', 'The Laravel Podcast brings you Laravel and PHP development news and discussion.')
                    ->has('seasons', 4)
                    ->has('seasons.4.episodes', 21)
                    ->has('host', fn (Assert $page) => $page
                        ->where('id', 1)
                        ->where('name', 'Matt Stauffer')
                    )
                    ->has('subscribers', 7, fn (Assert $page) => $page
                        ->where('id', 2)
                        ->where('name', 'Claudio Dekker')
                        ->where('platform', 'Apple Podcasts')
                        ->etc()
                        ->missing('email')
                        ->missing('password')
                    )
                )
            );
    }
}
```

As you can see in the example above, you may use these assertion methods to assert against the content of the data provided to the Inertia response. In addition, you may assert that array data has a given length as well as scope your assertions.

You may use the `inertiaProps` method to retrieve the props returned in the response. You can pass a key to retrieve a specific property, and nested properties are supported using "dot" notation.

```php
$response = $this->get('/podcasts/41');

// Returns all props...
$response->inertiaProps();

// Returns a specific prop...
$response->inertiaProps('podcast');

// Returns a nested prop using "dot" notation...
$response->inertiaProps('podcast.id');
```

Let's dig into the `assertInertia` method and the available assertions in detail. First, to assert that the Inertia response has a property, you may use the `has` method. You can think of this method as being similar to PHP's `isset` function.

```php
$response->assertInertia(fn (Assert $page) => $page
    // Checking if a root-level property has 7 items...
    ->has('podcasts', 7)

    // Checking nested properties using "dot" notation...
    ->has('podcast.subscribers', 7)
);
```

To assert that an Inertia property has a specified number of items, you may provide the expected size as the second argument to the `has` method.

```php
$response->assertInertia(fn (Assert $page) => $page
    // Checking if a root-level property has 7 items...
    ->has('podcasts', 7)

    // Checking nested properties using "dot" notation...
    ->has('podcast.subscribers', 7)
);
```

The `has` method may also be used to scope properties in order to lessen repetition when asserting against nested properties.

```php
$response->assertInertia(fn (Assert $page) => $page
    // Creating a single-level property scope...
    ->has('message', fn (Assert $page) => $page
        // We can now continue chaining methods...
        ->has('subject')
        ->has('comments', 5)

        // And can even create a deeper scope using "dot" notation...
        ->has('comments.0', fn (Assert $page) => $page
            ->has('body')
            ->has('files', 1)
            ->has('files.0', fn (Assert $page) => $page
                ->has('url')
            )
        )
    )
);
```

When scoping into Inertia properties that are arrays or collections, you may also assert that a specified number of items are present in addition to scoping into the first item.

```php
$response->assertInertia(fn (Assert $page) => $page
    // Assert that there are 5 comments and automatically scope into the first comment...
    ->has('comments', 5, fn (Assert $page) => $page
        ->has('body')
        // ...
    )
);
```

To assert that an Inertia property has an expected value, you may use the `where` assertion.

```php
$response->assertInertia(fn (Assert $page) => $page
    ->has('message', fn (Assert $page) => $page
        // Assert that the subject prop matches the given message...
        ->where('subject', 'This is an example message')

        // Or, assert against deeply nested values...
        ->where('comments.0.files.0.name', 'example-attachment.pdf')
    )
);
```

Inertia's testing methods will automatically fail when you haven't interacted with at least one of the props in a scope. While this is generally useful, you might run into situations where you're working with unreliable data (such as from an external feed), or with data that you really don't want interact with in order to keep your test simple. For these situations, the `etc` method exists.

```php
$response->assertInertia(fn (Assert $page) => $page
    ->has('message', fn (Assert $page) => $page
        ->has('subject')
        ->has('comments')
        ->etc()
    )
);
```

The `missing` method is the exact opposite of the `has` method, ensuring that the property does not exist. This method makes a great companion to the `etc` method.

```php
$response->assertInertia(fn (Assert $page) => $page
    ->has('message', fn (Assert $page) => $page
        ->has('subject')
        ->missing('published_at')
        ->etc()
    )
);
```

<a name="testing-testing-partial-reloads"></a>

#### Testing Partial Reloads

You may use the `reloadOnly` and `reloadExcept` methods to test how your application responds to [partial reloads](#partial-reloads). These methods perform a follow-up request and allow you to make assertions against the response.

```php
$response->assertInertia(fn (Assert $page) => $page
    ->has('orders')
    ->missing('statuses')
    ->reloadOnly('statuses', fn (Assert $reload) => $reload
        ->missing('orders')
        ->has('statuses', 5)
    )
);
```

Instead of passing a single prop as a string, you may also pass an array of props to `reloadOnly` or `reloadExcept`.

<a name="testing-testing-deferred-props"></a>

#### Testing Deferred Props

You may use the `loadDeferredProps` method to test how your application responds to [deferred props](#deferred-props). This method performs a follow-up request to load the deferred props and allows you to make assertions against the response.

```php
$response->assertInertia(fn (Assert $page) => $page
    ->has('users')
    ->has('roles')
    ->missing('permissions') // Deferred prop not in initial response
    ->loadDeferredProps(fn (Assert $reload) => $reload
        ->has('permissions')
        ->where('permissions.0.name', 'edit users')
    )
);
```

You may also load specific deferred prop groups by passing the group name as the first argument to the `loadDeferredProps` method.

```php
$response->assertInertia(fn (Assert $page) => $page
    ->has('users')
    ->missing('teams')
    ->missing('projects')
    ->loadDeferredProps('attributes', fn (Assert $reload) => $reload
        ->has('teams', 5)
        ->has('projects')
        ->missing('permissions') // Different group
    )
);
```

Instead of passing a single group as a string, you may also pass an array of groups to `loadDeferredProps`.

```php
$response->assertInertia(fn (Assert $page) => $page
    ->loadDeferredProps(['default', 'attributes'], fn (Assert $reload) => $reload
        ->has('permissions')
        ->has('teams')
        ->has('projects')
    )
);
```

<a name="testing-testing-flash-data"></a>

#### Testing Flash Data

You may use the `hasFlash` and `missingFlash` methods to test [flash data](#flash-data) in your Inertia responses.

```php
$response->assertInertia(fn (Assert $page) => $page
    // Assert flash data exists...
    ->hasFlash('message')

    // Assert flash data has a specific value...
    ->hasFlash('message', 'Item saved!')

    // Nested values are supported using "dot" notation...
    ->hasFlash('notification.type', 'success')

    // Assert flash data does not exist...
    ->missingFlash('error')
);
```

<a name="testing-redirect-responses"></a>

##### Redirect Responses

The `hasFlash` and `missingFlash` methods above only work on rendered Inertia page responses. For redirect responses, you may use the `assertInertiaFlash` and `assertInertiaFlashMissing` methods directly on the test response to assert against the session's flash data.

```php
$response = $this->post('/users');

$response->assertRedirect('/dashboard')
    ->assertInertiaFlash('message')
    ->assertInertiaFlash('message', 'User created!')
    ->assertInertiaFlash('notification.type', 'success')
    ->assertInertiaFlashMissing('error');
```

<a name="testing-disabling-ssr-during-tests"></a>

### Disabling SSR During Tests

Your application may have [SSR enabled](#server-side-rendering) for development and production, but you might not need it during testing. You may set `INERTIA_SSR_ENABLED` to `false` in your `phpunit.xml` to prevent the Hypervel adapter from dispatching SSR requests.

```xml
<env name="INERTIA_SSR_ENABLED" value="false" />
```

Alternatively, you may use the `Inertia::disableSsr()` method in your test base class.

```php
use Hypervel\Inertia\Inertia;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Inertia::disableSsr();
    }
}
```

A boolean or closure may be passed to disable SSR conditionally. This is typically called from a service provider.

```php
// Evaluated immediately...
Inertia::disableSsr(app()->runningUnitTests());

// Evaluated lazily...
Inertia::disableSsr(fn () => app()->runningUnitTests());
```

<a name="typescript"></a>

## TypeScript

Inertia provides first-class TypeScript support. You may configure global types using declaration merging, and pass generics to hooks and router methods for type-safe props, forms, and state management.

<a name="typescript-using-pnpm"></a>

### Using pnpm

Due to pnpm's strict dependency isolation, `@inertiajs/core` is not accessible at `node_modules/@inertiajs/core`. Instead, it's nested inside `.pnpm/`, which prevents TypeScript module augmentation from resolving the module.

You may fix this by configuring pnpm to [hoist the package](https://pnpm.io/settings#publichoistpattern). Add the following to your `.npmrc` file and run `pnpm install`.

```ini
public-hoist-pattern[]=@inertiajs/core
```

Alternatively, you may add `@inertiajs/core` as a direct dependency in your project.

```bash
pnpm add @inertiajs/core
```

<a name="typescript-global-configuration"></a>

### Global Configuration

You may configure Inertia's types globally by augmenting the `InertiaConfig` interface in the `@inertiajs/core` module. This is typically done in a `global.d.ts` file in your project's root or `types` directory.

```ts
// global.d.ts
import '@inertiajs/core'

declare module "@inertiajs/core" {
  export interface InertiaConfig {
    sharedPageProps: {
      auth: { user: { id: number; name: string } | null };
      appName: string;
    };
    flashDataType: {
      toast?: { type: "success" | "error"; message: string };
    };
    errorValueType: string[];
    layoutProps: {
      title: string;
      showSidebar: boolean;
    };
    namedLayoutProps: {
      app: { title: string; theme: "light" | "dark" };
      content: { padding: string; maxWidth: string };
    };
  }
}
```

> [!NOTE]
> The `import` statement (or `export {}`) is required to make this file a module. Without it, `declare module` replaces the module definition instead of augmenting it. Your `tsconfig.json` also needs to include `.d.ts` files, so make sure a pattern like `"resources/js/**/*.d.ts"` is present in the `include` array.

<a name="typescript-shared-page-props"></a>

#### Shared Page Props

The `sharedPageProps` option defines the type of data that is [shared](#shared-data) with every page in your application. With this configuration, `page.props.auth` and `page.props.appName` will be properly typed everywhere.

```ts
sharedPageProps: {
    auth: { user: { id: number; name: string } | null }
    appName: string
}
```

<a name="typescript-flash-data"></a>

#### Flash Data

The `flashDataType` option defines the type of [flash data](#flash-data) in your application.

```ts
flashDataType: {
    toast?: { type: 'success' | 'error'; message: string }
}
```

<a name="typescript-error-values"></a>

#### Error Values

By default, validation error values are typed as `string`. You may configure TypeScript to expect arrays instead for [multiple errors per field](#validation-multiple-errors-per-field).

```ts
errorValueType: string[]
```

<a name="typescript-layout-props"></a>

#### Layout Props

The `layoutProps` option types the data accepted by `setLayoutProps()`. The `namedLayoutProps` option types the data accepted by `setLayoutProps('name', props)`, keyed by layout name.

```ts
layoutProps: {
    title: string
    showSidebar: boolean
}
namedLayoutProps: {
    app: { title: string; theme: 'light' | 'dark' }
    content: { padding: string; maxWidth: string }
}
```

With this configuration, `setLayoutProps({ title: 'Dashboard' })` is type-checked, and `setLayoutProps('app', { theme: 'dark' })` validates both the layout name and its props.

You may also pass a generic type parameter directly to `setLayoutProps` for ad-hoc typing without configuring the global `InertiaConfig` interface.

```ts
setLayoutProps<{ custom: string }>({ custom: 'value' })
setLayoutProps<{ collapsed: boolean }>('sidebar', { collapsed: true })
```

<a name="typescript-page-components"></a>

### Page Components

You may type the `import.meta.glob` result for better type safety when resolving page components.

```ts tab=Vue
import { createInertiaApp } from "@inertiajs/vue3";
import type { DefineComponent } from "vue";

createInertiaApp({
  resolve: (name) => {
    const pages = import.meta.glob<DefineComponent>("./Pages/**/*.vue");
    return pages[`./Pages/${name}.vue`]();
  },
  // ...
});
```

```tsx tab=React
import { createInertiaApp, type ResolvedComponent } from "@inertiajs/react";

createInertiaApp({
  resolve: (name) => {
    const pages = import.meta.glob<ResolvedComponent>("./Pages/**/*.tsx");
    return pages[`./Pages/${name}.tsx`]();
  },
  // ...
});
```

```ts tab=Svelte
import { createInertiaApp, type ResolvedComponent } from "@inertiajs/svelte";

createInertiaApp({
  resolve: (name) => {
    const pages = import.meta.glob<ResolvedComponent>("./Pages/**/*.svelte");
    return pages[`./Pages/${name}.svelte`]();
  },
  // ...
});
```

<a name="typescript-page-props"></a>

### Page Props

You may type page-specific props by passing a generic to `usePage()`. These are merged with your global `sharedPageProps`, giving you autocomplete and type checking for both shared and page-specific data.

```vue tab=Vue
<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";

const page = usePage<{
  posts: { id: number; title: string }[];
}>();
</script>
```

```tsx tab=React
import { usePage } from "@inertiajs/react";

export default function Posts() {
  const page = usePage<{
    posts: { id: number; title: string }[];
  }>();

  return (
    <ul>
      {page.props.posts.map((post) => (
        <li key={post.id}>{post.title}</li>
      ))}
    </ul>
  );
}
```

```svelte tab=Svelte
<script lang="ts">
    import { usePage } from '@inertiajs/svelte'

    const page = usePage<{
        posts: { id: number; title: string }[]
    }>()
</script>
```

<a name="typescript-form-helper"></a>

### Form Helper

The [form helper](#forms-form-helper) accepts a generic type parameter for type-safe form data and error handling. This provides autocomplete for form fields and errors, and prevents typos in field names.

```vue tab=Vue
<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";

const form = useForm<{
  name: string;
  email: string;
  company: { name: string };
}>({
  name: "",
  email: "",
  company: { name: "" },
});
</script>
```

```tsx tab=React
import { useForm } from "@inertiajs/react";

export default function CreateUser() {
  const form = useForm<{
    name: string;
    email: string;
    company: { name: string };
  }>({
    name: "",
    email: "",
    company: { name: "" },
  });

  return null;
}
```

```svelte tab=Svelte
<script lang="ts">
    import { useForm } from '@inertiajs/svelte'

    const form = useForm<{
        name: string
        email: string
        company: { name: string }
    }>({
        name: '',
        email: '',
        company: { name: '' },
    })
</script>
```

<a name="typescript-nested-data-and-arrays"></a>

#### Nested Data and Arrays

Form types fully support nested objects and arrays. You may access and update nested fields using dot notation, and error keys are automatically typed to match.

```ts
import { useForm } from "@inertiajs/react";

const form = useForm<{
  user: { name: string; email: string };
  tags: { id: number; label: string }[];
}>({
  user: { name: "", email: "" },
  tags: [],
});
```

<a name="typescript-form-component"></a>

### Form Component

The `<Form>` component accepts a generic type parameter for type-safe slot props. In React, you may pass the generic directly. In Vue and Svelte, you may use the `createForm` helper to create a typed form component.

```tsx tab=React
import { Form } from "@inertiajs/react";

interface UserForm {
  name: string;
  email: string;
}

export default function CreateUser() {
  return (
    <Form<UserForm> action="/users" method="post">
      {({ errors }) => (
        <>
          <input type="text" name="name" />
          {errors.name && <div>{errors.name}</div>}
          <button type="submit">Create User</button>
        </>
      )}
    </Form>
  );
}
```

```vue tab=Vue
<script setup lang="ts">
import { createForm } from "@inertiajs/vue3";

interface UserForm {
  name: string;
  email: string;
}

const TypedForm = createForm<UserForm>();
</script>

<template>
  <TypedForm action="/users" method="post" #default="{ errors }">
    <input type="text" name="name" />
    <div v-if="errors.name">{{ errors.name }}</div>
    <button type="submit">Create User</button>
  </TypedForm>
</template>
```

```svelte tab=Svelte
<script lang="ts">
    import { createForm } from '@inertiajs/svelte'

    interface UserForm {
        name: string
        email: string
    }

    const TypedForm = createForm<UserForm>()
</script>

<TypedForm action="/users" method="post">
    {#snippet children({ errors })}
        <input type="text" name="name" />
        {#if errors.name}<div>{errors.name}</div>{/if}
        <button type="submit">Create User</button>
    {/snippet}
</TypedForm>
```

The generic provides autocomplete and type checking for the `errors` object, `setError`, `clearErrors`, and other slot props that reference form fields.

<a name="typescript-useformcontext"></a>

#### useFormContext

The `useFormContext()` function also accepts a generic type parameter, providing type-safe access to the form context from child components.

```tsx tab=React
import { useFormContext } from "@inertiajs/react";

const form = useFormContext<UserForm>();
```

```vue tab=Vue
<script setup lang="ts">
import { useFormContext } from "@inertiajs/vue3";

const form = useFormContext<UserForm>();
</script>
```

```svelte tab=Svelte
<script lang="ts">
    import { useFormContext } from '@inertiajs/svelte'

    const form = useFormContext<UserForm>()
</script>
```

<a name="typescript-http-helper"></a>

### HTTP Helper

The [`useHttp`](#http-requests) hook accepts two generic type parameters: the form data type and an optional default response type.

```ts
import { useHttp } from "@inertiajs/react";

interface UserForm {
  name: string;
  email: string;
}

interface UserResponse {
  id: number;
  name: string;
}

const http = useHttp<UserForm, UserResponse>({ name: "", email: "" });
```

<a name="typescript-layout-callbacks"></a>

### Layout Callbacks

The `LayoutCallback` type provides type safety for [layout callbacks](#layouts-callback-props). The callback receives the page's props (typed via your global `sharedPageProps` configuration) and should return a valid layout definition.

```tsx tab=React
import type { LayoutCallback } from "@inertiajs/react";
import Layout from "./Layout";

const Profile = () => <h1>Profile</h1>;

const callback: LayoutCallback = (props) => {
  return [Layout, { title: props.auth.user?.name }];
};

Profile.layout = callback;

export default Profile;
```

```vue tab=Vue
<script lang="ts">
import Layout from "./Layout.vue";
import type { LayoutCallback } from "@inertiajs/vue3";

const callback: LayoutCallback = (props) => {
  return [Layout, { title: props.auth.user?.name }];
};

export default {
  layout: callback,
};
</script>
```

```svelte tab=Svelte
<script module lang="ts">
    import Layout from './Layout.svelte'
    import type { LayoutCallback } from '@inertiajs/svelte'

    export const layout: LayoutCallback = (props) => {
        return [Layout, { title: props.auth.user?.name }]
    }
</script>
```

<a name="typescript-remembering-state"></a>

### Remembering State

The `useRemember` hook accepts a generic type parameter for type-safe local state persistence, providing autocomplete and ensuring values match the expected types.

```vue tab=Vue
<script setup lang="ts">
import { useRemember } from "@inertiajs/vue3";

const filters = useRemember<{
  search: string;
  status: "active" | "inactive" | "all";
}>({
  search: "",
  status: "all",
});
</script>
```

```tsx tab=React
import { useRemember } from "@inertiajs/react";

export default function Users() {
  const [filters, setFilters] = useRemember<{
    search: string;
    status: "active" | "inactive" | "all";
  }>({
    search: "",
    status: "all",
  });

  return null;
}
```

```svelte tab=Svelte
<script lang="ts">
    import { useRemember } from '@inertiajs/svelte'

    const filters = useRemember<{
        search: string
        status: 'active' | 'inactive' | 'all'
    }>({
        search: '',
        status: 'all',
    })
</script>
```

<a name="typescript-restoring-state"></a>

### Restoring State

The `router.restore()` method accepts a generic for typing state restored from [history](#remembering-state-manually-saving-state).

```ts
import { router } from "@inertiajs/react";

interface TableState {
  sortBy: string;
  sortDesc: boolean;
  page: number;
}

const restored = router.restore<TableState>("table-state");

if (restored) {
  console.log(restored.sortBy);
}
```

<a name="typescript-router-requests"></a>

### Router Requests

Router methods accept a generic for typing request data, providing type checking for the data being sent.

```ts
import { router } from "@inertiajs/react";

interface CreateUserData {
  name: string;
  email: string;
}

router.post<CreateUserData>("/users", {
  name: "John",
  email: "john@example.com",
});
```

<a name="typescript-scoped-flash-data"></a>

### Scoped Flash Data

The `router.flash()` method accepts a generic for typing page or section-specific flash data, separate from the global `flashDataType` configuration.

```ts
import { router } from "@inertiajs/react";

router.flash<{ paymentError: string }>({ paymentError: "Card declined" });
```

<a name="typescript-client-side-visits"></a>

### Client-Side Visits

The `router.push()` and `router.replace()` methods accept a generic for typing [client-side visit](#manual-visits-client-side-visits) props.

```ts
import { router } from "@inertiajs/react";

interface UserPageProps {
  user: { id: number; name: string };
}

router.push<UserPageProps>({
  component: "Users/Show",
  url: "/users/1",
  props: { user: { id: 1, name: "John" } },
});

router.replace<UserPageProps>({
  props: (current) => ({
    ...current,
    user: { ...current.user, name: "Updated" },
  }),
});
```
