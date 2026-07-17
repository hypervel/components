# Source Implementation Gaps

## Authentication

- Create hypervel/react-starter-kit. Include the standard skeleton pieces that currently only exist as follow-ups: a `composer dev` script for running the Hypervel development server and frontend asset watcher together, plus explicit Hypervel Vite refresh paths instead of the Laravel plugin's `refresh: true` shortcut.
- Port Fortify package
- Port Passport package
- Replace permission package fake Passport client-credentials coverage with real Passport tests once Passport is ported. The current tests use a local fake guard/client so the permission package can keep Passport middleware parity without depending on a package that does not exist yet.

## Artisan

- Add a `composer dev` script to the `hypervel/hypervel` application skeleton. The script should start the Hypervel development server and frontend asset watcher together using the package manager tools already included with the skeleton, so new applications have a simple one-command local development workflow.

## Boost

- Implement Hypervel Boost's installation flow and revisit the Boost section of `installation.md` once the implementation is complete. The current docs describe the intended `composer require hypervel/boost --dev` and `php artisan boost:install` workflow, but `src/boost` currently contains the documentation package only. Correct fix: add the interactive installer command and supporting tools, then update the installation docs for any differences from Laravel Boost.

## Broadcasting

- Review whether Pusher and Ably broadcaster pooling manages state that cannot safely be shared like the unpooled Reverb broadcaster. Their current pooling behavior remains unchanged; see `docs/plans/2026-07-10-object-pool-lifecycle-and-client-pooled-filesystems.md` for the state-ownership evidence and Reverb decision.

## Framework-wide

- Make `CarbonImmutable` the framework's default date class. Change `DateFactory::DEFAULT_CLASS_NAME` (`src/support/src/DateFactory.php`) from `Carbon::class` to `CarbonImmutable::class` so `now()`, `today()`, and factory-produced date casts return immutable instances; `Date::use(Carbon::class)` remains the opt-out. Mutable dates held on singletons or statics are shared mutable state across coroutines — the bug class the rest of the framework is designed to prevent — and immutable-by-default matches modern PHP practice (Symfony's Clock, Doctrine's immutable types) that Laravel can't adopt only because of its backwards-compatibility burden. Scope: audit the framework files importing mutable `Carbon` and retype those receiving factory-produced values to `CarbonImmutable` or `CarbonInterface`; review ported code relying on in-place mutation; add mutable-date conversion to the Porting Packages modernization list in `AGENTS.md`; update tests. When done, add a Code conventions rule to `AGENTS.md` stating Hypervel defaults to `CarbonImmutable` where Laravel defaults to mutable `Carbon`, and record the divergence in `docs/ai/differences-vs-laravel.md`.

- Convert container array access to `make()` across `src/`. About 40 files use `$app['...']` (e.g. `LogManager`, `ViewServiceProvider`, `TranslationServiceProvider`), carried over from upstream Laravel. `offsetGet()` always returns `mixed`, while `make()` has class-string generics phpstan can follow, so the conversion makes static analysis strictly more useful. Approved modernization per the Porting Packages policy in `AGENTS.md`; new code already follows the rule.
- Convert untyped `$config->get()` calls across `src/` to the typed getters (`string()`, `integer()`, `float()`, `boolean()`, `array()`) without call-site defaults, for every key that isn't genuinely nullable. Defaults live in the merged config files — declare any key currently defaulted only at a call site in its package's config file as part of the conversion. Typed getters throw `InvalidArgumentException` naming the key on misconfiguration instead of letting a wrong type propagate silently, and give phpstan real return types. Bootstrap code that runs before config merging keeps its call-site defaults. Approved modernization per the Porting Packages policy in `AGENTS.md`; new code already follows the rule.

## Documentation

- Re-run the introduction benchmarks against Hypervel 0.4 before publishing externally. The benchmark tables currently preserve the 0.3 results so the comparison is not lost during the docs port, but Hypervel 0.4's decoupled runtime should have fresh measurements before those numbers are treated as current.

## Redis

- Audit transformed Redis command wrapper return types against serializer-configured phpredis connections. For example, `RedisConnection::callGet(): ?string` can receive unserialized non-string values from phpredis when a serializer is enabled under `strict_types`; check the other `call*` wrappers for the same mismatch and update signatures/tests to match real client behavior.

## Collections

## Horizon

- Wire SMS support for Hypervel Horizon long-wait notifications. The Horizon docs show `Horizon::routeSmsNotificationsTo(...)` and `Hypervel\Horizon\Horizon` stores the configured number, but `Hypervel\Horizon\Notifications\LongWaitDetected::via()` and `Hypervel\Horizon\Listeners\SendNotification` currently have the SMS / Nexmo route commented out because no SMS client is supported yet. Correct fix: add a supported SMS notification channel, route long-wait notifications to it when `Horizon::$smsNumber` is set, add the matching notification message method, document the channel prerequisite, and add coverage for mail, Slack, and SMS routing.
