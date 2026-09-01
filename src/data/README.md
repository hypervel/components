# Hypervel Data

Documentation: https://hypervel.org/docs/data-objects

## Differences From Laravel

Hypervel Data keeps the familiar `spatie/laravel-data` vocabulary with fixed, coroutine-safe internals for long-lived workers. Metadata is analyzed once per used class and retained in worker memory; there is no discovery or deploy cache command.

`Data`, `Dto`, and `Resource` validate request input by default. Each `factory()` call starts a fresh operation, omitted nullable properties become `null`, and a declared `Optional` union always preserves absence.

Constructor injection uses Hypervel contextual attributes. Their resolved value always wins over payload input, including `null`; use a named factory or creation hook when payload values should take precedence. Hypervel's compiled wildcard validation is used for uniform nested collections, with concrete indexed rules for dynamic shapes.

Deprecated collection forwarding, Livewire integration, and TypeScript generation are not included. Use `toCollection()` for collection operations; TypeScript generation belongs in a general transformer package.

Ported from: https://github.com/spatie/laravel-data
