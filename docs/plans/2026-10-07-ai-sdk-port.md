# AI SDK port and framework integration

## Outcome and working boundaries

Port Laravel AI `1.x` to `hypervel/ai`, retaining its optional MCP adapters. The owner has assigned the MCP package port to a separate session; it is not a prerequisite for this port. Preserve upstream application APIs, named arguments, protected extension points, protocols and test coverage while making execution safe and efficient in long-lived coroutine workers. Ordinary Eloquent/query-builder usage and ordinary agent calls keep their familiar behavior.

Work in the `components-ai` worktree on `feature/ai`, based on `0.4`. Dependencies are independently installed and `.env` is copied. Follow the current monorepo instructions and the owner's governing `/home/binaryfire/workspace/contrib/hypervel/components/AGENTS.md`, including its dependency-access guidance even where the worktree copy differs. Maintain a separate remaining-file checklist during implementation rather than expanding this plan into a source inventory. The implementer is authorized to commit signed-off checkpoints under the workflow below; pushing, merging another worktree and altering historical plans remain unauthorized.

**Current delivery boundary:** complete all changes to existing framework packages before starting the AI package, so they can form a separate framework PR. Bring forward filesystem, JSON schema, reflection metadata, watcher and other supporting framework changes from later checkpoints. Finish checks, self-review and peer code-review loops for that entire framework scope, commit signed-off work, then notify the owner and stop. Do not begin AI source porting or create/push the PR until instructed to continue.

The public reference is the local `references/laravel/ai` clone on `1.x` and its current tests/config/resources. Recheck upstream changes before copying. Use `src/cache/{composer.json,README.md,LICENSE.md}` as the first-party package skeleton. Within dependency groups, port files alphabetically, one at a time: `cp` the upstream file, read the entire copy, then make targeted edits. Apply this to source, tests, fixtures and documents; only genuinely new Hypervel-specific files are written fresh. Exclude Boost resources only; agent skills remain supported. Real MCP integration execution depends on the separately assigned package, as described in §8. Apply current dependency-injection guidance: retain upstream access patterns in closely ported classes; use injection where a substantive redesign benefits from it.

Implement the database and HTTP changes directly against this worktree's framework code. The proposed Swoole server-level HTTP/2 stream-cancel event is **not** a prerequisite. Implement all behavior possible on supported released runtimes. The missing immediate HTTP/2 reset notification is tracked in `docs/todo.md`; no polling workaround, unreleased runtime requirement or speculative native API belongs in this port.

## Public behavior and compatibility

Keep `Provider::__toString()`, `Provider::formatProviderAndModelList()`'s `name => model` return shape, `UntrustedUrl::resolveUsing()`, standalone conversation-store methods, queue options, custom gateways, middleware, stream event replay and protocol wire formats. In particular, upstream tests subclass `TextGenerationLoop::stepToolResults()` and `approvalAwareToolResults()`; do not add required protected parameters to pass invocation state.

Apply these discussed differences and explain them proportionately:

- `broadcast()` defaults to immediate delivery (`now: true`); retain `now: false` and queued generation. The default change and Filesystem contract additions are explicitly approved.
- Release idle database leases before AI network waits. Transactions/cursors remain pinned automatically; deliberately session-dependent code spanning such a wait uses `withPinnedSession()`. This affects temporary tables, retained raw PDO/statements and session locks, not ordinary queries or framework session configurators.
- Store-backed approval resumption requires `ClaimsPendingApprovals`. Custom stores without it still serve ordinary conversations but fail clearly before approved tools execute. Stateless resumption from caller-supplied history has no server record to claim; the application owns duplicate-submission prevention there.
- Cache discovery only for configured fixed skill paths, with explicit flush/reload behavior and development watcher coverage.
- Shipped migration types/indexes and new claim/replay columns differ from Laravel's schema; document migration of existing data rather than implying Laravel tables can be reused unchanged.

Record deliberate lasting public differences briefly in the relevant package README and link to canonical docs. Explain affected edge cases in feature docs, and put actual migration/adaptation requirements in `src/docs/porting-from-laravel.md`. Do not catalogue internal optimizations or bug fixes as public API redesigns. Any additional compatibility change discovered during implementation needs owner approval.

## Implementation checkpoints

Establish the source/test checklist and dependency metadata first. Use these review boundaries for the public work:

| Checkpoint | Completed work to review |
|---|---|
| 1 | Database connection ownership: physical leases, early release, pinning and lifecycle tests. |
| 2 | HTTP streaming and cancellation: incremental transport, response ownership, disconnect handling and tests. |
| 3 | AI foundations: package wiring, manager, agents, provider configuration, BYOK and coroutine/deferred context. |
| 4 | Provider implementations: generation gateways across modalities, files/stores and Bedrock. |
| 5 | Tools and streaming features: tools, skills, schemas, optional MCP adapters, concurrent execution and broadcasting. |
| 6 | Conversation persistence and approvals: partitioning, atomic turns, claims, failure handling and tests. |
| 7 | Public package completion: remaining caching/optimizations, in-place port of `src/docs/ai-sdk.md`, related docs, full framework validation and controlled performance checks. |

