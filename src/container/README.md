Container for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/container)

Documentation: https://hypervel.org/docs/container

## Differences From Laravel

Hypervel supports Laravel's named container APIs, but not container ArrayAccess or dynamic service properties. Use `make()` / `get()`, `bound()` / `has()`, `bind()`, and `instance()`. For temporary instance overrides, use `forgetInstance()` to restore the original binding. Hypervel does not expose arbitrary binding removal because registrations are worker-wide boot-time state.

A contextual attribute's resolved value is authoritative, including `null`. Unlike Laravel, Hypervel does not fall through to class or primitive resolution, contextual bindings, or declared defaults after a contextual resolver returns `null`.

`#[BindWhen]` conditions must depend only on boot-stable state. The first matching condition becomes a normal worker-lifetime binding; unmatched conditions remain eligible for reevaluation on later resolutions.

Hypervel does not expose Laravel's protected per-parameter override helpers. Container subclasses that customize parameter override resolution should override `resolveRecipeParameters()`, which receives the current coroutine's resolution state and resolves the complete parameter list in one pass.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Container
