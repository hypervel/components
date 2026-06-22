# Upgrade Guide

- [Upgrading To 0.4 From 0.3](#upgrade-04)
- [Recommended Upgrade Path](#recommended-upgrade-path)
- [What Changed](#what-changed)
- [Migration References](#migration-references)

<a name="upgrade-04"></a>
## Upgrading To 0.4 From 0.3

Hypervel 0.4 is a complete rewrite of the framework that makes Hypervel standalone and decouples it from Hyperf. Previous versions of Hypervel were built on top of Hyperf. Hypervel 0.4 replaces much of that foundation with fresh ports of Laravel packages, while preserving Hypervel's Swoole and coroutine runtime.

Because of the size of this change, an in-place upgrade from Hypervel 0.3 to Hypervel 0.4 is not recommended. The framework structure, configuration, testing stack, package internals, namespaces, and many application-level APIs have changed enough that a traditional step-by-step upgrade guide would be longer and less useful than starting from a clean 0.4 application.

<a name="recommended-upgrade-path"></a>
## Recommended Upgrade Path

For existing Hypervel 0.3 applications, we recommend creating a fresh Hypervel 0.4 application and migrating your application code into it intentionally.

In practice, this means moving your routes, controllers, models, database migrations, jobs, listeners, tests, views, configuration, and frontend code into a new Hypervel 0.4 application while updating each area to match the new Laravel-style APIs and coroutine-safe architecture.

This approach gives you a clean 0.4 application structure, avoids carrying forward obsolete Hyperf-era configuration, and makes it easier to spot the places where your application needs to adapt to Hypervel's new foundation.

<a name="what-changed"></a>
## What Changed

When migrating from Hypervel 0.3 to 0.4, the most important shift is that your application is moving from a Hyperf-based framework to a standalone Laravel-style framework built for Swoole.

The biggest areas to review are:

<div class="content-list" markdown="1">

- Namespaces and APIs now use `Hypervel\` classes that closely follow Laravel's public API.
- Configuration uses the new Laravel-style application structure, with app config files overriding framework defaults instead of replacing every value.
- Request-scoped state is stored in coroutine-safe context APIs instead of worker-wide mutable state.
- Testing uses Hypervel's new PHPUnit 13-based testing stack, including coroutine-aware feature tests and the new Testbench package.

</div>

<a name="migration-references"></a>
## Migration References

The following documentation pages are the best starting points when moving an existing application to Hypervel 0.4:

<div class="content-list" markdown="1">

- [Installation](/docs/{{version}}/installation)
- [Directory Structure](/docs/{{version}}/structure)
- [Configuration](/docs/{{version}}/configuration)
- [Lifecycle](/docs/{{version}}/lifecycle)
- [Database](/docs/{{version}}/database)
- [Queues](/docs/{{version}}/queues)
- [Testing](/docs/{{version}}/testing)
- [Coroutines](/docs/{{version}}/coroutines)
- [Coroutine Context](/docs/{{version}}/coroutine-context)

</div>

> [!NOTE]
> Future Hypervel 0.x upgrades are expected to be much more conventional. The 0.3 to 0.4 upgrade is the largest breaking change expected before Hypervel reaches 1.0.