Boundaries are flexible review groupings, not partial releases or rigid file assignments. Bring dependencies forward and complete them when needed; aim to finish each file in one pass, including its approved optimizations and meaningful tests. Do not create temporary implementations, placeholder methods or deliberately unfinished files to fit a checkpoint. Revisit completed files when integration, evidence or review requires it. Keep only remaining work in the checklist as scope moves, and include all brought-forward changes in the current review.

- **Implementer:** run every new/changed test file immediately, then the affected checks required by this plan and `AGENTS.md`. Keep directly affected docs accurate with each change; checkpoint 7 completes the canonical AI page and overall documentation audit. Request code review from the assigned peer after each completed checkpoint, supplying the full changes since the previous signoff and validation results. Investigate feedback, push back on incorrect or unnecessary suggestions, fix accepted findings, and loop until explicit signoff before committing or starting the next checkpoint.
- **Reviewer:** review the actual checkpoint scope, including brought-forward dependencies and reported validation. Check correctness, API compatibility, coroutine/resource ownership, performance, Laravel ergonomics and unnecessary code/tests. Request missing verification from the implementer rather than rerunning checks; do not implement or commit in the reviewer role.
- **Implementer, after signoff:** commit the reviewed work in its owning repository. Use multiple coherent commits when useful, with a detailed body for each explaining the problem, decisions, resulting behavior and relevant validation. Stage whole files only; never split hunks or temporarily rewrite a file to manufacture commit boundaries. Keep inseparable changes together, exclude unrelated work, and do not push. Checkpoint signoff does not replace the final complete-package verification in §10.

Code-reviewed checkpoints may be committed and subsequent work may proceed before performance measurements. Benchmarks remain mandatory before opening the framework PR; obtain peer review of the results and make any required improvements in additional reviewed commits. Use an owner-confirmed idle window.

## 1. Database connection ownership

### Completed framework surface and downstream requirements

Logical connections now remain owned by their execution while physical PDO sessions can return to the pool between operations. Retained builders, sticky routing, logs and callbacks survive early release. Initial resolution still borrows a slot; later use reacquires lazily. Lifecycle, read-endpoint selection, physical-handle cleanup and transaction-context isolation fixes are implemented with regression coverage. See the source and database documentation for their internal details.

Use the additive operations:

```php
DB::releaseIdleConnections();

DB::connection()->withPinnedSession(function () {
    // Explicitly session-dependent operations may span external I/O here.
});
```

`releaseIdleConnections()` acts only on the current execution's existing connections. Transactions, cursors, complete query/event callbacks, scoped FK suppression and explicit pin scopes prevent voluntary release. Pins do not prevent replacing a broken session. `lazy()`/`chunk()` may release between completed batches. Retained raw PDO/statements or manually changed SQL session state spanning release require `withPinnedSession()`; ordinary builders do not. Existing session configurators synchronize on acquired physical sessions; this does not add transaction-pooler support.

`DB::extend()` drivers retain whole-connection ownership and early release is a no-op. The same applies when selectable records differ in database or table prefix: write records for base pools, read records for `::read` pools. PDO-first custom drivers participate through `Connection::resolverFor()` and, when needed, `db.connector.{driver}`. Preserve concrete connection identities and these extension boundaries.

Child coroutines own separate connections and transaction records. `DatabaseTransactionState` is non-copyable; Testbench explicitly transfers the same bag across setup/test/teardown. Do not copy transaction ownership into AI child work.

Place AI release boundaries before each actual provider wait: text steps and stream startup, embeddings, images, audio, transcription, reranking, classification, files/stores and Bedrock credential/network operations. Include built-in network tools where they own the wait; custom tools can use the same DB API. No per-token release calls and no transactions around network operations. Avoid acquiring an otherwise unused database connection merely to release it.

### Remaining performance acceptance

In `tests/Benchmarks/Database`, compare ordinary request database lifecycles against the unchanged baseline: logical construction, first query, query pinning, acquisition/settlement and coroutine-end cleanup, at realistic concurrency. Include GC runs/collected/roots, heap growth between collections and worker peak memory alongside concurrency and request counts. This redesign affects every database-using application. Require no unexplained throughput or allocation regression; optimize measured construction costs before considering any bounded wrapper recycling. Never recycle a logical object still retained by a builder/caller. Use the owner-arranged idle window required in §10.

## 2. HTTP streaming and response cancellation

### Incremental provider responses

The current Guzzle StreamHandler path batches larger reads; simply replacing the SDK's byte reader with `fgets()`, `stream_get_line()` or `read(8192)` delays events. Fix the owning HTTP transport first, then use `Response::lines()`/`jsonLines()` from AI parsers.

Implement incremental streaming in this worktree's existing HTTP client, with a PSR response body that returns available chunks as they arrive. Integrate at `PendingRequest::buildHandlerStack()` and the factory's existing named-connection handler boundary, preserving Guzzle middleware and caller-supplied handlers/clients. Use framework Coroutine/ObjectPool primitives where ownership requires them; AI gateways use `Http`, never package-owned cURL/socket implementations. Validate the streaming implementation with equivalent verified-TLS measurements and lifecycle tests. Pending transfers and promises remain operation-owned rather than shared across coroutines.

