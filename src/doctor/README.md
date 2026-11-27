Doctor for Hypervel
===

Documentation: https://hypervel.org/docs/doctor

## Differences From Laravel

`ApplicationTimezoneIsValid` is not provided. Hypervel validates the application timezone during startup and stops before commands run when it is invalid.

Use `EnvironmentMode::fromHypervelEnvironment()` in place of `fromLaravelEnvironment()`.

`ComposerAutoloadIsAuthoritative` fails in production unless Composer uses an [authoritative class map](https://hypervel.org/docs/deployment#autoloader-optimization).

`OpcacheIsEnabledForCli` fails in production unless [OPcache is enabled for the PHP CLI](https://hypervel.org/docs/deployment#php-configuration).

Ported from: https://github.com/laravel/doctor
