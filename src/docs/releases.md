# Release Notes

- [Versioning Scheme](#versioning-scheme)
- [Support Policy](#support-policy)
- [Hypervel 0.4](#hypervel-04)

<a name="versioning-scheme"></a>
## Versioning Scheme

Hypervel is currently a pre-1.0 framework. Until Hypervel reaches 1.0, version numbers follow a `0.MINOR.PATCH` format. Minor releases, such as 0.3 to 0.4, may contain breaking changes. Patch releases within a minor release, such as 0.4.1 or 0.4.2, should not intentionally contain breaking changes.

When referencing the Hypervel framework or its components from your application or package, you should use a version constraint such as `^0.4`. For pre-1.0 packages, Composer will resolve `^0.4` to releases greater than or equal to 0.4.0 and less than 0.5.0. This allows your application to receive compatible patch releases while requiring you to intentionally opt into the next minor release.

The 0.3 to 0.4 upgrade is expected to be the largest breaking change before Hypervel 1.0, as this release makes Hypervel a standalone framework decoupled from Hyperf. Future 0.x releases may still contain breaking changes, but those changes will be documented in the release notes and [upgrade guide](/docs/{{version}}/upgrade).

Once Hypervel reaches 1.0, the project intends to follow standard [Semantic Versioning](https://semver.org) conventions.

<a name="named-arguments"></a>
#### Named Arguments

[Named arguments](https://www.php.net/manual/en/functions.arguments.php#functions.named-arguments) are not covered by Hypervel's backwards compatibility guidelines. We may choose to rename function arguments when necessary in order to improve the Hypervel codebase. Therefore, using named arguments when calling Hypervel methods should be done cautiously and with the understanding that the parameter names may change in the future.

<a name="support-policy"></a>
## Support Policy

Hypervel is still pre-1.0 and does not yet follow a long-term support release schedule. The latest minor release receives active bug and security fixes, and applications should stay on the latest supported minor release when possible.

Although Hypervel is pre-1.0, the framework is used in production by the core team across multiple applications. Bugs are addressed quickly, and breaking changes are documented so applications can upgrade with clear guidance.

<div class="overflow-auto">

| Version | PHP  | Runtime | Status     |
| ------- | ---- | ------- | ---------- |
| 0.4     | 8.4+ | Swoole  | Active     |
| 0.3     | —    | —       | Superseded |

</div>

<a name="hypervel-04"></a>
## Hypervel 0.4

Hypervel 0.4 is a major refactor that makes Hypervel a standalone framework. Previous versions of Hypervel were built on top of Hyperf. In Hypervel 0.4, the framework has been decoupled from Hyperf and many Hyperf packages have been replaced with fresh ports of Laravel packages.

This release brings Hypervel much closer to Laravel's public API while preserving Hypervel's Swoole and coroutine runtime. In general, Hypervel 0.4 packages provide Laravel-style APIs under `Hypervel\` namespaces, with internal changes for coroutine safety and long-lived worker performance.

<a name="largest-pre-1-upgrade"></a>
### Largest Pre-1.0 Upgrade

The upgrade from Hypervel 0.3 to 0.4 is expected to be the largest breaking change before Hypervel reaches 1.0. The architectural shift from a Hyperf-based framework to a standalone framework affects many packages and application internals.

For step-by-step migration instructions, please consult the [upgrade guide](/docs/{{version}}/upgrade). This page explains what changed at a high level, while the upgrade guide explains how to update an application.

<a name="php-84"></a>
### PHP 8.4

Hypervel 0.4 requires PHP 8.4 or greater.

<a name="swoole-runtime"></a>
### Swoole Runtime

Hypervel runs on Swoole using long-lived workers and coroutines. This architecture allows Hypervel to provide Laravel-style APIs while sharing expensive framework state for the lifetime of a worker and isolating request-specific state through coroutine-safe internals.

<a name="laravel-style-package-ports"></a>
### Laravel-Style Package Ports

Many Hypervel 0.4 packages are fresh ports of Laravel packages. These ports aim to provide an API that is almost identical to Laravel where the feature makes sense for Hypervel.

Some differences are intentional. Hypervel uses `Hypervel\` namespaces, adapts internals for coroutine safety, adds Swoole-specific performance optimizations, and removes drivers or integrations that Hypervel does not support.

<a name="immutable-dates"></a>
### Immutable Dates

Hypervel 0.4 uses `Hypervel\Support\CarbonImmutable` as the modern default for date helpers, factory-created dates, Eloquent date casts, request casts, and framework-owned timestamps. Immutable values provide predictable value semantics, with additional safety when dates are retained or shared in long-lived workers. Applications may still opt into the explicit mutable `Hypervel\Support\Carbon` class through the `Date` facade.

<a name="coroutine-safety"></a>
### Coroutine Safety

Hypervel 0.4 refactors framework internals for Swoole's coroutine runtime. State that would be request-local in a traditional PHP process is stored using coroutine-safe APIs, while immutable or process-wide state may be cached for the worker lifetime to avoid repeated work.

<a name="production-usage"></a>
### Production Usage

Hypervel remains pre-1.0, but it is already used in production by the core team. The 0.4 release is designed to provide a more stable foundation for future releases by removing the dependency on Hyperf and aligning more closely with Laravel's developer experience.
