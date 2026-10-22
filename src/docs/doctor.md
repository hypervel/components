# Doctor

- [Introduction](#introduction)
- [Installation](#installation)
- [Running Doctor](#running-doctor)
- [Diagnostic Statuses](#diagnostic-statuses)
- [Selecting Diagnostics](#selecting-diagnostics)
- [Configuring Doctor](#configuring-doctor)
- [Environment Modes](#environment-modes)
- [Default Diagnostics](#default-diagnostics)
- [Creating Diagnostics](#creating-diagnostics)
    - [Diagnostic Helpers](#diagnostic-helpers)
- [Registering Diagnostics](#registering-diagnostics)
- [Running Doctor Programmatically](#running-doctor-programmatically)
- [Output Formats](#output-formats)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Hypervel Doctor diagnoses common configuration, environment, and infrastructure problems in your application.

Each diagnostic is a single check. It inspects one thing, such as whether Hypervel can write to its storage directories, and reports one of several statuses. A diagnostic may also offer a fix when the repair is safe and deterministic. Other issues are reported with remediation steps instead.

<a name="installation"></a>
## Installation

Install Hypervel Doctor using Composer:

```bash
composer require hypervel/doctor --dev
```

If you want to run Doctor in deployments that omit development dependencies, install it without `--dev`.

<a name="running-doctor"></a>
## Running Doctor

Once installed, the package registers the `doctor` Artisan command:

```bash
php artisan doctor
```

When a failing diagnostic can be fixed, Doctor reports the problem and prompts before applying the fix:

```text
Storage is writable: The application cannot write to every required storage directory.

 Make the storage directories writable? (yes/no) [yes]
```

Some fixes offer a choice of repairs instead of a yes/no confirmation. When the default cache store is unreachable on a developer machine, for example, Doctor checks the configured `file`, `database`, and `redis` alternatives. It offers usable stores and eligible database stores whose default cache tables can be created. Declining the switch leaves the failure in the report with its remediation text. The database creation option also runs all pending migrations, as its label explains.

To run available fixes without prompting, pass the `--fix` option:

```bash
php artisan doctor --fix
```

First-party fixes cover deterministic local repairs such as creating a missing `.env`, generating `APP_KEY`, disabling production debug mode, adding `.env` to `.gitignore`, creating the public storage link, and repairing writable storage directories. Because `--fix` never prompts, it applies every fix that does not require a choice; fixes that need one are reported as ordinary failures with their remediation text.

Environment-file checks use the file selected by your application. A missing environment file is skipped in production, where deployment variables may be supplied externally. Doctor does not untrack files from Git or replace conflicting storage links automatically.

Run repairs as maintenance operations without concurrent application traffic. Repairs may change deployment files, run migrations, or update process-wide configuration. After changing deployment settings, run `php artisan server:reload` or restart the server so running workers use the new settings.

> [!NOTE]
> Fixes are only available with the CLI and agent [output formats](#output-formats). With agent output, `--fix` applies every available deterministic fix and reports the outcomes in the payload's `fixes` array. Doctor rejects `--fix` with the JSON and GitHub report formats so machine-readable reports never mutate the application.

<a name="diagnostic-statuses"></a>
## Diagnostic Statuses

Every diagnostic returns one of the following statuses. Doctor uses them to render output and to determine the command's exit code:

| Status   | Meaning                                                 | Affects exit code          |
| -------- | ------------------------------------------------------- | -------------------------- |
| `pass`   | The check succeeded and nothing is wrong.               | No                         |
| `notice` | Informational context worth surfacing to the developer. | No                         |
| `warn`   | A potential problem that may not require action.        | Only with `--fail-on=warn` |
| `fail`   | The check found a problem that should be resolved.      | Yes                        |
| `skip`   | The check did not apply to the current environment.     | No                         |
| `error`  | The diagnostic threw an exception while running.        | Yes                        |

By default, the command exits with a failing status when a diagnostic fails or errors. Use `--fail-on=warn` to also fail on warnings, or `--fail-on=never` when Doctor should only report issues.

<a name="bailing-on-failure"></a>
### Bailing on Failure

Use `--bail` to stop after the first diagnostic that fails or errors. Warnings, notices, passes, and skips do not stop the run:

```bash
php artisan doctor --bail
```

The flag works with every output format and may be combined with diagnostic selectors. Programmatic runs may enable the same behavior with `Doctor::bail()->run()`.

<a name="selecting-diagnostics"></a>
## Selecting Diagnostics

Diagnostics may be selected or excluded by class name, group, package, or package wildcard. Multiple values may be passed either by repeating the option or by separating values with commas.

```bash
php artisan doctor --only=storage

php artisan doctor --only=StorageIsWritable

php artisan doctor --only=vendor/package

php artisan doctor --except=hypervel/*

php artisan doctor --except=hypervel/doctor

php artisan doctor --except=storage
```

<a name="configuring-doctor"></a>
## Configuring Doctor

To configure persistent diagnostic selection and environment modes, publish Doctor's configuration file:

```bash
php artisan vendor:publish --tag=doctor-config
```

The `only` and `except` options accept the same selectors as the command line: diagnostic class names, groups, packages, or package wildcards.

```php
'only' => [
    // 'hypervel/doctor',
    // 'vendor/*',
    // 'security',
],

'except' => [
    // \Hypervel\Doctor\Diagnostics\EnvironmentFileIsGitIgnored::class,
],
```

Configured `only` selectors act as a persistent allowlist. Passing `--only` at runtime narrows that allowlist further, while configured `except` selectors and `--except` are combined.

<a name="environment-modes"></a>
## Environment Modes

Some facts about an application depend on its environment. A `sync` queue connection is a sensible default on a developer's machine, but in production it means queued jobs run inside web requests. Uncached bootstrap files are normal while code is changing; in production, caching them can improve startup. Debug mode is useful locally and a security problem on a public server.

To judge these diagnostics, Doctor resolves the application to one of two modes:

| Mode         | Expectations                                                                                                                                |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `local`      | The application is under active development. Debug mode, `sync` queues, and uncached bootstrap files are normal.                              |
| `production` | The application serves real traffic. Debug mode is a security risk, queues should run asynchronously, and bootstrap files should be cached.   |

Missing bootstrap caches warn in production but pass locally. A partial production cache produces a notice listing the cached and uncached types. Complete caches pass in production but produce a notice locally, since a stale cache is a common reason recent changes do not appear during development. These checks cover configuration, routes, and events; compiled views are created on demand and do not indicate whether the deployment is optimized.

Doctor recognizes Hypervel's conventional `local`, `production`, and `staging` environment names out of the box. If your application uses other names, group each one under the appropriate Doctor mode in the configuration file:

```php
'environments' => [
    'local' => ['local', 'dev'],
    'production' => ['production', 'staging', 'qa'],
],
```

Any environment not listed is treated as `production`, so unfamiliar environments are held to the strictest expectations rather than quietly excused. An unsupported mode name, an environment list that is not an array, or an environment assigned to both modes throws an exception.

Custom diagnostics may branch on the current mode. Here, a `sync` queue connection passes on a developer's machine but warns in production:

```php
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;

public function check(): DiagnosticResult
{
    $connection = config()->string('queue.default');

    if (config("queue.connections.{$connection}.driver") !== 'sync') {
        return $this->skip('not-sync');
    }

    if (EnvironmentMode::current()->isProduction()) {
        return $this->warn('sync-in-production');
    }

    return $this->pass('sync-local');
}
```

The `pass` and `warn` methods build results from the diagnostic's named messages, which are covered in [Creating Diagnostics](#creating-diagnostics).

<a name="default-diagnostics"></a>
## Default Diagnostics

Doctor ships with a focused suite of diagnostics that cover common configuration, environment, dependency, database, queue, and storage problems. The default suite includes:

- **Environment** — the selected environment file, `APP_KEY`, PHP version constraints, and required and recommended PHP extensions.
- **Composer** — installed dependencies, optimized autoload validation, lock-file consistency, and local repairs for eligible dependency problems.
- **Configuration** — configuration files can be loaded and cached, configuration values required by the active drivers are set, and bootstrap cache files are reported when their presence does not match the current environment.
- **Database** — the default connection is reachable, including its selectable read and write records. Explicit `::read` or `::write` defaults check that role. Doctor also checks the selected SQLite database file and can apply pending migrations automatically in local environments.
- **Cache, queue, scheduler, and session** — selected drivers and active Redis data and lock connections are reachable, degraded failover capacity is reported, `sync` queues are flagged outside local environments, and scheduled tasks prompt advice to keep `schedule:run` running. Background and deferred queues run in coroutines and need no external worker; null queues intentionally discard jobs.
- **Storage** — the default filesystem disk is reachable, required directories are writable, and the `storage:link` symlink exists when expected.
- **Security** — debug mode matches the environment, `.env` is ignored, and Composer dependencies pass the configured audit policy, including vulnerability, abandoned-package, and filter-list checks.

Platform checks read the root Composer manifest and installed package metadata. This includes dependency extension constraints, such as the framework's Swoole requirement, without keeping a second version list. Compatible Composer providers and replacements can satisfy extension requirements. Root development requirements apply only when the installed metadata records a development installation. Checks inspect the PHP process running Doctor, not Composer's emulated platform.

Redis feature checks use the framework's shared Redis requirements. Higher client and server requirements apply only when active cache stores use `any` tag mode or Redis sessions enable user session tracking. Ordinary Redis use requires only the base client version; separate lock connections do not inherit hash-field requirements. Server version inspection on a cluster checks its default node.

SQS and Beanstalkd reachability checks read queue sizes without publishing jobs. The SQS check requires the `sqs:GetQueueAttributes` permission.

Doctor runs after application startup. Startup guards still reject unsupported runtime settings and invalid application timezones before the command can run. Doctor does not bypass those guards.

<a name="creating-diagnostics"></a>
## Creating Diagnostics

A diagnostic is a single class that, like an Artisan command, can declare its definition as properties. Extend `Hypervel\Doctor\Diagnostic` and implement a `check` method that returns a `DiagnosticResult`. When `$name` or `$group` are not set, Doctor derives defaults from the class name.

Bundled diagnostics assume the standard behavior of built-in driver names. If your application replaces that behavior, select custom diagnostics for those services.

The `make:diagnostic` Artisan command scaffolds a diagnostic into `app/Doctor/Diagnostics`. The command asks whether the diagnostic should offer a fix, or accepts the `--fixable` option directly:

```bash
php artisan make:diagnostic HorizonIsRunning --fixable
```

To offer a fix, implement the `Hypervel\Doctor\Contracts\Fixable` contract and mark each repairable failure with `->fixable()`. Doctor only attempts fixes for explicitly fixable `fail` results from diagnostics that implement the contract. Other failures and unexpected `error` results are never fixed automatically. Pass one or more `EnvironmentMode` values to limit where an automatic fix may run, such as `->fixable(EnvironmentMode::Local)` for a developer-machine-only repair.

The following diagnostic checks whether the application's report directory exists and offers to create it in local environments:

```php
<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Message;
use Hypervel\Support\Facades\File;

class ReportDirectoryExists extends Diagnostic implements Fixable
{
    public string $name = 'Report directory exists';

    public string $group = 'filesystem';

    /**
     * Get the diagnostic's named message definitions.
     */
    protected function messages(): array
    {
        return [
            'exists' => 'The report directory exists.',

            'missing' => Message::make(
                summary: 'The report directory is missing.',
                remediation: 'Create the app/reports directory within application storage.',
                confirmation: 'Create the report directory?',
            ),

            'created' => 'The report directory was created.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (File::isDirectory(storage_path('app/reports'))) {
            return $this->pass('exists');
        }

        return $this->fail('missing')->fixable(EnvironmentMode::Local);
    }

    /**
     * Create the report directory.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        File::ensureDirectoryExists(storage_path('app/reports'));

        return $this->fixed('created');
    }
}
```

`ensureDirectoryExists` throws if the directory cannot be created. Doctor reports unexpected exceptions from a repair as an error result.

Diagnostics should explain what failed and how to recover. Copy lives in the `messages()` registry: a plain string is used as the result's summary, while `Message::make()` may also provide remediation text, documentation links, or a confirmation prompt for fixes.

Use `Link::docs()` for Hypervel documentation and `Link::to()` for other destinations. Documentation links use the same versionless page and section URLs in every output format:

```php
Message::make(
    summary: 'Queued jobs run synchronously in production.',
    remediation: 'Configure a background queue driver.',
)->link(Link::docs('queues', 'connections-vs-queues'));
```

Statuses are declared where the decision is made. Return `$this->pass()`, `$this->fail()`, `$this->warn()`, `$this->notice()`, `$this->skip()`, or `$this->error()` from `check()`, and `$this->fixed()` or `$this->fixFailed()` from `fix()`. Each result also receives a stable machine-readable code derived from the class and message names, such as `report-directory-exists.missing`.

Summaries and remediation text may interpolate values using `{placeholder}` tokens supplied at the return site:

```php
'unsatisfied' => Message::make(
    summary: 'PHP {version} does not satisfy [{constraint}].',
    remediation: 'Use a PHP binary that satisfies the composer.json PHP constraint.',
),
```

```php
return $this->fail('unsatisfied', [
    'version' => PHP_VERSION,
    'constraint' => $constraint,
]);
```

Reserve tokens for short identifying values such as versions, paths, and counts. Attach unbounded evidence such as exception messages, process output, or lists of failures with `withDetails()` instead.

If the check gathers state the fix will need, store it with `withContext()` on the result. Mark only outcomes the fix can handle with `fixable()`, and only implement `Fixable` when the repair is predictable and safe. Scope a fix to `EnvironmentMode::Local` to limit fixes to a development environment.

When a failure has several valid repairs and the right one is a human decision, declare the choices with `fixOptions()` after `fixable()`. The interactive CLI renders them as a select list using the result's confirmation text as the prompt label, and the chosen option value is passed to `fix()`:

```php
return $this->fail('unreachable')
    ->fixable(EnvironmentMode::Local)
    ->fixOptions(['file' => 'File', 'redis' => 'Redis']);
```

```php
public function fix(DiagnosticResult $result, ?string $option = null): FixResult
{
    // $option is one of the declared option values...
}
```

Compute the options in `check()` and filter them to choices that will actually succeed — probe candidate services, verify client packages, and drop anything that would fail — so the select list only offers working repairs. A result with no viable options should stay non-fixable and rely on its remediation text.

Every select list ends with an entry that declines the fix, `Skip — leave unfixed` by default. Pass a `decline` label when naming the current selection reads better, such as `Keep Redis (repair it manually)` — choosing it applies nothing, so the failure renders with its remediation:

```php
->fixOptions(['file' => 'File'], decline: 'Keep Redis (repair it manually)');
```

<a name="diagnostic-helpers"></a>
### Diagnostic Helpers

Many applications and packages end up writing the same kinds of checks, so Doctor provides helpers in the `Hypervel\Doctor\Support` namespace for the recurring mechanics. Reach for these before re-implementing the logic inside a diagnostic.

#### Configuration Values

The `Configured` helper reads configuration defensively. Diagnostics must be able to inspect misconfigured applications without failing before they can report, so these methods never throw on unexpected types the way the config repository's typed accessors can.

`Configured::string()` returns a configured string, treating empty or non-string values as missing:

```php
use Hypervel\Doctor\Support\Configured;

$connection = Configured::string('queue.default', 'database');
```

`Configured::missing()` returns the given keys that do not hold a usable value. Null values, blank strings, and empty arrays are treated as missing, so a "package requires these configuration values" diagnostic reduces to:

```php
public function check(): DiagnosticResult
{
    $missing = Configured::missing([
        'services.stripe.key',
        'services.stripe.secret',
    ]);

    if ($missing === []) {
        return $this->pass('set');
    }

    return $this->fail('missing')->withDetails(Details::bullets($missing));
}
```

#### Active Drivers

Default configuration values often point at wrapper drivers: the default log channel may be a `stack`, and a default mailer may be a `failover` or `roundrobin` transport. The `ActiveDrivers` helper finds the concrete services in use:

```php
use Hypervel\Doctor\Support\ActiveDrivers;

$channels = ActiveDrivers::logChannels(Configured::string('logging.default', 'stack'));

$mailers = ActiveDrivers::mailers(Configured::string('mail.default', 'log'));

$stores = ActiveDrivers::cacheStores(config()->string('cache.default'));

$connections = ActiveDrivers::queueConnections(config()->string('queue.default'));

$redis = ActiveDrivers::redisConnections();
```

The methods follow nested wrappers and return unique names in configuration order. Cache and queue discovery reports circular definitions as errors. Logging and mail discovery stops at repeated names.

#### Result Details

The `Details` helper formats the evidence attached to results with `withDetails()`. `Details::bullets()` renders a list of strings as bullets, `Details::failures()` renders keyed failure messages, and `Details::processOutput()` selects the most useful stream from a finished process, preferring error output and falling back when both streams are empty:

```php
use Hypervel\Doctor\Support\Details;

Details::bullets(['services.stripe.key', 'services.stripe.secret']);

Details::failures(['media' => 'The disk root is not writable.']);

Details::processOutput($process->output(), $process->errorOutput(), 'The command produced no output.');
```

<a name="registering-diagnostics"></a>
## Registering Diagnostics

Applications may register diagnostics through the Doctor facade, typically from a service provider:

```php
use App\Doctor\Diagnostics\ReportDirectoryExists;
use Hypervel\Doctor\Facades\Doctor;

public function boot(): void
{
    Doctor::diagnostic(ReportDirectoryExists::class);
}
```

Packages use the same registration API from their service providers:

```php
use Hypervel\Doctor\Facades\Doctor;
use Vendor\Package\Diagnostics\HorizonIsRunning;

public function boot(): void
{
    Doctor::diagnostic(HorizonIsRunning::class);
}
```

Reports show each diagnostic's source next to its name. The source is the Composer package that provides the diagnostic:

```text
[fail] Storage is writable (hypervel/doctor): The application cannot write to every required storage directory.
[pass] SQLite database exists (acme/application): The SQLite database file exists.
[warn] Horizon is running (hypervel/horizon): Horizon is not currently running.
```

Register diagnostics during provider boot. The registry is shared for the worker lifetime. Doctor resolves a fresh instance for each diagnostic invocation unless the application explicitly binds that diagnostic with another lifetime. Keep health results local to the invocation instead of storing them in static properties.

<a name="running-doctor-programmatically"></a>
## Running Doctor Programmatically

Doctor may also be run without the Artisan command. The `run` method runs the registered diagnostics and returns a `DiagnosticReport`:

```php
use Hypervel\Doctor\Facades\Doctor;

$report = Doctor::run();

if ($report->hasFailures()) {
    // ...
}
```

Programmatic runs honor the `only` and `except` selectors from Doctor's configuration file. To narrow a run further, constrain it with `only` and `except` before calling `run`. Selectors may reference a diagnostic class, class basename, group, or package name — the same values accepted by the command's `--only` and `--except` options:

```php
$report = Doctor::only('security')
    ->except(SomeDiagnostic::class)
    ->run();
```

Repeated `only` calls intersect, so each call narrows the run within the previous constraints.

To apply fixes as part of a run, configure the run with `fixUsing`. The callback receives each failing diagnostic that offers a fix and decides what to do: return `false` to skip the fix, `true` to apply a plain fix, or one of the result's fix option values to apply the fix with that choice. When any fixes are applied, Doctor re-runs the diagnostics so the report reflects the repaired application:

```php
$report = Doctor::fixUsing(
    fn ($outcome) => $outcome->fixRequiresOption() ? false : true,
)->run();

$report->fixes();
```

Returning `true` for an outcome whose result declares fix options throws a `LogicException` — a choice-based fix never runs without a choice.

Use programmatic repairs only during maintenance without concurrent traffic, just as with `doctor --fix`. Coroutine cancellation propagates through checks and repairs instead of becoming a diagnostic failure.

<a name="output-formats"></a>
## Output Formats

Doctor renders readable CLI output by default. JSON and GitHub Actions annotations are also available:

```bash
php artisan doctor --format=json

php artisan doctor --format=github
```

### Agent-Optimized Output

When Doctor detects that it is running inside an AI coding agent such as Claude Code or Cursor — using [Laravel Agent Detector](https://github.com/laravel/agent-detector) — it defaults to an agent-optimized format instead of CLI output. The format follows the [Laravel PAO](https://github.com/laravel/pao) convention: a single line of JSON with aggregate counts up front and only actionable outcomes itemized, letting agents use Doctor as a minimal, parseable completion gate:

```json
{"tool":"doctor","result":"failed","diagnostics":27,"failed":1,"warnings":0,"notices":0,"passed":20,"skipped":6,"issues":[{"name":".env file exists","status":"fail","summary":"The selected environment file [/var/www/app/.env] does not exist.","fix":"Copy [/var/www/app/.env.example] to [/var/www/app/.env], then review the copied values.","fixable":true,"links":{"Configuration documentation":"https://hypervel.org/docs/configuration#environment-configuration"}}]}
```

Issues marked `fixable` may be fixed by re-running Doctor with `--fix`, which applies every available deterministic fix, re-runs the diagnostics, and appends the fix outcomes to the payload. Issues that carry an `options` map instead of the `fixable` flag require a choice `--fix` will not make: the agent should apply the change itself following the issue's `fix` remediation, or escalate the shortlist of options to a human.

An explicit `--format` option always overrides detection, and the agent format may also be requested directly:

```bash
php artisan doctor --format=cli

php artisan doctor --format=agent
```

To preview agent output outside an agent, set the `AI_AGENT` environment variable: `AI_AGENT=test php artisan doctor`.

<a name="credits"></a>
## Credits

Hypervel Doctor is ported from [Laravel Doctor](https://github.com/laravel/doctor).
