Foundation for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/foundation)

## Differences From Laravel

Hypervel's HTTP kernel contract includes the complete middleware stack, group,
alias, and priority management surface because framework and package providers
configure middleware through that contract. Custom HTTP kernels must implement
the same surface, and its mutators are intended for application boot.

Laravel's real-time facades are intentionally not supported. Define explicit facade classes or inject services from the container instead.

Deferred providers and their `services.php` manifest are omitted. Providers register once during worker startup; remove `DeferrableProvider` and `provides()` when porting them.

Maintenance responses are served by the running worker; Laravel's pre-bootstrap `maintenance.php` stub is not generated. Configure your reverse proxy or load balancer to serve a maintenance page when Hypervel is unavailable.

The application locale setters do not change the `app.locale` or `app.fallback_locale` configuration values. `App::setLocale()` applies only to the current request, while `App::setFallbackLocale()` is intended for application boot and changes the fallback shared by the worker.

Laravel's deprecated `VerifyCsrfToken` and `ValidateCsrfToken` middleware aliases and `Middleware::validateCsrfTokens()` method are intentionally not ported. Use `PreventRequestForgery` and configure request-forgery protection with `preventRequestForgery()`.

The `php artisan serve` command starts Hypervel's configured Swoole servers directly instead of PHP's built-in development server.

The default `dev` server process runs `php artisan watch` so the Watcher package can own and restart the long-running Swoole server. Official Hypervel skeletons and starter kits include `hypervel/watcher` as a development dependency.

Laravel's default Pail process is omitted because Hypervel has no Pail-equivalent command. Application logging remains controlled by the application's logging configuration.

Laravel's optional Whoops exception renderer is omitted. Hypervel's built-in renderer provides framework-aware query details and Blade source mapping while applications may still bind a custom `ExceptionRenderer` implementation.

Laravel's deprecated `HandleExceptions::forgetApp()` is omitted. Use `HandleExceptions::flushState()` for test cleanup.

Laravel Mix and the `mix()` helper are not ported; use Vite instead.

`FailOnUnknownFields` accepts the contents of an `array` field without child rules. Define child rules or allowed array keys to restrict those contents.

Ported from: https://github.com/laravel/framework
