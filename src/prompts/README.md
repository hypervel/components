Prompts for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/prompts)

Documentation: https://hypervel.org/docs/prompts

## Differences From Laravel

- Spinners are animated by a coroutine instead of a forked process, so `spin()` animates only inside a Swoole coroutine. Outside one, it shows a static spinner while the callback runs.
- `Prompt::interactive()`, `fallbackWhen()`, `fallbackUsing()`, `setOutput()`, and `validateUsing()` apply only to the current coroutine when called inside one, so one coroutine's settings do not leak into others in the same worker. Use `Coroutine::fork()` to pass these settings to child coroutines. Call them outside a coroutine, such as during boot, to apply them globally.
- `Logger::prefix()` is not available because Task output uses binary frames. Override `write()` to customize transport writes.
- `NumberPrompt::wrapValidation()` is replaced by `validateIntrinsic()`, which every execution mode calls. Custom prompts should override `validateIntrinsic()` for rules that belong to the prompt type.
- `DataTableRenderer::computeColumnWidths()` is split into `DataTablePrompt::naturalColumnMetrics()`, which measures the content once per prompt, and `DataTableRenderer::fitColumnWidths()`, which fits those widths to the terminal on each render. Override whichever method covers the step you need to change.

Ported from: https://github.com/laravel/prompts
