# Packages Toolkit: Testbench
[[toc]]

## Introduction

Packages Toolkit for Hypervel is a collection of packages that have been designed to write, test, and preview your Hypervel packages.

Before going through the rest of this documentation, please take some time to read the [Package Development](/docs/packages) section of Hypervel's own documentation, if you haven't done so yet.

## Installation

To start using Packages Toolkit for Hypervel you can first install the components:

```shell:no-line-numbers
composer require --dev hypervel/testbench
```

## Configuration

The Packages Toolkit for Hypervel uses `testbench.yaml` as a configuration file where you can define the following schemas to be used within Workbench environment:

 Name            | Type      | Description
:----------------|:----------|:--------------------
 `hypervel`      | `string`  | Set the path to Hypervel skeleton.
 `providers`     | `array`   | List of Service Provider classes to be loaded.
 `dont-discover` | `array`   | List of packages to be ignored.
 `env`           | `array`   | Set environment variables to be loaded.
 `purge`         | `array`   | Configurable `files` and `directories` to be pruned after executing test cases.
 `workbench`     | Object    | See [Workbench configuration](#workbench-discovery-configuration) for details.

## Basic Usages

For the `testbench` command to understand any required service providers, bootstrappers, environment variables or other options to be used when executing the "artisan" command you need to add the following `testbench.yaml` file on the project root directory.

```yaml
providers:
  - Hypervel\Package\PackageServiceProvider

dont-discover:
  - hypervel/sanctum
```

### Hypervel Skeleton

You can use `hypervel` configuration key to set a custom location instead of using the default `vendor/hypervel/testbench/workbench`:

```yaml
hypervel: ./workbench
```

To publish the skeleton folder, you can run the following command:

```shell:no-line-numbers
vendor/bin/testbench-sync workbench
```

### Service Providers

You can use `providers` configuration key to set an array of service providers to be loaded additionally:

```yaml
providers:
  - Hypervel\Sanctum\SanctumServiceProvider
  - Workbench\App\Providers\WorkbenchServiceProvider
```

### Exclude Packages

To exclude specific packages, you can also use `dont-discover` to exclude specific packages from being loaded:

```yaml
dont-discover:
  - hypervel/html
```

### Environment Variables

You can use `env` configuration key to set an array of environment variables to be loaded:

```yaml
env:
  - SEND_QUERIES_TO_RAY=(false)
```

::: warning
The `env` environment variables are only applied when using the CLI and will not be used when running tests.
:::

#### Workbench Discovery Configuration

The `discovers` configuration allows Workbench to discover routes and commands.

 Name            | Type          | Description
:----------------|:--------------|:--------------------
 `web`           | `bool`        | Enabling the options allows Workbench to register routes from `workbench/routes/web.php`
 `api`           | `bool`        | Enabling the options allows Workbench to register routes from `workbench/routes/api.php`
 `commands`           | `bool`        | Enabling the options allows Workbench to register console commands from `workbench/routes/console.php`

#### Example

```yaml
workbench:
  discovers:
    web: true
    api: false
    commands: false
```

## Getting Started

To use Testbench Component, all you need to do is extend `Hypervel\Testbench\TestCase` instead of `PHPUnit\Framework\TestCase`. The fixture app booted by `Hypervel\Testbench\TestCase` is predefined to follow the base application skeleton of Hypervel.

```php
class TestCase extends \Hypervel\Testbench\TestCase
{
    //
}
```

### PHPUnit Configuration

You can separate your tests in your `phpunit.xml` file by providing different test suites, allowing you to run your Feature tests on demand.

For example:

```yaml
<testsuites>
    <testsuite name="Feature">
        <directory suffix="Test.php">./tests/Feature</directory>
    </testsuite>
    <testsuite name="Unit">
        <directory suffix="Test.php">./tests/Unit</directory>
    </testsuite>
</testsuites>
```

Run only your feature tests by running PHPUnit with the `--testsuite=Feature` option.

```shell:no-line-numbers
vendor/bin/phpunit --testsuite=Feature
```