Required behavior:

- Bounded admission and buffering; a slow consumer applies backpressure without unbounded queued events. Deadlines account for pool/admission waits as well as network I/O.
- Per-request headers, credentials, cookies, body and middleware remain operation-owned. Reused physical transports must not retain request secrets/callbacks; dynamic origins must not create an ever-growing worker registry.
- Correct headers/informational responses, redirects, decompression, TLS verification, timeouts, middleware/fakes, stats and errors. Preserve supported options through the streaming handler or a compatible fallback; do not silently ignore them or reject existing usage without approval. Update transport capability guards, including the current `allow_url_fopen` check, to reflect the actual handler requirements.
- EOF, explicit close, exceptions, cancellation and abandoned consumption settle resources once. If an owned producer exists, closing must actively stop and join it even when upstream is silent. Preserve pre-header errors and truncated-body errors instead of translating them to successful EOF.
- Avoid redundant SDK-level `finally { close(); }` boilerplate where the owning response/generator chain already closes correctly. Test ownership before adding a close path.

Use a stable named HTTP identity per configured provider so TLS connections can be reused. Do not derive names from tenant secrets, trace IDs or arbitrary per-request configurations. Dynamic provider credentials travel in request options, not a cached PendingRequest.

### Caller disconnects on released runtimes

Add `IterableStreamedResponse::cancelOnDisconnect(): static` as response-level opt-in. Default AI SSE and both stream protocols enable it. Scope registration to response production in `ResponseBridge`; remove it before terminable work. Do not cancel unrelated controller side effects, background jobs or every request globally.

Where existing Swoole connection-close delivery is reliable, compose internal handling with the configured application callback at `Server::registerSwooleEvents()`. Preserve the application callback even if internal cleanup encounters an error. Register only in supported effective modes, avoiding Swoole startup warnings. Index active responses by connection identity and HTTP/2 stream ID, retaining per-response ownership tokens so cleanup removes only its own registration. HTTP/1 uses its connection entry without an HTTP/2 stream. Full connection close cancels every opted-in owner on that connection. Keep the map bounded by active responses; a future server-level stream-cancel event can select one stream through the same map.

Current Swoole `RST_STREAM` handling has no PHP notification. A reset of one HTTP/2 stream while the provider is silent therefore remains detectable only by a subsequent failed write or operation completion/timeout. Document and test existing-runtime behavior honestly. The missing native capability is a server-level stream-cancel event identifying the connection and stream; a per-response native subscription is unnecessary. Keep this enhancement in `docs/todo.md` and integrate the actual released API when available; no per-stream polling, synthetic heartbeat solely for detection or dependency on an unaccepted/unreleased API.

### Tests

Extend the existing `HttpClientStreamingTest`, `HttpClientResponseStreamTest` and related client/server suites using isolated loopback origins: paced SSE, split CRLF/UTF-8 lines, JSON lines, trailers, 1xx responses, truncated body, failures before headers, quiet upstream, slow consumer, explicit close and abandoned iteration. Assert cleanup and sibling-request progress under cancellation. Cover ordinary application close callbacks, opt-in boundaries and supported server modes; HTTP/2 reset tests must reflect the documented current limit. Reuse and extend this worktree's streaming fixtures rather than introducing duplicate test servers.

Explicitly prove that cancelling a response producer interrupts the new streaming transport while it is waiting on a silent provider, both before headers and during a quiet response body. Synchronize the fixture so cancellation happens during the wait, without depending on a later provider chunk or timeout to unblock it. Assert that owned child work finishes, the upstream transfer is closed and its lease is settled, while sibling requests remain usable. Exercise the supported connection-close path through ResponseBridge as well as direct transport cancellation; merely observing a cancellation flag or dropping a generator is insufficient.

## 3. AI lifetimes, providers and deferred execution

### State ownership

| State | Owner |
|---|---|
| Fixed configured provider definitions, safe gateways, immutable class metadata | Worker, bounded by application configuration/classes |
| Agent instances and intrinsically caller-configured tool families | Fresh resolution (`Transient` where intrinsic to the hierarchy) |
| On-demand/BYOK provider configuration and credentials | Operation/coroutine, never worker caches |
| Parent invocation, repair setting and optional MCP request | Coroutine context with nested restoration |
| Pending requests and stream protocol instances created with `new` | Existing caller-owned factory lifetime |
| Active streaming bodies, promises, subprocesses and leases | The operation that starts them |

Make the Agent contract extend `Transient`. Classify tools by real resolution paths: `FilesystemTool`, `AgentTool` and mutable approval/provider-tool families have caller-owned fluent configuration. Do not mark all tools or factory-created objects transient simply because they have mutable properties. Preserve worker-shared stateless middleware/services; document scoped/Transient alternatives for application middleware with operation-owned state.

