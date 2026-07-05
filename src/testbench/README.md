Testbench for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/testbench)

Ported from: https://github.com/orchestral/testbench-core

## Differences From Orchestra Testbench

Hypervel does not forward the parent Testbench CLI application's full runtime environment to `package:test` subprocesses. In package-test mode, package and workbench environment files are copied into the child runtime application, while shell or CI environment variables, PHPUnit XML values, and Testbench YAML `env` values continue to reach package-test child processes through their normal channels.

Hypervel's Testbench skeleton config files contain only intentional differences from the framework base configuration. The framework configuration is merged into the skeleton application during bootstrap.
