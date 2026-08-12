Translation for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/translation)

Documentation: https://hypervel.org/docs/localization

## Differences From Laravel

- `Translator::setLocale()` changes the locale only for the current coroutine and does not affect other concurrent requests in the worker.
- `Translator::setFallback()` changes the fallback shared by the worker and is intended for application boot.
- `Translator` rejects JSON translation files whose top-level values are not strings or arrays, naming the file and key. A `null` value is allowed and means the key is untranslated.

Ported from: https://github.com/laravel/framework