Move `ParentInvocation` and `TextGenerationLoop` repair settings into separate scoped context entries, using the parent-ID pair and boolean values with nested `finally` restoration behind the existing method signatures. They have different owners and are rarely read together, so no shared mutable invocation bag is needed. The interleaved save/restore pattern on a shared property can leave the final shared value permanently wrong, not merely wrong during overlap.

Accept `Provider` objects throughout prompt/stream/queue, pending generation, files/stores, macros and related contracts. Preserve the objects through failover and encrypted on-demand serialization rather than reducing them to names. Keep the public name/model formatting utility unchanged and introduce an internal object-preserving iterator. Explicit named lookups of `Ai::build()` results retain a coroutine-local registry; internal flows pass objects directly. Never cache resolver-backed instances merely by name for an entire coroutine: nested scopes can change identity within the same coroutine.

Add these boot-only hooks on the `Ai` facade/AiManager, with individually owned callback slots, conflict checks, docblocks and test cleanup; no generic resolver registry:

```php
Ai::resolveProviderConfigUsing(
    fn (string $name, array $baseConfig): array => $accounts->providerConfig($name, $baseConfig),
);
Ai::resolveConversationPartitionUsing('account_id', fn (): ?string => $accounts->currentId());
Ai::resolveEmbeddingsCacheScopeUsing(fn (): ?string => $accounts->cacheScope());
Ai::captureContextUsing($captureOperationContext);
```

Here `$accounts` and `$captureOperationContext` are application-supplied worker-safe services/callbacks, not new framework abstractions. The capture callback returns `?Closure`; the returned runner accepts a Closure and returns its result.

Provider config is evaluated per operation, including default-provider and vector-query paths, without mutating global config. Return an array, not null; configured model defaults remain in that resolved record. No redundant model-default hook. Retain bounded reuse only for genuinely fixed configuration. Remove Octane/queue listeners that flush a global on-demand registry; classify remaining fake/gateway/global mutators as boot/tests only.

Partition registration follows Permission's immutable boot registration: a second/conflicting/late registration throws; after registration null/empty resolution fails closed. Cache scope null means unscoped central use; an empty string is invalid and throws at operation identity resolution. Capture hook null means no wrapper. Keep each hook's documentation precise about registration and resolver lifetime.

### Deferred operation context

Capture a runner once at lazy operation creation. Its shape is `Closure(Closure): mixed`; the integration owns only the contextual state it understands. Apply it to generator creation and each resumption, AI-owned `each`/`then`/`catch` callbacks (including `then` after completion), event/broadcast callbacks and inner-generator disposal in the wrapper's `finally`. Restore the caller's context before outward yields; arbitrary application `foreach` bodies remain in the consumer's context. Include failover wrappers, sub-agent streaming and replay paths.

The runner must represent a captured empty/central context too. Do not indiscriminately copy every coroutine-context entry. Synchronous calls use their caller's current context. Queue jobs use the framework's existing serialization/context lifecycle rather than serializing a stream runner.

### Cancellation and exception correctness

Trace real cancellation paths and rethrow `CanceledException` through AgentTool, approval execution, title generation, Bedrock exception conversion, filesystem tool catches and StreamProtocol masking. Clean up owned child operations. Do not add cancellation branches to unrelated catches.

`TextGenerationOptions` intentionally ignores protected option methods and methods with required arguments. Use cached reflection to establish eligibility, then invoke valid methods without swallowing errors from their bodies. Preserve attribute fallback and dynamic option values; metadata caching must not cache an agent's runtime-returned value.

## 4. Conversation storage, partitioning and approvals

### Isolation and schema

Implement generic opt-in row partitioning across `DatabaseConversationStore`, `Conversation`, `ConversationMessage`, their relationships, writes and model restoration. Filter reads, updates and deletes; stamp inserts; reject a model/message/conversation from a different resolved partition. A configured but unresolved partition fails closed. An application that has not registered partitioning continues using the unpartitioned schema. Use Permission's captured ownership/restoration patterns; a global scope alone is insufficient because model restoration can bypass scopes.

Provide one protected `DatabaseConversationStore::ensureWriteAllowed(): void` extension point, called at its mutation boundaries including claims, result recording and turn settlement. Its default permits writes; a specialized store can enforce account lifecycle/read-only policies before raw-builder writes. A model observer cannot enforce those store writes, and a partition resolver must not block legitimate read access. Keep this a direct protected hook, not a general policy registry.

Stock migrations use `uuid()`/`foreignUuid()`, `jsonb()` and nullable participant columns respecting `Builder::$defaultMorphKeyType`. Declare `participant_type` explicitly and choose `uuid`, `ulid` or `unsignedBigInteger` for `participant_id` with a migration-local match; do not use morph helpers that automatically add an index already covered by the composites below. Preserve configured table/connection names and message UUID ordering. On messages, add nullable UUID `approval_claim`, nullable timestamp `approval_claimed_at`, and boolean `has_replay_blocks` defaulting to false and maintained by the store. Keep replay content at `steps[].replay_blocks`; do not move/truncate payloads or change documented message accessors.

