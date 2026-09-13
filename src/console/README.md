Console for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/console)

## Differences From Laravel

`schedule:run` is a long-running process by default and replaces `schedule:work`. Use `schedule:run --once` in cron entries. See the [scheduling documentation](https://hypervel.org/docs/scheduling#running-the-scheduler).

Scheduled tasks do not support `user()`. Run the scheduler as the required OS user, or use `exec()` with an explicit command to run an individual task as another user.

`schedule:list --timezone` converts next-due timestamps but leaves cron expressions in their real evaluation timezone. Laravel's display-only expression converter cannot faithfully handle ranges, special cron syntax, month boundaries, or daylight-saving transitions. JSON output includes `expression_timezone`, and CLI output labels it when it differs from the requested display timezone.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Console
