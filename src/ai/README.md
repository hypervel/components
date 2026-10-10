AI SDK for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/ai)

Documentation: https://hypervel.org/docs/ai-sdk

## Differences From Laravel

Agent and stream-event `broadcast()` methods deliver events immediately by default. Pass `now: false` to queue individual events; queued generation remains available. See [broadcasting](https://hypervel.org/docs/ai-sdk#broadcasting).

Queued AI jobs' `failed()` hooks accept `?Throwable` so manual failures without an exception reach their callbacks. Overrides must accept null as well.

`Promptable::getProvidersAndModels()` returns an ordered list of `[Provider|string, ?string]` pairs instead of a name-keyed map, preserving provider objects and their credentials. Update overrides to return pairs. Failover retains repeated entries as separate attempts. The public `Provider::formatProviderAndModelList()` utility retains its name-keyed return format. See [failover](https://hypervel.org/docs/ai-sdk#failover).

Pending requests' protected `resolveProviderOptionsAndHeaders()` helper accepts the provider contract so custom implementations work. `PendingEmbeddingsGeneration`'s protected caching helpers receive precomputed identities or keys, keeping cache reads and writes consistent without repeated identity calculation. Subclasses overriding these helpers must match the Hypervel signatures; public generation and caching methods are unchanged.

Custom conversation stores must implement `ClaimsPendingApprovals` to resume stored tool approvals safely. Claim-owned per-result recording replaces the protected `ResumesToolApprovals::storeApprovalResultRecorderFor()` helper, which is removed. Stores read `AgentPrompt::approvalClaim()` when settling the turn. Ordinary conversations and stateless resumption remain available without that capability.

Skill discovery is cached for the fixed paths in `ai.skills.cached_paths`. Reload the worker or flush the skill cache after changing those files; other skill sources remain dynamic.

The conversation migrations use Hypervel's column types, indexes, and approval claim fields. Existing Laravel conversation data requires a schema migration before use.

Remote downloads share the HTTP client's destination checks. The protected `UntrustedUrl::validate()`, `resolve()`, and `isBlocked()` helpers and hostname-blocklist constants are removed; `resolveUsing()` remains available. URLs containing embedded credentials are rejected. Downloads are limited to 32 MiB of decoded content by default; configure `ai.remote_files.max_size` to change or disable the limit. See [remote attachments](https://hypervel.org/docs/ai-sdk#remote-attachments).

Ported from: https://github.com/laravel/ai
