Foundation for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/foundation)

Ported from: https://github.com/laravel/framework

## Differences From Laravel

Hypervel's HTTP kernel contract includes the complete middleware stack, group,
alias, and priority management surface because framework and package providers
configure middleware through that contract. Custom HTTP kernels must implement
the same surface, and its mutators are intended for application boot.

Laravel's real-time facades are intentionally not supported. Define explicit facade classes or inject services from the container instead.

Laravel's deprecated `Middleware::validateCsrfTokens()` alias is intentionally not ported. Configure request-forgery protection with `preventRequestForgery()`.

The default `dev` server process runs `php artisan watch` so the Watcher package can own and restart the long-running Swoole server. Official Hypervel skeletons and starter kits include `hypervel/watcher` as a development dependency.

Laravel's default Pail process is omitted because Hypervel has no Pail-equivalent command. Application logging remains controlled by the application's logging configuration.

Laravel's optional Whoops exception renderer is omitted. Hypervel's built-in renderer provides framework-aware query details and Blade source mapping while applications may still bind a custom `ExceptionRenderer` implementation.