Constrain `conversation_id` to the configured conversations table with `cascadeOnDelete()`. Upstream deletion leaves messages behind; `latestConversationId()` queries messages alone, so `continueLastConversation()` can resume a deleted conversation and append to its invisible orphaned history. The foreign key fixes ownership at storage rather than adding joins or model-only cleanup. Create a new conversation before its messages inside the atomic turn transaction. For existing schemas, document resolving orphaned rows before adding the constraint; do not silently delete or reassign historical data. Partitioned schemas use the corresponding same-partition composite foreign key.

Indexes follow real query predicates:

- Message history/pagination: `(conversation_id, id)`.
- Conversations for a participant: `(participant_type, participant_id, updated_at)`, preserving upstream's index for `HasConversations::conversations()` and recency queries.
- Latest conversation lookup is on the **messages** table: `(participant_type, participant_id, agent, id)`.
- Paused/replay work: conversation plus the applicable status/replay predicates, then ordering as needed; validate plans and avoid redundant indexes.
- Partitioned application schemas prepend their partition column to these indexes and enforce matching ownership in foreign keys. The stock migration stays unpartitioned, as Permission's does.

InnoDB appends the primary key to secondary indexes; PostgreSQL does not, and SQLite's UUID primary key is not its rowid. Do not infer that every engine needs an identical redundant index or always sorts. Use EXPLAIN for the actual queries on supported drivers.

### Atomic turn storage

An ordinary upstream turn writes two messages and touches the conversation twice; writes are per turn, not per token. Add an optional `StoresConversationTurns` capability implemented by the database store:

```php
public function storeTurn(
    string $conversationId,
    ?string $newConversationTitle,
    ?string $participantType,
    string|int|null $participantId,
    AgentPrompt $prompt,
    AgentResponse $response,
    ?Throwable $exception = null,
): StoredTurn;
```

`StoredTurn` contains the conversation ID and nullable user/assistant message IDs. A non-null title requests creation with the supplied ID; preserve the pending ID already surfaced to stream protocols. Derive the user message from the prompt and omit it on approval resumption. One short store-owned transaction creates the conversation if necessary, stores the user/assistant or folds the paused row, cleans eligible replay data, and touches the conversation once. Generate the title before opening the transaction. Mutate agent/response stored IDs after commit. Apply the same atomic boundary to failed-turn persistence where a turn is recordable.

Custom stores without this capability retain the existing sequential storage API. Do not wrap generic middleware in SQL facade transactions or impose SQL behavior on custom stores. Standalone store methods retain their behavior; internal helpers avoid repeating touches inside storeTurn.

### Claim approved work before executing tools

Add `Contracts\ClaimsPendingApprovals` and readonly `Approvals\ApprovalClaim` containing message ID and token:

```php
public function claimPendingApprovals(string $conversationId, array $toolCallIds): ?ApprovalClaim;
public function recordApprovalResult(ApprovalClaim $claim, ToolResult $result): void;
```

Carry the claim on `AgentPrompt`, as with run context. Resolve and validate the exact paused row's pending-call set in the same short transaction that conditionally sets a token where the row is paused and unclaimed. Eager validation before a stream starts does not replace this check at actual execution. No claim for an unconsumed stream; no tool/network work while a DB lock is held.

| Event | Required behavior |
|---|---|
| Claim succeeds | Execute that owned approval continuation. |
| Already claimed/not resumable | Reject before tool side effects with the appropriate approval exception. |
| Each completed/rejected tool | Merge its outcome under the claim token and row lock before starting the next side-effecting tool. Preserve edited arguments and result IDs. |
| Completion, new pause or caught failure | Fold under the same token, preserve recorded outcomes and new steps, set status and clear the claim. Represent a started but unrecorded call as outcome unknown; do not make it executable again. |
| Ordinary prompt while claimed | Use persisted outcomes plus in-memory “in progress or outcome unknown” results. Do not clear the claim, overwrite its steps or infer the owner died. Late owner results remain valid. |
| Worker/process crash | Retain the claim. Reject automatic resumption of that claimed row; later history reports unknown outcomes rather than repeating effects. |

Do not implement heartbeat/expiry/retry machinery for claims or promise exactly-once external effects. Existing tool-call IDs remain usable as provider/application idempotency keys. `settleAbandonedToolCalls()` only synthesizes messages; it does not prove another run is dead. Non-claimed abandoned pauses retain upstream behavior.

Every read-modify-write of steps, including replay cleanup and legacy approval-result methods, must respect active claims and merge under a short row lock. Cleanup selects only rows with replay data and no active claim, then clears the marker atomically; previously emptied paused rows must not be fetched and decoded forever. Keep queries bounded to their actual conversation/partition.

### Tests

