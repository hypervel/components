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

## Documentation

- Re-run the introduction benchmarks against Hypervel 0.4 before publishing externally. The benchmark tables currently preserve the 0.3 results so the comparison is not lost during the docs port, but Hypervel 0.4's decoupled runtime should have fresh measurements before those numbers are treated as current.

## Redis

- Audit transformed Redis command wrapper return types against serializer-configured phpredis connections. For example, `RedisConnection::callGet(): ?string` can receive unserialized non-string values from phpredis when a serializer is enabled under `strict_types`; check the other `call*` wrappers for the same mismatch and update signatures/tests to match real client behavior.

## Collections

## Horizon

- Wire SMS support for Hypervel Horizon long-wait notifications. The Horizon docs show `Horizon::routeSmsNotificationsTo(...)` and `Hypervel\Horizon\Horizon` stores the configured number, but `Hypervel\Horizon\Notifications\LongWaitDetected::via()` and `Hypervel\Horizon\Listeners\SendNotification` currently have the SMS / Nexmo route commented out because no SMS client is supported yet. Correct fix: add a supported SMS notification channel, route long-wait notifications to it when `Horizon::$smsNumber` is set, add the matching notification message method, document the channel prerequisite, and add coverage for mail, Slack, and SMS routing.
