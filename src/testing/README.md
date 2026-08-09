Testing for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/testing)

Documentation: https://hypervel.org/docs/testing

## Differences From Laravel

- `ParallelRunner` does not expose Laravel's `getExitCode()` method. ParaTest 7 returns the final
  exit code directly from `RunnerInterface::run()`, and Hypervel's `execute()` method returns it.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Testing
