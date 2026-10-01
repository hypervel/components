Testbench for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/testbench)

Documentation: https://hypervel.org/docs/testbench

## Differences From Laravel

Hypervel applies every `#[WithConfig]` value before service providers register. Orchestra's deferred mode is intentionally omitted because configuration is process-global state shared by Hypervel's long-lived Swoole worker. When a package provider uses shallow configuration merging, a nested key replaces that package option's defaults. Tests that need a post-boot value, or a nested value alongside the package defaults, should set it explicitly in the test body.

Hypervel does not forward the parent Testbench CLI application's full runtime environment to `package:test` subprocesses. In package-test mode, package and workbench environment files are copied into the child runtime application, while shell or CI environment variables, PHPUnit XML values, and Testbench YAML `env` values continue to reach package-test child processes through their normal channels.

Hypervel does not use Orchestra's `TESTBENCH_APP_BASE_PATH` channel. Each process owns its runtime application identity through `BASE_PATH`, remote child processes receive the current worker clone through `TESTBENCH_BASE_PATH`, and `APP_BASE_PATH` remains the explicit user override.

Hypervel includes root package discovery metadata when a `package:test` worker builds the Testbench package manifest. Orchestra's persistent skeleton can be seeded by the parent Testbench CLI process, while Hypervel's per-worker runtime skeletons may build their manifests directly inside PHPUnit / ParaTest workers.

Hypervel's `serve` command creates and removes the Workbench `sync` links itself, while Orchestra applies them through the optional Workbench package. Each command gets its own copy of the default skeleton, so `package:sync-skeleton` only syncs custom skeletons. An existing file or directory that isn't a symlink is never replaced at a `to` path outside that copy; Orchestra deletes it. Existing symlinks are still replaced. See [Syncing Workbench Directories](https://hypervel.org/docs/testbench#syncing-workbench-directories).

Pest integration is not supported in Hypervel 0.4. Use PHPUnit test classes; `WithFixtures` does not resolve Pest test files. See the [testing documentation](https://hypervel.org/docs/testing#using-pest).

Ported from: https://github.com/orchestral/testbench-core
