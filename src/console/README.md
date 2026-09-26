Console for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/console)

## Differences From Laravel

`CommandInput::all([])` returns an empty array. Use `all()` to retrieve all command input.

Laravel's deprecated `GeneratorCommand::possibleModels()` alias is not ported. Generator commands should call `findAvailableModels()` instead.

`schedule:run` is a long-running process by default and replaces `schedule:work`. Use `schedule:run --once` in cron entries. See the [scheduling documentation](https://hypervel.org/docs/scheduling#running-the-scheduler).

Scheduled tasks do not support `user()`. Run the scheduler as the required OS user, or use `exec()` with an explicit command to run an individual task as another user.

`schedule:list --timezone` converts next-due timestamps but leaves cron expressions in their evaluation timezone because a single converted expression cannot stay correct across daylight-saving changes and month boundaries. See the [timezone documentation](https://hypervel.org/docs/scheduling#timezones).

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Console
