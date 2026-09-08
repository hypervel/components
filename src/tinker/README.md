Tinker for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/tinker)

## Differences From Laravel

Hypervel disables PsySH process forking because it is incompatible with Swoole. A fatal error ends the Tinker session instead of only ending the current evaluation.

Hypervel uses PsySH's prompt project-trust mode by default, while Laravel Tinker trusts `.psysh.php` configuration automatically. Interactive sessions ask before loading an unfamiliar project, and non-interactive sessions skip its configuration.

Ported from: https://github.com/laravel/tinker
