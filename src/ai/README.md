AI SDK for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/ai)

Documentation: https://hypervel.org/docs/ai-sdk

## Differences From Laravel

Agent and stream-event `broadcast()` methods deliver events immediately by default. Pass `now: false` to queue events; queued generation remains available. See [broadcasting](https://hypervel.org/docs/ai-sdk#broadcasting).

Agent broadcasts batch adjacent text/reasoning deltas by default within a 50 ms window, retaining original events for replay. Set `ai.broadcasting.batching.enabled` to `false` for individual-event delivery. See [broadcast batching](https://hypervel.org/docs/ai-sdk#broadcast-batching).

Queued AI jobs' `failed()` hooks accept `?Throwable` so manual failures without an exception reach their callbacks. Overrides must accept null as well.

`Promptable::getProvidersAndModels()` returns an ordered list of `[Provider|string, ?string]` pairs instead of a name-keyed map, preserving provider objects and their credentials. Update overrides to return pairs. Failover retains repeated entries as separate attempts. The public `Provider::formatProviderAndModelList()` utility retains its name-keyed return format. See [failover](https://hypervel.org/docs/ai-sdk#failover).

Pending requests' protected `resolveProviderOptionsAndHeaders()` helper accepts the provider contract so custom implementations work. `PendingEmbeddingsGeneration`'s protected caching helpers receive precomputed identities or keys, keeping cache reads and writes consistent without repeated identity calculation. Subclasses overriding these helpers must match the Hypervel signatures; public generation and caching methods are unchanged.

`RunContext::startingStep()` accepts optional trailing `?string $instructions = null` and `array $tools = []` parameters so listeners receive the resolved step inputs. Overrides must accept these parameters too.

Custom conversation stores must implement `ClaimsPendingApprovals` to resume stored tool approvals safely. Claim-owned per-result recording replaces the protected `ResumesToolApprovals::storeApprovalResultRecorderFor()` helper, which is removed. Stores read `AgentPrompt::approvalClaim()` when settling the turn. Ordinary conversations and stateless resumption remain available without that capability.

`RememberConversation` replaces protected `openTurn()` with `rememberTurn(): StoredTurn` so the database store can persist a complete turn atomically. Its protected `rememberFailedTurn()` returns `?StoredTurn` instead of `void`, preserving stored IDs across stream retries. Update overrides to use these helpers; public agent calls and standalone store methods are unchanged.

Skill discovery is cached for the fixed paths in `ai.skills.cached_paths`. [Reload services](https://hypervel.org/docs/deployment#reloading-services) after changing those files; skills loaded during application boot require a full restart. Other skill sources remain dynamic. Set `cached_paths` to `[]` to disable caching. See [skills](https://hypervel.org/docs/ai-sdk#skills).

The conversation migrations use Hypervel's column types, indexes, and approval claim fields. Existing Laravel conversation data requires a [schema migration](https://hypervel.org/docs/ai-sdk#migrating-conversation-data) before use.

Remote downloads share the HTTP client's destination checks. The protected `UntrustedUrl::validate()`, `resolve()`, and `isBlocked()` helpers and hostname-blocklist constants are removed; `resolveUsing()` remains available. URLs containing embedded credentials are rejected. Downloads are limited to 32 MiB of decoded content by default; configure `ai.remote_files.max_size` to change or disable the limit. See [remote attachments](https://hypervel.org/docs/ai-sdk#remote-attachments).

## Known Issues

Bedrock streaming is affected by two AWS SDK decoder issues. A valid stream can fail with a checksum error when an event's checksum arrives in parts ([#3371](https://github.com/aws/aws-sdk-php/issues/3371)). The final event can also be lost ([#3374](https://github.com/aws/aws-sdk-php/issues/3374)): token usage then reads zero, or an AWS error sent as the last event is reported as an incomplete stream. These limitations remain until the supported AWS SDK version includes the fixes.

Like Laravel AI, nested objects in tool arguments can lose their JSON type when decoded to PHP arrays: `{"options":{}}` can become `{"options":[]}`. This can fail validation in MCP or Vercel integrations that require an object. This behavior is retained for now; upstream reports and fixes will be considered during normal syncs.

Ported from: https://github.com/laravel/ai
