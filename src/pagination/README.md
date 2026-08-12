Pagination for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/pagination)

Documentation: https://hypervel.org/docs/pagination

## Differences From Laravel

- Hypervel ships Tailwind pagination views only; Laravel's Bootstrap view selectors are intentionally omitted. Use `Paginator::defaultView()` and `Paginator::defaultSimpleView()` to configure custom pagination views.
- Length-aware paginator JSON includes `current_page_url`.
- Default request resolvers read `RequestContext`; replacing the container's `request` binding does not change pagination state.

Ported from: https://github.com/laravel/framework
