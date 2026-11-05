# Static Analysis

- [Introduction](#introduction)
- [Installation](#installation)
    - [Analyzing Packages](#analyzing-packages)
    - [Runtime Requirements](#runtime-requirements)
- [What the Analysis Knows](#what-the-analysis-knows)
    - [Collection Types](#collection-types)
- [Fixing and Ignoring Diagnostics](#fixing-and-ignoring-diagnostics)
    - [Environment Variables](#environment-variables)
    - [Configuration Values](#configuration-values)
    - [Array Keys](#array-keys)
    - [Value and With Helpers](#value-and-with-helpers)
    - [Converting Collections to Arrays](#converting-collections-to-arrays)
    - [Translations](#translations)
    - [Views](#views)
    - [Console Input](#console-input)
    - [Authentication in Requests](#authentication-in-requests)
    - [Deprecations](#deprecations)

<a name="introduction"></a>
## Introduction

Hypervel's `hypervel/phpstan` package is a [PHPStan](https://phpstan.org) extension that understands your Hypervel application. It is a port of [Larastan](https://github.com/larastan/larastan): it boots your application so PHPStan knows its container services, configuration, authentication guards, console commands, views and translations, and it adds rules that catch common mistakes in Hypervel code.

<a name="installation"></a>
## Installation

To get started, install the package as a development dependency:

```shell
composer require hypervel/phpstan --dev
```

Next, include the extension and the bundled deprecation rules in your `phpstan.neon` file:

```neon
includes:
    - vendor/hypervel/phpstan/extension.neon
    - vendor/phpstan/phpstan-deprecation-rules/rules.neon

parameters:
    level: 5
    paths:
        - app
```

If your project uses the [PHPStan extension installer](https://github.com/phpstan/extension-installer), it loads both extensions automatically, so you should omit the `includes` entries. Once configured, you may analyze your application:

```shell
./vendor/bin/phpstan analyse
```

<a name="analyzing-packages"></a>
### Analyzing Packages

When you analyze a package instead of an application, PHPStan boots the package's Testbench application, so the package should require `hypervel/testbench` as a development dependency. The analysis uses the providers, configuration and package discovery settings from your `testbench.yaml` file, just like your package's tests. Authentication types come from the Testbench application's configuration, where the `AUTH_MODEL` environment variable or the workbench `User` model selects the user model.

<a name="runtime-requirements"></a>
### Runtime Requirements

Since PHPStan boots your application, the PHP CLI that runs it needs the same Swoole setup as your application: Swoole (not OpenSwoole) built with OpenSSL and native cURL support, with `swoole.use_shortname` set to `Off`. If the application cannot boot, PHPStan stops and displays the exception that prevented it.

<a name="what-the-analysis-knows"></a>
## What the Analysis Knows

Configuration types come from your configuration files themselves, never from the values on your machine. For example, the following `enabled` option is a `bool` no matter what your `.env` file contains:

```php
// config/feature.php
return ['enabled' => (bool) env('FEATURE_ENABLED', false)];

// Inferred as bool...
$enabled = config('feature.enabled');
```

The facts your application registers come from the booted application: container bindings, authentication guards and user models, console commands, views and translations, including the default locale. Your environment can influence these facts. For instance, `app('db.connection')` is typed as the connection class your `DB_CONNECTION` variable selects, and the authentication user model may come from `AUTH_MODEL`. So, you should run the analysis with the same environment locally and in CI. When you analyze a package, the analysis sees the Testbench application's registrations, not those of every application that may install the package.

A value typed as an interface only has the interface's methods, even when the container binds a class with more of them. When your code needs a specific implementation, type it as that implementation. Resolving a service from the container, such as `app(Repository::class)`, is typed as the class the booted application binds.

Dates are different: `now()`, `today()` and the `Date` facade return `CarbonInterface`, as they are declared, even when your application configures a specific date class. When your code needs a specific class, convert the value, such as with `toImmutable()`.

Some facts cannot be known statically. When the analysis cannot tell what a configuration key holds, such as a value that provider code writes in a way the analysis does not recognize and that did not run while your application booted, it uses the declared return type instead of guessing.

Calling `str()` without arguments returns an object that passes method calls to `Str`. The analysis does not check those calls.

<a name="collection-types"></a>
### Collection Types

Collections keep Laravel's declared types, so a collection of users may be passed where a collection of models is expected. Writes through the wider reference are checked against its model type, so the analysis can miss a value that violates the original collection's narrower user type.

If you enable PHPStan's [bleeding edge](https://phpstan.org/blog/what-is-bleeding-edge) mode, PHPStan keeps the value type it infers for a new collection, so adding or searching for a value of another type is reported:

```php
$ids = collect([1, 2, 3]);

$ids->push(4); // Reported with bleeding edge...
```

Declare the type the collection is meant to hold when you create it:

```php
/** @var Collection<int, int> $ids */
$ids = collect([1, 2, 3]);
```

<a name="fixing-and-ignoring-diagnostics"></a>
## Fixing and Ignoring Diagnostics

Whenever possible, fix the code a diagnostic points to. Ignore a diagnostic only for a legitimate exception or a limit of the analysis, and give the ignore the diagnostic's identifier and a reason:

```php
// @phpstan-ignore hypervel.noEnvCallsOutsideOfConfig (the test runner sets this token for each worker)
$token = Env::get('TEST_TOKEN');
```

Avoid `@phpstan-ignore-line` and `@phpstan-ignore-next-line`, which hide every error on the line, and always give a reason, so the next reader knows why the diagnostic does not apply. Never add a real defect to a baseline.

The following table summarizes the preferred pattern for each of Hypervel's diagnostics:

| Topic | Preferred Pattern | Identifiers |
|---|---|---|
| [Environment Variables](#environment-variables) | Read the environment in configuration files and use `config()` elsewhere. | `hypervel.noEnvCallsOutsideOfConfig` |
| [Configuration Values](#configuration-values) | Use the typed getter that matches the configuration value. | `hypervel.configType`, `hypervel.configCollection` |
| [Array Keys](#array-keys) | Read keys the array has, or pass a default for optional keys. | `offsetAccess.notFound` |
| [Value and With Helpers](#value-and-with-helpers) | Use the value directly when no closure or callback runs. | `hypervel.uselessConstructs.value`, `hypervel.uselessConstructs.with` |
| [Converting Collections to Arrays](#converting-collections-to-arrays) | Use `all()` when no items need converting. | `hypervel.unnecessaryEnumerableToArrayCall` |
| [Translations](#translations) | Use translation keys that exist. | `hypervel.missingTranslations` |
| [Views](#views) | Use view names the application can find. | `hypervel.unusedViews`, `argument.type` for view names |
| [Console Input](#console-input) | Read the arguments and options the command defines. | `hypervel.console.undefinedArgument`, `hypervel.console.undefinedOption` |
| [Authentication in Requests](#authentication-in-requests) | Use `$request->user()` where a request is available. | `hypervel.noAuthFacadeInRequestScope`, `hypervel.noAuthHelperInRequestScope` |
| [Deprecations](#deprecations) | Use the replacement the deprecation names. | `method.deprecated` and the other deprecation identifiers |

<a name="environment-variables"></a>
### Environment Variables

You should only read environment variables in your configuration files. Once your configuration is cached, the `.env` file is no longer loaded, so a read anywhere else only sees the process environment. Instead, read the variable in a configuration file and use `config()` elsewhere:

```php
// config/services.php
'billing' => [
    'key' => env('BILLING_KEY'),
],

// Elsewhere in your application...
$key = config('services.billing.key');
```

The rule checks calls to the `env`, `env_array`, `env_or_fail` and `app_id` helpers and to the `Env::get`, `Env::getArray` and `Env::getOrFail` methods. Calls within your configuration directories, the framework's configuration files and the configuration files that service providers merge or publish are allowed. Only direct calls are checked: callables, dynamic class names, `getenv()` and `$_ENV` are not.

Some infrastructure code genuinely needs the process environment, such as code that runs before configuration loads, values passed to child processes, or settings provided by a test runner. Ignore those calls individually, with a reason. You may disable the rule using the `noEnvCallsOutsideOfConfig` parameter.

<a name="configuration-values"></a>
### Configuration Values

The typed configuration getters, such as `Config::string()` and `config()->integer()`, throw an exception when the value has a different type. The analysis reports a typed getter, or a `collection()` call, whose key can never hold the required type:

```php
// config/queue.php
'retries' => '3',

// Config key 'queue.retries' is not an integer, so integer() throws...
$retries = Config::integer('queue.retries');
```

Correct the configuration value, casting environment variables within the configuration file, or use the getter that matches the value's type. Reserve `get()` for values that are intentionally nullable, a union of types, or unrestricted:

```php
'retries' => (int) env('QUEUE_RETRIES', 3),
```

Only values the analysis knows are checked. A key the analysis cannot follow keeps the getter's declared type.

Configuration types include the defaults of any package whose configuration your application has not published. If a package supports configuration that is wider than its shipped default, its code should declare the contract it accepts where it reads the value, rather than relying on the type of the default:

```php
/** @var list<string> $allowedOrigins */
$allowedOrigins = config()->array('mcp.allowed_origins');
```

Similarly, when a package ships a `null` default that applications may replace, a typed getter reached only after a `null` check can still be reported, since the analysis reads the configuration key rather than your local value. Ignore that call with a reason. These checks may be disabled using the `checkConfigTypes` parameter.

<a name="array-keys"></a>
### Array Keys

Calls to `Arr::get()` and `Arr::pull()` are checked against the keys the array is known to have, following keys in "dot" notation through nested arrays. A key the array never has is reported, since the call always returns `null`:

```php
/**
 * @param array{name: string, email: string} $user
 */
function notify(array $user): void
{
    $email = Arr::get($user, 'mail'); // Offset 'mail' does not exist...
}
```

Correct the key. When a key is optional by design, pass the default you expect, since calls with a default are not reported:

```php
$timeout = Arr::get($options, 'timeout', 30);
```

Arrays whose keys are not known, such as `array<string, mixed>`, are not checked.

<a name="value-and-with-helpers"></a>
### Value and With Helpers

The `value` helper only calls a closure, and the `with` helper only calls its callback, so a call that passes neither returns the value unchanged and is reported:

```php
$name = value($user->name); // Reported...
$name = with($user->name); // Reported...

$name = $user->name;
```

Use the value directly. When the value may be a closure, such as a default that callers may compute lazily, keep the `value` call; the analysis does not report it.

<a name="converting-collections-to-arrays"></a>
### Converting Collections to Arrays

A collection's `toArray()` method converts each `Arrayable` item to an array as well, while `all()` returns the items as they are. When no item is `Arrayable`, the two return the same array, so the analysis recommends `all()`:

```php
// Called [toArray()] on an Enumerable which does not contain any Arrayables...
$ids = collect([1, 2, 3])->toArray();

$ids = collect([1, 2, 3])->all();
```

Keep `toArray()` when the items are, or may be, `Arrayable`, such as models. Collections that override `toArray()`, `all()` or `map()` are not checked. You may disable the rule using the `noUnnecessaryEnumerableToArrayCalls` parameter.

<a name="translations"></a>
### Translations

The `__`, `trans` and `trans_choice` helpers, the `Lang` facade and the translator are checked for keys that are missing from a translation file the application can load. Only literal `group.item` and `namespace::group.item` keys are checked, and only when the group's file exists for the requested locale or its fallback. When a lookup does not specify a locale, the application's default locale is used. JSON translations, dynamic keys and custom translators or loaders are not checked.

To fix the diagnostic, add the missing line or correct the key. You may disable the rule using the `checkMissingTranslations` parameter.

<a name="views"></a>
### Views

Methods that accept a view name, such as `view()` and `View::make()`, require a view the booted application can find, so a misspelled view name is reported as an `argument.type` error. If a package only registers a view namespace when a command runs or a feature is enabled, the analysis cannot see those views; ignore those calls with a reason.

You may also enable the `checkUnusedViews` parameter to report views that no code refers to by a literal name. Views rendered by computed names or used by other packages may still be in use, so treat these diagnostics as a list to review rather than proof that a view can be deleted.

<a name="console-input"></a>
### Console Input

Calls to a command's `argument()` and `option()` methods are checked against the command's definition. Define the argument or option in the command's signature, or check `hasArgument()` or `hasOption()` before reading an input the command may not define:

```php
if ($this->hasOption('force') && $this->option('force')) {
    // ...
}
```

<a name="authentication-in-requests"></a>
### Authentication in Requests

When the `checkAuthCallsWhenInRequestScope` parameter is enabled, the analysis recommends `$request->user()` over `Auth::user()` and `auth()->user()` where a request is available. The facade and helper remain fully supported. Since a request's user resolver may return a different user than the `Auth` facade, review each change instead of replacing calls mechanically.

<a name="deprecations"></a>
### Deprecations

The bundled deprecation rules report uses of deprecated APIs from any of your dependencies, including PHP, Symfony and Carbon as well as Hypervel. Use the replacement the deprecation names. When you still need the deprecated API, such as to keep a public API compatible, ignore it with the reported identifier and a reason.

Deprecated methods and properties reached through a proxy, such as `$users->each->notify()`, `optional($user)->notify()` or `$user->when($active)->notify()`, are not reported, since PHPStan's deprecation checks only look at the proxy class itself.

To disable deprecation reporting, remove the `rules.neon` include. If you use the extension installer, add `phpstan/phpstan-deprecation-rules` to the `extra.phpstan/extension-installer.ignore` list in your `composer.json` file instead.