Port store/model/middleware/approval tests and extend them for partition reads/writes/restoration, unresolved scope, configured tables, UUID participants, rollback without partial turns, pending-ID preservation, one touch, concurrent double resume, result recording before the next tool, ordinary prompt racing a live claim, cancellation after an external effect, re-pause and retained hard-crash claims. Cover conversation deletion removing its messages, latest-conversation lookup selecting a remaining conversation or none, and explicit continuation of a deleted ID never recovering old history or persisting orphan messages. Verify the configured-table foreign key and atomic insertion order. Test custom stores with/without the capabilities and stateless resumption. Exercise locking on real supported databases, not only SQLite or facade mocks. Do not simulate a distributed recovery service that the design does not contain.

## 5. Provider I/O and Bedrock

Remote-file fetching uses Hypervel's Http destination policy, preserving host allowlists, redirects and DNS pinning. Keep the public custom resolver seam. Hooked DNS itself is not a blocking defect. Test redirects/private destinations and resolver overrides through the framework policy.

Bedrock's current assume-role map hashes credentials plus all additional configuration: trace headers multiply retained STS clients, while timeout differences can reuse the first client's timeout. AWS default-chain creation also repeats work and its memoized pending promises cannot be concurrently driven by different coroutines.

- Cache **fulfilled credentials** for bounded fixed configured identities. Coordinate refresh with the existing coroutine Mutex; recheck after acquisition, release in finally, and preserve timeout/cancellation. One owner creates and drives each STS/default-chain refresh; waiters receive their own settled promises. No queue dependency, distributed cache or cross-process refresh lock.
- Keep on-demand/BYOK credentials operation-local. Credential identity includes credential-relevant role/source/region configuration, not per-request trace headers. A fresh refresh client avoids retaining the first request's timeout.
- Create operation-owned Bedrock SDK clients/commands but route their `http_handler` through a named Hypervel Http connection. The gateway adapter preserves signed PSR requests/bodies, AWS error-array conventions, stats callbacks, retries and event-stream decoding. Use synchronous coroutine I/O and settled per-call promises for synchronous SDK calls; merely wrapping `buildClient()` in AWS's default `sendAsync` handler is insufficient.
- Put request headers on the command's cloned handler list and timeouts in operation/command options. Reuse the underlying HTTP transport, not a second pool of credential-bearing SDK clients. Keep the AWS-to-Http adapter beside Bedrock because it has one consumer; no speculative shared integration package.
- Test concurrent cold refresh, refresh expiry/failure/cancellation, header/timeout independence, static credentials, default chain, assume role, buffered/streamed requests and quiet-upstream cleanup. No live paid provider is needed for ownership tests.

## 6. Caches, schema and aggregation

### Embeddings

Use one effective identity per operation: optional scope, provider name/driver, endpoint, model/deployment, dimensions, API version and result-affecting options/account routing. Include a non-secret account/project identity when available; otherwise hash credentials cryptographically once per operation. Never expose raw keys. Same-name dynamic providers within one scope must not collide across accounts; provider-file inputs are account-owned too.

Compute normalized options/identity once per batch. Use seeded `xxh128` for internal input/key hashing, retaining cryptographic hashing at credential trust boundaries. Use `($scope === null ? '' : $scope . ':') . 'hypervel-embeddings:v1:' . $digest`, with a fixed-length digest of the structured effective identity/input. The trusted integration supplies its logical namespace; do not put secrets there. Keeping scope outside the digest lets integrations remove their owned cache entries. Document that literal key layout, preserve central/scoped separation, and never concatenate unhashed variable input fields ambiguously. Trace headers are not result identity.

Encode vectors losslessly as little-endian float64 with a versioned base64 envelope. Raw packed bytes are incompatible with Redis JSON serialization; base64 is portable. Apply it to individual and whole-response caches, preserving ordering, duplicates, partial hits and response metadata. Keep the public array<float> result and existing cache controls; use a new key version rather than misreading old JSON values. No float32 precision change or codec registry.

Use existing cache multi-get/put APIs. Standalone Redis already has a single-script putMany; phpredis RedisCluster has no pipeline, and MULTI still exchanges a reply per command. Retain sequential Cluster TTL writes rather than multiplying pooled clients or forcing embedding keys into hot hash slots. Do not claim MGET across random Cluster slots is one server round trip.

### Stable metadata, skills and response work

- Extend existing `Hypervel\Support\ClassMetadataCache` in `src/reflection` with recursive trait membership and reuse existing reflection/attribute metadata. Cache class facts, not runtime agent options. Register static cleanup.
- Configure `ai.skills.cached_paths`, defaulting to `[resource_path('skills')]`. Cache discovery/parsed immutable directory skills only for this finite boot-configured set, with flush/reload. Closure sources, arbitrary directories and caller-supplied skills retain dynamic semantics. Add `resources/skills/**` to watcher defaults and document custom watch paths.
- Consolidate repeated stream-event aggregation into one owned aggregation pass, preserving text/reasoning block boundaries, citations, usage, step state, stored IDs and repeat iteration. Avoid duplicate Collections/large intermediate arrays. Preserve the public buffered/replay contract; do not claim streaming memory is constant while the API retains all events.
- Guard optional event construction with `hasListeners()`, excluding broadcasts/jobs where dispatch is the actual side effect.
- Remove obsolete AnyOf availability/string-class checks and `default: null` stripping. Generate schema fake data via `Type::toArray()`, serialize once and recurse, supporting UnionType/AnyOf and explicit null defaults while preserving fake return conventions. Add a small `ObjectType` property accessor for the MCP tool adapter's existing Type-object consumer; do not bind a closure into protected state.

