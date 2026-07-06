Testbench for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/testbench)

Ported from: https://github.com/orchestral/testbench-core

## Differences From Orchestra Testbench

Hypervel does not forward the parent Testbench CLI application's full runtime environment to `package:test` subprocesses. In package-test mode, package and workbench environment files are copied into the child runtime application, while shell or CI environment variables, PHPUnit XML values, and Testbench YAML `env` values continue to reach package-test child processes through their normal channels.

Hypervel does not use Orchestra's `TESTBENCH_APP_BASE_PATH` channel. Each process owns its runtime application identity through `BASE_PATH`, remote child processes receive the current worker clone through `TESTBENCH_BASE_PATH`, and `APP_BASE_PATH` remains the explicit user override.

Hypervel's Testbench skeleton config files contain only intentional differences from the framework base configuration. The framework configuration is merged into the skeleton application during bootstrap.

Hypervel includes root package discovery metadata when a `package:test` worker builds the Testbench package manifest. Orchestra's persistent skeleton can be seeded by the parent Testbench CLI process, while Hypervel's per-worker runtime skeletons may build their manifests directly inside PHPUnit / ParaTest workers.
