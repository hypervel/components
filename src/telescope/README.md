Telescope for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/telescope)

Documentation: https://hypervel.org/docs/telescope

## Differences From Laravel

- Recording state applies to the current coroutine, since one worker handles many requests at once. `startRecording()`, `stopRecording()`, `withoutRecording()` and `isRecording()` apply to the current coroutine, and the `$shouldRecord` property is not available. See [controlling recording](https://hypervel.org/docs/telescope#controlling-recording).
- Recorded entries and updates are also held per coroutine. Use `Telescope::getEntriesQueue()` and `Telescope::getUpdatesQueue()` instead of the `$entriesQueue` and `$updatesQueue` properties. `Telescope::store()` waits until the current coroutine finishes before storing them, unless the `telescope.defer` option is `false`. See [deferred storage](https://hypervel.org/docs/telescope#deferred-storage).
- `Telescope::cspNonce()` sets the nonce for the current request only, so call it from middleware rather than during boot. The `$nonceAttribute` property is not available. See [Content Security Policy (CSP) nonce](https://hypervel.org/docs/telescope#content-security-policy-csp-nonce).

Ported from: https://github.com/laravel/telescope