## 7. Optional parallelism and broadcasting

Keep sequential tools as default. Add `#[ConcurrentTools(max: N)]` with positive bounded concurrency through framework primitives. Preserve returned tool order, approval decisions, coherent AgentTool streams and parent invocation IDs. Joined tool children execute under the operation's exact context runner, plus the required parent/repair state: reuse the captured runner for lazy operations, or call the capture hook at fan-out for synchronous operations. Enabling concurrency must not change the same tool's contextual permissions. Apply the runner inside child execution after framework startup propagation; restore on exit and cancel/join owned children on failure or cancellation. Separately launched background work retains normal framework propagation. Child database work has its own connection and does not inherit the parent's transaction.

Keep title generation serial by default and add one opt-in boolean adjacent to `ai.conversations.generate_title`. Start concurrent title work only when the main call/stream is actually consumed and conversation persistence is eligible. Await it before the short persistence transaction; own its cleanup. A failed main call can still incur title cost when enabled, which needs documentation. Never start a paid title request for an unconsumed stream.

Immediate broadcast is the approved default. Keep queued per-event delivery and whole-generation queues for their retry/durability/worker separation semantics. Add config-only opt-in delta batching with time and byte limits. Coalesce only adjacent compatible text/reasoning deltas with matching invocation/message/block identity; flush before structural events, completion and errors. Preserve order and scope. A time bound must also flush during upstream silence: use a bounded owned coroutine/channel/timer only when batching is enabled, and cancel/join it on every exit. Do not create a generic scheduler or add per-agent tuning machinery. Queued delivery retains the queue's ordering/retry semantics rather than claiming stronger ordering guarantees.

## 8. MCP integration boundary

Upstream AI lists `laravel/mcp` in development dependencies and suggestions, not runtime requirements. Ordinary agents, local AI tools, providers, streams, conversations and approvals run independently. The owner wants the entire MCP package audited and ported in a separate session with equivalent care.

Keep `Tools/McpTool`, `Tools/McpServerTool`, `Tools/Concerns/NormalizesMcpResult`, `Schema/SchemaNormalizer` and both MCP adaptation branches in `GeneratesText::resolveTool()`. Their `Hypervel\Mcp` references remain optional and lazy, like upstream's references; no root runtime/development dependency on an unavailable package, eager resolution or placeholder production classes. Ordinary tool normalization, including nested ToolSearch, must work with MCP absent. Retain the optional feature suggestion with documentation that MCP tools require the separately available package.

Fix `McpServerTool::handle()` using `CoroutineContext` keyed by its existing `MCP_REQUEST` constant (`Hypervel\Mcp\Request::class`), following the existing HTTP RequestContext's class-key convention, not global `Container::instance()`. Save the prior entry, install the call's request, and restore/forget it in finally after result/generator consumption. The later MCP provider resolves its Request from this same context without caching the resolved request for the worker or across nested calls. This narrow bridge needs no new general container override mechanism or additional unavailable context class.

Port adapter tests/fixtures alongside AI, preserving their assertions. Run dependency-independent normalization/schema and MCP-absent behavior now. Tests requiring the actual package use an explicit dependency-availability check before loading MCP-derived fixtures, like the existing optional filesystem adapter tests; report them as unverified until the separate port supplies that dependency. This is the owner's deferred dependency, not permission to skip tests for implemented AI behavior. Do not weaken mixed test files' non-MCP coverage or pretend substitute MCP classes prove integration correctness.

Preserve the adapters' upstream object/class-string boundaries. Where analysis cannot resolve the absent optional MCP symbols, use line- and identifier-scoped `@phpstan-ignore` annotations with the reason `optional MCP package`, following `FilesystemManager`'s optional-adapter precedent. Apply only to actual diagnostics after tracing the affected types; do not preemptively suppress every dynamic call. No neon patterns, analysis exclusions, stub files, eager production dependency or placeholder classes. Remove annotations made obsolete by the MCP port when that dependency becomes available; track this with its integration verification in `docs/todo.md`.

Track the separate MCP port and integration verification in `docs/todo.md`. Its audit must cover shared request binding in MCP's server and nested tool/resource paths, lazy context ownership, connected-client/session lifetime, HTTP/stdio cleanup and bounded reuse of genuinely reusable sessions. Static credentials alone do not prove that a server session contains no caller-owned state. Do not choose a session-pooling design before that package's investigation.

## 9. Package wiring and documentation

