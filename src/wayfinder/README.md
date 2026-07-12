Wayfinder for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/wayfinder)

Differences From Laravel
---

- Forced root generation is not supported. Hypervel uses request-scoped
  `URL::useOrigin()` overrides instead of Laravel's global forced root state, so
  Wayfinder generates relative URLs with the configured `app.url` base path and
  explicit route domains.
