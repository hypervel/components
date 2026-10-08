AI SDK for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/ai)

Documentation: https://hypervel.org/docs/ai-sdk

## Differences From Laravel

`broadcast()` delivers events immediately by default. Pass `now: false` to queue individual events; queued generation remains available.

Custom conversation stores must implement `ClaimsPendingApprovals` to resume stored tool approvals safely. Ordinary conversations and stateless resumption remain available without that capability.

Skill discovery is cached for the fixed paths in `ai.skills.cached_paths`. Reload the worker or flush the skill cache after changing those files; other skill sources remain dynamic.

The conversation migrations use Hypervel's column types, indexes, and approval claim fields. Existing Laravel conversation data requires a schema migration before use.

Remote downloads share the HTTP client's destination checks. The protected `UntrustedUrl::validate()`, `resolve()`, and `isBlocked()` helpers and hostname-blocklist constants are removed; `resolveUsing()` remains available. URLs containing embedded credentials are rejected. Downloads are limited to 32 MiB of decoded content by default; configure `ai.remote_files.max_size` to change or disable the limit. See [remote attachments](https://hypervel.org/docs/ai-sdk#remote-attachments).

Ported from: https://github.com/laravel/ai
