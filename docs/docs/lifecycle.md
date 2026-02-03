# Lifecycle
[[toc]]

## Introduction

When using any tool in the "real world", you feel more confident if you understand how that tool works. Application development is no different. When you understand how your development tools function, you feel more comfortable and confident using them.

The goal of this document is to give you a good, high-level overview of how Hypervel works. By getting to know the overall framework better, everything feels less "magical" and you will be more confident building your applications. If you don't understand all of the terms right away, don't lose heart! Just try to get a basic grasp of what is going on, and your knowledge will grow as you explore other sections of the documentation.

## Lifecycle Overview

### First Steps

The entry point for all requests to a Hypervel application is the `artisan` file. The `artisan` file doesn't contain much code. Rather, it is a starting point for loading the rest of the framework.

The `artisan` file loads the Composer generated autoloader definition and environment variables, and then retrieves an instance of the Hypervel application from `bootstrap/app.php`. The first action taken by Hypervel itself is to create an instance of the application / [service container](/docs/container).

::: note
`BASE_PATH` constant is defined in `artisan`. Although we suggest fetching base path from service container, components in Hyperf framework have strong coupling to `BASE_PATH` constant. Therefore you can still find `BASE_PATH` in Hypervel for backward compatibility.
:::

### Container Bindings

Same as in Laravel, the application itself is a service container. When the application created, dependencies scanner collected container bindings from `config/dependencies.php`. This is how dependencies are registered in Hyperf by default. Hypervel also supports registering dependencies in service providers.

### Console Kernel

Next, the console kernel defines an array of `bootstrappers` that will be run before executing the command. These bootstrappers load facade aliases, register service providers, load commands and setup cronjob scheduling. Typically, these classes handle internal Hypervel configurations that you do not need to worry about.

### Config Providers / Service Providers

Config Providers (from Hyperf framework) are similar to Laravel's `Service Providers`. Config Providers will provide all the configuration information of the corresponding components, which will be started by Hypervel when loaded. Essentially every major feature offered by Hypervel is bootstrapped and configured by config providers.

Hypervel also supports [service providers](/docs/providers) in a manner similar to Laravel. Service providers are responsible for bootstrapping the framework's various components and are a fundamental part of the Hypervel application lifecycle.

Hypervel will iterate through this list of providers and instantiate each of them. After instantiating the providers, the `register` method will be called on all of the providers. Then, once all of the providers have been registered, the `boot` method will be called on each provider. This is so service providers may depend on every container binding being registered and available by the time their `boot` method is executed.

While the framework internally uses dozens of Config Providers / Service Providers, you also have the option to create your own. If you're going to create a third-party package, we recommend using `Config Provider` or providing both `Config Provider` and `Service Provider` to ensure compatibility of Hyperf framework.

### Routing

Once the application has been bootstrapped and all service providers have been registered, an HTTP server will be launched and wait for incoming requests.

The `Request` will be handed off to the router for dispatching. The router will dispatch the request to a route or controller, as well as run any route specific middleware.

Middleware provide a convenient mechanism for filtering or examining HTTP requests entering your application. For example, Hypervel includes a middleware that verifies if the user of your application is authenticated. If the user is not authenticated, it will throw `AuthenticationException`. You can set up your middleware in a Laravel style by configuring `app/Http/Kernel.php`. To learn more about middleware, you can refer to the complete [middleware documentation](/docs/middleware).

If the request successfully passes through all of the matched route's assigned middleware, the route or controller method will be executed. The response returned by the route or controller method will then be sent back through the route's chain of middleware. This allows middleware to perform actions both before and after your core application logic executes.

Middleware in Hypervel can filter requests, modify responses, and even terminate the request cycle entirely if necessary. By configuring middleware in `app/Http/Kernel.php`, you can define global middleware, assign middleware to specific routes, or create middleware groups for convenient assignment.

For more detailed information on creating, registering, and using middleware in Hypervel, including examples and best practices, please refer to the [middleware documentation](/docs/middleware).

## Focus on Config Providers / Service Providers

Config Providers / Service providers are truly the key to bootstrapping a Hypervel application. The application instance is created, the service providers are registered, and the request is handed to the bootstrapped application. It's really that simple!

Having a firm grasp of how a Hypervel application is built and bootstrapped via service providers is very valuable. Your application's user-defined service providers are stored in the `app/Providers` directory.

By default, the `AppServiceProvider` is fairly empty. This provider is a great place to add your application's own bootstrapping and service container bindings. For large applications, you may wish to create several service providers, each with more granular bootstrapping for specific services used by your application.
