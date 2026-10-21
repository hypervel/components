Workbench for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/workbench)

Documentation: https://hypervel.org/docs/testbench

## Differences From Laravel

Orchestra Workbench's `workbench:build`, `workbench:devtool` and `workbench:install` commands, build recipes, Canvas generator presets and stub registrar are not provided, so the `build` and `assets` Workbench settings are not supported. Use Testbench's `package:install` command and your normal frontend tooling instead. Use Hypervel's long-running `schedule:run` command in place of `schedule:work`.

The authentication page assets are published to `public/vendor/workbench/build` under the `hypervel-assets` tag, instead of the application's `public` directory. When `vendor/bin/testbench serve` runs the default skeleton, the assets are copied automatically. For a custom skeleton, publish them with `vendor:publish --provider="Hypervel\Workbench\AuthServiceProvider" --tag=hypervel-assets`. See the [Workbench documentation](https://hypervel.org/docs/testbench#workbench-authentication).

Ported from: https://github.com/orchestral/workbench
