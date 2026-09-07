Wayfinder for Hypervel
===

Documentation: https://hypervel.org/docs/wayfinder

Differences From Laravel
---

- Forced root generation is not supported. Hypervel uses request-scoped
  `URL::useOrigin()` overrides instead of Laravel's global forced root state, so
  Wayfinder generates relative URLs with the configured `app.url` base path and
  explicit route domains.

Ported from: https://github.com/laravel/wayfinder
