<p align="center"><a href="https://hypervel.org" target="_blank"><img src="https://hypervel.org/logo.png" width="400"></a></p>

<p align="center">
<a href="https://github.com/hypervel/components/actions"><img src="https://github.com/hypervel/components/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/hypervel/components"><img src="https://img.shields.io/packagist/dt/hypervel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/hypervel/components"><img src="https://img.shields.io/packagist/v/hypervel/components" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/hypervel/components"><img src="https://img.shields.io/packagist/l/hypervel/components" alt="License"></a>
<a href="https://deepwiki.com/hypervel/components"><img src="https://deepwiki.com/badge.svg" alt="Ask DeepWiki"></a>
</p>

> [!WARNING]
> This branch contains the ongoing, unreleased work for the Hypervel 0.4 rewrite.
>
> Hypervel 0.4 is not ready for use yet. APIs, behavior, configuration, and package internals may change unexpectedly while the rewrite is still in progress.
>
> The published documentation at [hypervel.org/docs](https://hypervel.org/docs) currently covers Hypervel 0.3. If you are experimenting with this branch, use the [in-progress 0.4 documentation](https://github.com/hypervel/components/tree/0.4/src/docs). If you are coming from Laravel, begin with the [porting guide](https://github.com/hypervel/components/blob/0.4/src/docs/porting-from-laravel.md).
>
> Please do not use this branch for projects until a beta release is tagged. If you are experimenting or testing the rewrite, bug reports and feedback are very welcome.

## About Hypervel

> [!NOTE]
> This repository contains the core components of the Hypervel framework. If you want to create a Hypervel application, visit the [Hypervel application repository](https://github.com/hypervel/hypervel).

Hypervel is a modern, opinionated PHP framework built for Swoole. It runs applications in long-lived workers and uses coroutines to handle many requests, jobs, and connections concurrently.

When one coroutine is waiting on a database query, cache lookup, queue operation, file access, or HTTP request, the worker can keep serving other requests and jobs instead of sitting idle. You write ordinary sequential code, and the runtime yields to other work while yours waits.

Hypervel is built for traditional web applications, APIs, microservices, real-time services, background workers, and other applications that spend meaningful time waiting on external systems.

## Framework Features

Hypervel includes the features expected from a modern full-stack framework:

- Fast, expressive [routing](https://github.com/hypervel/components/blob/0.4/src/docs/routing.md) and middleware.
- A powerful [dependency injection container](https://github.com/hypervel/components/blob/0.4/src/docs/container.md) and service provider system.
- [Eloquent ORM](https://github.com/hypervel/components/blob/0.4/src/docs/eloquent.md), schema building, and [database migrations](https://github.com/hypervel/components/blob/0.4/src/docs/migrations.md).
- Multiple [session](https://github.com/hypervel/components/blob/0.4/src/docs/session.md) and [cache](https://github.com/hypervel/components/blob/0.4/src/docs/cache.md) stores.
- [Background jobs](https://github.com/hypervel/components/blob/0.4/src/docs/queues.md), job batching, and [task scheduling](https://github.com/hypervel/components/blob/0.4/src/docs/scheduling.md).
- Real-time [event broadcasting](https://github.com/hypervel/components/blob/0.4/src/docs/broadcasting.md) and [WebSocket support](https://github.com/hypervel/components/blob/0.4/src/docs/websockets.md).
- [Authentication](https://github.com/hypervel/components/blob/0.4/src/docs/authentication.md), [authorization](https://github.com/hypervel/components/blob/0.4/src/docs/authorization.md), [validation](https://github.com/hypervel/components/blob/0.4/src/docs/validation.md), [notifications](https://github.com/hypervel/components/blob/0.4/src/docs/notifications.md), [mail](https://github.com/hypervel/components/blob/0.4/src/docs/mail.md), and [filesystem storage](https://github.com/hypervel/components/blob/0.4/src/docs/filesystem.md).
- [Blade templates](https://github.com/hypervel/components/blob/0.4/src/docs/blade.md) and [Vite](https://github.com/hypervel/components/blob/0.4/src/docs/vite.md) integration for full-stack applications.
- First-class [coroutines](https://github.com/hypervel/components/blob/0.4/src/docs/coroutines.md) and [concurrent HTTP requests](https://github.com/hypervel/components/blob/0.4/src/docs/http-client.md#concurrent-requests).
- Persistent [database](https://github.com/hypervel/components/blob/0.4/src/docs/database.md#connection-pooling) and [Redis](https://github.com/hypervel/components/blob/0.4/src/docs/redis.md#connection-pooling) connection pools.
- Coroutine-aware [testing](https://github.com/hypervel/components/blob/0.4/src/docs/testing.md), with [Testbench](https://github.com/hypervel/components/blob/0.4/src/docs/testbench.md) for package development.
- [gRPC](https://github.com/hypervel/components/blob/0.4/src/docs/grpc.md) and [custom server processes](https://github.com/hypervel/components/blob/0.4/src/docs/server-processes.md).

## Laravel Compatibility

Hypervel aims for Laravel API compatibility wherever it fits. However, Hypervel is not a Laravel clone or drop-in replacement. Many Hypervel components are ports of Laravel packages, adapted for Hypervel's asynchronous runtime, performance requirements, and coroutine safety, but the framework itself has its own architecture, features, supported integrations, and direction.

Moving an existing Laravel application or package to Hypervel is a deliberate port, not a namespace replacement. The [porting guide](https://github.com/hypervel/components/blob/0.4/src/docs/porting-from-laravel.md) explains what needs to change and why.

## Project Direction

Hypervel will continue to track and port Laravel features where they fit, while building features designed for Hypervel's runtime. The dedicated [rate limiter](https://github.com/hypervel/components/blob/0.4/src/docs/rate-limiting.md), [dual-mode Redis cache tags](https://github.com/hypervel/components/blob/0.4/src/docs/cache.md#redis-tag-modes), [layered cache stores](https://github.com/hypervel/components/blob/0.4/src/docs/cache.md#building-cache-stacks), and [Redis session system with user-session management](https://github.com/hypervel/components/blob/0.4/src/docs/session.md#managing-user-sessions) are examples of that direction.

First-party ClickHouse support and built-in integration with [SonicStack](https://sonicstack.io) are also planned. SonicStack is Hypervel's deployment platform and is how we plan to fund ongoing framework development.

## Requirements

Hypervel 0.4 requires PHP 8.4 or later and Swoole 6.2.2 or later. Hypervel's Redis integrations use the PhpRedis extension 6.1 or later; Predis is not supported.

See the [installation documentation](https://github.com/hypervel/components/blob/0.4/src/docs/installation.md#requirements) for the complete list of required PHP extensions and setup instructions.

## This Repository

This monorepo contains Hypervel's core framework components and first-party packages. Components are developed and tested together here, then published as separate Composer packages. Framework changes and pull requests should be submitted to this repository rather than the split package repositories.

## Documentation

The complete Hypervel documentation is available at [hypervel.org/docs](https://hypervel.org/docs).

Hypervel's documentation follows the structure and style of Laravel's documentation, and portions are adapted from it. Our thanks to the Laravel community.

## Contributing

Thank you for considering contributing to Hypervel. The [contribution guide](https://github.com/hypervel/components/blob/0.4/src/docs/contributions.md) explains which changes are accepted, how to run the required checks, and how to prepare a pull request.

For support questions, ideas, and feature requests, please use [GitHub Discussions](https://github.com/hypervel/components/discussions).

## Code of Conduct

Please review and follow Hypervel's [Code of Conduct](https://github.com/hypervel/components/blob/0.4/src/docs/contributions.md#code-of-conduct) when participating in the community.

## Security Vulnerabilities

If you discover a security vulnerability in Hypervel, please report it privately by emailing Albert Chen at [albert@hypervel.org](mailto:albert@hypervel.org). Security vulnerabilities will be addressed promptly.

Please do not report security vulnerabilities through public GitHub issues or discussions.

## License

The Hypervel framework is open-sourced software licensed under the [MIT license](https://github.com/hypervel/components/blob/0.4/LICENSE.md).

## Created by

<table align="center">
    <tr>
        <td align="center">
            <a href="https://github.com/albertcht">
                <img src="https://github.com/albertcht.png?size=96" width="96" alt="Albert Chen">
                <br>
                <sub><b>Albert Chen</b></sub>
            </a>
        </td>
        <td align="center">
            <a href="https://github.com/binaryfire">
                <img src="https://github.com/binaryfire.png?size=96" width="96" alt="Raj Siva-Rajah">
                <br>
                <sub><b>Raj Siva-Rajah</b></sub>
            </a>
        </td>
    </tr>
</table>
