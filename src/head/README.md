Head for Hypervel
===

Documentation: https://hypervel.org/docs/head

## Differences From Laravel

Head configuration is shared for the worker lifetime. Register defaults and error pages during boot; use the runtime resolvers for request-specific values. See [runtime defaults](https://hypervel.org/docs/head#runtime-defaults).

An unset `og:url` falls back to the canonical URL when a page defines Open Graph metadata; explicit Open Graph URLs take precedence. See [Open Graph](https://hypervel.org/docs/head#open-graph).

Livewire integration is not provided. Use Blade or Inertia for page rendering.

Ported from: https://github.com/laravel/head
