# Hypervel View

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/view)

Documentation: https://hypervel.org/docs/views

## Differences From Laravel

- `Blade::component()` accepts the component alias before the class name: `Blade::component('package-alert', Alert::class)`.
- A `View` passed as section content is rendered before the content is stored, so later changes to that `View` instance do not affect the section.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/View
