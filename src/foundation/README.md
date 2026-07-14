Foundation for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/foundation)

## Differences From Laravel

Laravel's deprecated `Middleware::validateCsrfTokens()` alias is intentionally not ported. Configure request-forgery protection with `preventRequestForgery()`.

The default `dev` server process runs `php artisan watch` so the Watcher package can own and restart the long-running Swoole server. Official Hypervel skeletons and starter kits include `hypervel/watcher` as a development dependency.

Laravel's default Pail process is omitted because Hypervel has no Pail-equivalent command. Application logging remains controlled by the application's logging configuration.
