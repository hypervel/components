# Source Implementation Gaps

## Authentication

- Create hypervel/react-starter-kit
- Port Fortify package
- Port Passport package
- Replace permission package fake Passport client-credentials coverage with real Passport tests once Passport is ported. The current tests use a local fake guard/client so the permission package can keep Passport middleware parity without depending on a package that does not exist yet.

## Artisan

- Add a `composer dev` script to the `hypervel/hypervel` application skeleton and the `hypervel/react-starter-kit` skeleton. The script should start the Hypervel development server and frontend asset watcher together using the package manager tools already included with each skeleton, so new applications have a simple one-command local development workflow.

## Configuration

## Boost

- Implement Hypervel Boost's installation flow and revisit the Boost section of `installation.md` once the implementation is complete. The current docs describe the intended `composer require hypervel/boost --dev` and `php artisan boost:install` workflow, but `src/boost` currently contains the documentation package only. Correct fix: add the interactive installer command and supporting tools, then update the installation docs for any differences from Laravel Boost.
- Publish a Hypervel AI agent playbook at `hypervel.org/for/agents`. The copied installation docs include a Laravel agent prompt section, but Hypervel does not yet have an equivalent public playbook. Correct fix: write and publish a Hypervel-specific Markdown guide covering installation, project layout, Swoole / coroutine considerations, testing, and package conventions before adding the agent-prompt section back to the installation docs.

## Documentation

- Re-run the introduction benchmarks against Hypervel 0.4 before publishing externally. The benchmark tables currently preserve the 0.3 results so the comparison is not lost during the docs port, but Hypervel 0.4's decoupled runtime should have fresh measurements before those numbers are treated as current.

## Collections

## Horizon

- Wire SMS support for Hypervel Horizon long-wait notifications. The Horizon docs show `Horizon::routeSmsNotificationsTo(...)` and `Hypervel\Horizon\Horizon` stores the configured number, but `Hypervel\Horizon\Notifications\LongWaitDetected::via()` and `Hypervel\Horizon\Listeners\SendNotification` currently have the SMS / Nexmo route commented out because no SMS client is supported yet. Correct fix: add a supported SMS notification channel, route long-wait notifications to it when `Horizon::$smsNumber` is set, add the matching notification message method, document the channel prerequisite, and add coverage for mail, Slack, and SMS routing.

## Mail

## Packages

## Queue

## Support

## Testing

- Port an app-facing `php artisan test` command. The copied testing docs document `php artisan test`, including `--parallel`, `--coverage`, `--min`, `--profile`, `--recreate-databases`, `--drop-databases`, `--without-databases`, `--without-cache`, and ParaTest pass-through options such as `--processes`, but Hypervel currently ships only `make:test` for applications and `package:test` for Testbench package development. The underlying machinery already exists: `Hypervel\Testing\ParallelRunner`, `Hypervel\Testing\ParallelTesting`, parallel database / cache / view handling, and Collision's coverage / printer support used by Testbench's `package:test` command. Correct fix: add a Hypervel application test command, or a Hypervel Collision adapter, that shells out to PHPUnit / ParaTest using `Hypervel\Testing\ParallelRunner`, sets the `HYPERVEL_PARALLEL_TESTING_*` environment variables, preserves PHPUnit / ParaTest pass-through arguments, and port the matching command coverage.
- Port the `#[UnitTest]` testing attribute and no-boot test lifecycle. The copied testing docs reference `Hypervel\Foundation\Testing\Attributes\UnitTest` to skip booting the application for a single test method, but Hypervel currently has no `UnitTest` attribute and `Hypervel\Foundation\Testing\TestCase::setUp()` / `tearDown()` always call the framework lifecycle. Laravel implements this with `Illuminate\Foundation\Testing\Attributes\UnitTest` and a memoized `withoutBootingFramework()` check on the current test method. Correct fix: add the attribute class, add the per-method reflection check to Hypervel's test case lifecycle, skip application boot / teardown when present, preserve `RunTestsInCoroutine` behavior unless deliberately disabled by the test class, and port Laravel's coverage.
- Update the PHPUnit `make:test --unit` stub to use Hypervel's coroutine-aware application test case. The testing docs recommend extending `Tests\TestCase` for Hypervel application tests so they run through `RunTestsInCoroutine`, but `src/foundation/src/Console/stubs/test.unit.stub` currently extends raw `PHPUnit\Framework\TestCase`. Correct fix: change the unit stub to extend `Tests\TestCase`, keep `#[UnitTest]` as an optional per-method optimization for tests that should skip booting the application, and update the generator coverage.

## Validation

- Port Rule::string() fluent string rule builder

## Vite

- Port Laravel's recursive Vite import resolution. Laravel resolves static imports recursively via `Vite::resolveImports()`, while `Hypervel\Foundation\Vite::__invoke()` currently only preloads each entry chunk's direct `imports`, so nested imported chunks and nested CSS can be omitted from generated preload / stylesheet tags. Correct fix: port Laravel's recursive import resolver into `Hypervel\Foundation\Vite`, use it when collecting imports for an entry chunk, and port Laravel's nested import / nested CSS Vite tests.
- Align JavaScript module preload attributes with Laravel. Laravel includes `as="script"` on JavaScript `modulepreload` links, while Hypervel currently emits `rel="modulepreload"` without the `as` attribute. Correct fix: add the script preload `as` attribute in `Hypervel\Foundation\Vite::resolvePreloadTagAttributes()` and update the Vite tests that assert generated preload markup.
- Configure Hypervel's application skeleton and React starter kit with explicit Vite refresh paths. The Vite docs list Hypervel's default refresh paths without Laravel-only `app/Livewire/**`, but the current skeleton delegates to the Laravel Vite plugin's `refresh: true` defaults. Correct fix: update the skeleton `vite.config.js` files to pass the Hypervel-specific watch paths explicitly.
