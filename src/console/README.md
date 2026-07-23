Console for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/console)

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Console

## Differences From Laravel

`schedule:list --timezone` converts next-due timestamps but leaves cron expressions in their real evaluation timezone. Laravel's display-only expression converter cannot faithfully handle ranges, special cron syntax, month boundaries, or daylight-saving transitions. JSON output includes `expression_timezone`, and CLI output labels it when it differs from the requested display timezone.
