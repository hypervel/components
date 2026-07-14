# Development Command Orchestration

Add Laravel-shaped `dev` and `dev:list` commands to Hypervel while adapting process ownership, package-manager detection, and shutdown behavior for a long-running Swoole development environment.

The public API stays aligned with Laravel. Hypervel-specific behavior is limited to places where the runtime or package layout requires it: Watcher owns the development server, Composer path repositories must be classified correctly, package-manager lockfiles may live above the application directory, and child processes must be stopped when Watcher receives a termination signal.

## Background

Laravel provides a registry of development processes and two console commands:

- `dev` renders the effective process list and launches it through `concurrently`.
- `dev:list` shows registered processes, their source, and their registration priority.

The registry allows framework defaults, package registrations, and application registrations to share process names. Higher-priority registrations replace lower-priority registrations, while `only()` and `except()` filters select the effective process list.

Hypervel needs the same application-facing API, but the default server process cannot use Laravel's request-per-process `serve` assumptions. Hypervel Watcher owns the Swoole server process and restarts it when source files change. The supported default process chain is therefore:

```text
php artisan dev
  -> php artisan watch
    -> php artisan serve
```

Hypervel also has no Pail-equivalent command, so the default process list contains the server, queue listener, and frontend development server without a log-tail process.

## Design

### Development process registry

`Hypervel\Foundation\DevCommands` is the process-wide registry. It stores command definitions, filters, color assignment state, and the lazily detected Node package manager.

The framework registers these defaults during console boot:

```text
server  php artisan watch
queue   php artisan queue:listen --tries=1 --timeout=0
vite    <package-manager> run dev
```

Applications and packages can register processes through the Laravel-compatible methods:

- `DevCommands::register()` for arbitrary shell commands.
- `DevCommands::artisan()` for Artisan commands.
- `DevCommands::node()` for package scripts.
- `DevCommands::nodeExec()` for installed Node binaries.
- `DevCommands::only()` and `DevCommands::except()` for boot-time filtering.

The registry is static boot-time state. Its public mutators document that registrations and filters persist for the worker lifetime. `flushState()` resets every static field, and the PHPUnit after-each subscriber invokes it between tests.

### Registration priority

Development process names may be registered by framework defaults, Composer dependencies, or the application. Registration priority is:

```text
application > dependency > framework default
```

Priority is determined from the full registration backtrace. Dependency frames continue scanning because an application may call through a package helper; any later application frame makes the registration application-owned.

Composer path repositories require extra care. Composer exposes their real install directories rather than paths below `base_path('vendor')`. Priority detection therefore:

1. Resolves source paths with `realpath()` when possible.
2. Reads the Composer root package name.
3. Checks real install paths for every non-root installed package.
4. Uses directory-boundary-safe comparisons for both installed packages and the normal vendor directory.
5. Treats unknown non-vendor frames as application code.

This preserves Laravel's normal behavior while correctly handling framework and package development through Composer path repositories.

### Node package-manager detection

Laravel checks lockfiles only in the current directory. That selects npm incorrectly when an application is nested in a pnpm, Yarn, or Bun workspace and does not have its own lockfile.

Hypervel walks from `getcwd()` to the filesystem root. At each directory it checks lockfiles in this order:

1. Bun: `bun.lock`, then `bun.lockb`.
2. pnpm: `pnpm-lock.yaml`.
3. Yarn: `yarn.lock`.
4. npm: `package-lock.json`.

The nearest directory with a recognized lockfile wins. npm remains the fallback when no lockfile exists.

The concrete package-manager classes keep Laravel's no-argument `matches(): bool` API. The ancestor traversal stays private to the manager and does not change public method signatures or process-global working directories.

Installed binary execution uses each package manager's project-local command:

- Bun: `bunx`
- pnpm: `pnpm exec`
- Yarn: `yarn run`
- npm: `npx`

### The `dev` command

The command resolves the effective registry once and reuses that snapshot for validation, display, and process construction.

It fails before process construction when no commands remain after filtering. This avoids calling `max()` on an empty name list and gives the developer a clear console error.

When the effective `server` process is still the framework default, the command verifies that `watch` is registered with the console application. A missing Watcher package returns a failure with the installation command:

```text
composer require --dev hypervel/watcher
```

Filtering out the default server or replacing it with a higher-priority registration bypasses this check because the developer then owns the server process.

The command runs outside Hypervel's normal console coroutine. It launches long-running subprocesses and hands the native console process to `pcntl_exec` when available. Running natively ensures the process receiving terminal signals is the process that owns the `concurrently` process tree.

The final command preserves Laravel's names, colors, `--kill-others-on-fail`, `pcntl_exec`, `passthru`, and terminal-column restoration behavior.

### The `dev:list` command

The list command preserves Laravel's filtering, vendor-only view, JSON output, source formatting, and interactive table behavior.

Source truncation handles terminals too narrow to display a source column. When no source width remains, the source is omitted rather than passing a negative width to string truncation. The command text remains visible even at a one-column terminal width.

### Watcher shutdown ownership

Targeted termination of the Watcher command can bypass cleanup that normally occurs after `Watcher::run()` returns. The command therefore traps `SIGINT`, `SIGTERM`, and `SIGQUIT` before entering the watcher loop.

The signal callback stops the driver first, then guarantees restart-strategy cleanup with `finally`. This order stops the file-watching process before the strategy-owned server process and still cleans up the server if driver shutdown throws.

`WatchCommand` retains its public `Hypervel\Contracts\Container\Container` constructor dependency. It resolves the Foundation application contract internally when checking console mode, avoiding a dependency on the concrete Foundation application.

## Implementation

### Support package

Add the Node package-manager contract, manager, and Bun/npm/pnpm/Yarn implementations. Cover direct command generation, public `matches()` behavior, nearest ancestor detection, lockfile priority, and npm fallback.

### Foundation package

Add:

- `DevCommand`, the immutable command definition and fluent color API.
- `DevCommandColor`, the backed color enum.
- `DevCommands`, the worker-lifetime process registry.
- `Foundation\Console\DevCommand`, the process orchestrator.
- `Foundation\Console\DevListCommand`, the registry inspector.

Register both console commands and framework defaults from `FoundationServiceProvider`. Add `hypervel/prompts` as a direct Foundation dependency because both commands use the terminal API.

### Testing package

Reset registry state and the `dev` command's prohibition flag from `AfterEachTestSubscriber`. This prevents static boot-time configuration from crossing test boundaries in a PHPUnit worker.

### Watcher package

Resolve the Foundation application through its contract and register termination cleanup before `Watcher::run()`. Test normal signal cleanup and guaranteed restart-strategy cleanup when driver shutdown throws.

### Documentation

Document the intentional Laravel differences in the Foundation, Support, and Watcher package READMEs:

- Watcher replaces the default `serve` process and Hypervel omits Pail.
- Lockfile detection walks ancestor directories and pnpm/Yarn use project-local execution.
- Watcher owns and stops its driver and server children on termination.

## Verification

The implementation is verified at three levels:

1. Focused tests cover every new value object, package manager, registry path, console command, provider registration, and Watcher cleanup branch.
2. Static analysis runs against both repository PHPStan configurations.
3. `composer fix` runs CS Fixer, the complete framework suite, Testbench's package-mode suite, and the dogfood package suite.

The final verification completed with:

- 22,638 framework tests and 64,321 assertions.
- 327 Testbench tests and 955 assertions.
- 4 dogfood tests and 7 assertions.
- No CS Fixer or PHPStan failures.