- Wire root and split manifests: PSR-4, AI functions, replace entries, providers/aliases, actual runtime dependencies and optional feature suggestions. AI uses discovery, not DefaultProviders. Remove redundant concrete self-singleton bindings. Do not register upstream's currently unregistered ChatCommand without a deliberate decision.
- Symfony YAML is runtime. AWS remains optional for the split AI package with its real minimum (`3.369.1` in the reference); update the root minimum consistently using Composer after checking current compatible versions. No MCP dependency is needed for this port. Do not add redundant bundled extension requirements or compatibility branches for unsupported library versions.
- Add `Filesystem::fileExists()` and `directoryExists()` across contracts, concrete adapters, pooled/scoped proxies, instrumentation decorators and fixtures; `exists()` retains file-or-directory semantics. Then remove the AI capability/size fallback.
- Ship typed config for all consumed settings, including conversations connection/tables/title behavior, embeddings TTL, remote allowed hosts, concurrency/batching options and skill paths. Defaults belong in config or one owning optional fallback constant, respecting shallow merges of replaceable nested records.
- Port Pest/double tests to PHPUnit/Mockery using framework base cases. Put workbench classes/routes in package Fixtures and load them only where required. Preserve meaningful test coverage and approved exclusions; no blanket workbench/test omission.
- Register process-global optional-package cleanup via grouped `callIfExists()` calls in `AfterEachTestSubscriber`; instance/context ownership is already reset by the framework. Add facade/type fixtures where public surfaces need them. Do not invent production observability APIs solely to inspect private test state.
- Port the upstream AI SDK page already imported at `src/docs/ai-sdk.md` in place, after implementation is complete. Read it in full and update namespaces, installation/configuration, links, examples and behavior against the final code; retain applicable upstream explanations and MCP integration content, explaining its optional package requirement. Make the MCP section reflect the package's actual availability at merge: do not link to a missing MCP documentation page or present an unavailable package as installable. Finalize those links when the MCP package and page land. Add BYOK hooks, partitions and schema examples, custom stores/claims, unknown approval outcomes, queue choices, opt-in concurrency costs, stream context, cancellation limits and session pinning. Update the documentation navigation and related database, HTTP streaming, JSON schema, filesystem and watcher pages. Brief README differences and porting entries link to this canonical page. Remove all obsolete listener/config/shim descriptions after their code is removed; do not leave the imported page in its Laravel form or create a second AI documentation page.

## 10. Verification and performance acceptance

Run each changed/new test file immediately, then affected suites. For final broad framework changes use the required full verification workflow (`composer fix` where appropriate, including `composer test:parallel`); run `composer test:testbench` after Testbench changes. PHPStan checks source, not tests. External-service tests use existing isolation/skip traits and are added to the correct service workflows; real database locking tests cover MySQL/MariaDB/PostgreSQL and relevant SQLite behavior. Redis Cluster suites follow their serial isolation rules. Local loopback fixtures use assigned ports, bounded waits and unconditional cleanup.

| Concern | Meaningful acceptance evidence |
|---|---|
| Worker/coroutine ownership | Interleaved same-name providers, fresh agents/configured tools, nested parent/repair restoration, failure/cancellation cleanup |
| Deferred operations | Context A creation/B consumption, captured empty context, callbacks after completion, failover, abandoned generator disposal; consumer context restored |
| Persistence | Real atomic claims/rollback, no network inside DB transactions, stable IDs, indexed history/cleanup, no repeated empty replay decoding |
| Streaming | Incremental first-event delivery, slow-consumer bounds, errors not mistaken for EOF, silent-upstream close/cancellation, no orphan producer/lease |
| Embeddings | Provider/account/scope isolation, vector-builder default path, partial/duplicate batch hits, lossless codec across cache serializers, whole-response metadata |
| Public parity | Existing extension subclasses, provider formatting/string conversion, protocols/replay, fakes/macros/commands/jobs, optional split-package installs |
| Optional concurrency | Maximum active tools, stable results, exact operation context in joined children, child ownership, stream coherence, serial defaults, no title work before consumption, timed batch flush while upstream is quiet |

Add `tests/Benchmarks/Ai` for AI-level scenarios and `tests/Benchmarks/HttpTransport` for transport scenarios, sharing origin fixtures where applicable. Measure cold/warm verified-TLS separately, paced/unpaced streams, complete start-to-first-event latency (including admission), CPU, RSS/heap, file descriptors, pool waits/hold durations, turn persistence, history/tool payload sizes, tenant/account churn and long-running cleanup. Count actual simultaneous active transfers, not total scheduled requests.

Prior probes establish mechanisms, not acceptance thresholds: larger StreamHandler reads delay SSE; named transport reuse avoids repeated TLS setup; shared pending AWS promises are unsafe; old aggregation creates unnecessary passes/allocations; raw vector bytes fail Redis JSON serialization. Measurements mixed different TLS/setup conditions and machine load, so do not publish their ratios as expected gains. Notify the owner before benchmarks requiring an idle machine and wait for an agreed idle window. Functional tests use synchronization, not benchmark throughput assumptions. No brittle timing thresholds in CI.

Acceptance requires no unbounded tenant-derived worker caches, leaked credentials/request state, orphan tasks or leases, avoidable round trips, or unexplained throughput/memory regression. Optimize measured costs at their owning layer; do not weaken API behavior, TLS verification or correctness to improve a benchmark.
