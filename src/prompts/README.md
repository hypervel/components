Prompts for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/prompts)

Documentation: https://hypervel.org/docs/prompts

## Differences From Laravel

- Option lists, multi-select defaults, table headers and rows, and grid items may be `Hypervel\Support\Collection` instances.
- Spinners animate only inside a Swoole coroutine; outside one they render statically.
- `Logger::prefix()` is not available because Task output uses binary frames. Override `write()` to customize transport writes.
- `NumberPrompt::wrapValidation()` is replaced by `validateIntrinsic()` so every execution mode uses the same validation pipeline.
- `DataTableRenderer::computeColumnWidths()` is split into `DataTablePrompt::naturalColumnMetrics()` and `DataTableRenderer::fitColumnWidths()` because source sizing and terminal fitting have different lifetimes.

Ported from: https://github.com/laravel/prompts
