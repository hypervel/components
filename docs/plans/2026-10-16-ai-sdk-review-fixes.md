# AI SDK review fixes

## Purpose and boundaries

Correct the verified execution, history, frontend-adapter, file, telemetry and demo defects in the AI SDK branch. Keep Laravel's public APIs and extension points, preserve the existing coroutine ownership and storage guarantees, and remove avoidable repeated work. This plan includes the related Sentry and Telescope fixes; package boundaries do not exclude verified defects.

The approved contract change is `StoreProvider extends FileProvider`. The approved schema change is to prefix the conversation migration's explicit index names with their configured table names. Neither approval permits other API or schema changes. Preserve all other public and inherited protected signatures unless a further difference is explicitly approved.

Work in the AI worktree on `feature/ai`. This is the implementation plan for these fixes, not another package-port or upstream-sync pass. Do not change sync baselines or rewrite the completed port plan. Consult package READMEs and the repository rules when implementing. Keep the existing documented nested-tool-argument limitation and dependency regression skips; these findings do not authorize dependency workarounds.

## 1. Invocation ownership and safe failover

### 1.1 Stop whole-run failover after a tool starts

**Problem:** buffered prompts can execute a tool, encounter a retryable error on a later model step, and restart the whole run against another provider. That can repeat side effects. `RunContext::invokingTool()` is the shared entry point for ordinary tools and streaming sub-agents. Remembering middleware currently clears the run context after recording a failed turn, before terminal failure reporting and the outer failover loop inspect the exception.

**Files:** `src/ai/src/Promptable.php`, `Prompts/AgentPrompt.php`, `Gateway/RunContext.php`; verify `Providers/Concerns/GeneratesText.php`, `StreamsText.php`, `Middleware/RememberConversation.php`, `Gateway/Concerns/InvokesTools.php` and both tool-execution paths in `Gateway/TextGenerationLoop.php`.

- Set a boolean on RunContext at the start of `invokingTool()`, before its `hasListeners()` early return. This is execution state, independent of telemetry listeners.
- Make `AgentPrompt::willRetry()` the single predicate used by failed-turn persistence, terminal failure events and buffered provider failover. It requires a retryable exception, another configured attempt, and no started tool.
- Before `AgentPrompt::setRunContext(null)` detaches a context, retain its tool-start flag on the prompt. Attaching a fresh context resets that retained flag. Stream re-iteration clones the pristine factory context and must not inherit the previous iteration's flag.
- Keep this as two booleans with ordinary accessors; do not add callbacks retaining prompts, a shared state registry, exception wrappers or exception-type changes.
- `Promptable::withModelFailover()` is private and has one caller. Make its callback construct the attempt's AgentPrompt; the loop then calls that prompt's provider and consults that same prompt on failure. The approval-only path constructs one prompt and invokes its provider directly. Keep the existing streaming rule that no failover occurs after any event has reached the consumer.
- Rethrow the original exception when retry is forbidden. The remembering middleware must persist completed steps and tool results, and `AgentFailed` must fire once rather than reporting a failover.

The predicate's essential logic is:

```php
return $exception instanceof FailoverableException
    && ! $this->isFinalAttempt
    && ! ($this->runContext?->hasInvokedTool() ?? $this->toolWasInvoked);
```

Use names consistent with the final RunContext accessor. Do not serialize live RunContext or pending-title resources into queued listeners.

### 1.2 Capture conversation selection and history once

**Problem:** a lazy stream retains its prompt text but can later read a different conversation or participant from the same agent. The multi-provider stream constructs each provider attempt inside its generator, so capturing only in `RememberConversation::handle()` is too late. Saving a newly created conversation also unconditionally changes the agent's selection, overwriting a newer explicit selection.

**Design:** preserve the original agent identity. Keep operation-owned data on the prompt/objects captured by the operation, not on worker-shared middleware or in a process-global registry.

1. At `prompt()` and `streamPrompt()` setup, capture the conversation ID and participant for agents accepted by `RememberConversation::appliesTo()`. Use one small internal `Support/ConversationSelection` object shared by all attempts of that operation. The conversation ID advances after creation; the participant remains the captured participant. Construct it directly, not through a container binding.
2. For every `Conversational` agent, call and materialize its existing `messages()` method once at public-call setup. Keep this history snapshot separate from ad-hoc `AgentPrompt::$messages`; the provider still concatenates ad-hoc history, conversational history and the current user prompt in that order. Approval continuations still append no new user prompt. Reuse the snapshot across failover attempts and failed-stream re-iterations.
3. Add optional trailing AgentPrompt constructor arguments for the selection and history snapshot, backed by nullable properties declared with `null` defaults. Use `null` to distinguish an absent history snapshot from a captured empty array. Direct provider and middleware entry points initialize missing values before executing downstream work. Pass both through `revise()` and `withTools()` when they construct a new prompt. Exclude both from `__serialize()`, like live run/title resources: queued listeners must not receive another full history copy. Explicit property defaults keep them initialized after unserialization; a subsequent direct execution captures fresh values.
4. Read captured selection in remembering middleware, `GeneratesText::runContextFor()` and the existing-conversation metadata path in `StreamsText`. This covers pending IDs, participant keys, title eligibility, claims, response metadata, failure persistence and successful persistence. Keep `shouldRemember()` and `shouldRememberTurn()` signatures and dispatch intact, but change their decision inputs to the captured selection: having a participant means its participant is non-null. Adapt Hypervel's internal `startsConversation()` helper to use the prompt's selection rather than the mutable agent.
5. After a new conversation is stored successfully, advance the operation's selection to the **ID returned by the store**. Update the original agent with `continue()` only if its live conversation ID and participant still match the selection's pre-advance values. Do not change the agent or advance the selection after failed persistence.
6. Keep the returned/stored ID when a failed iteration is later retried. Selection advancement is separate from the immutable history snapshot. Clear the middleware's pending ID after creation as today, so no duplicate conversation or title is created.

Expose the captured selection through an internal AgentPrompt accessor for Sentry's `src/sentry/src/Features/AiIntegration.php`. Its invocation-start path must use the captured participant/ID, and its failure and abandonment paths must use the prompt's selection when finishing spans. Failed-turn persistence may assign an ID without updating an agent that was explicitly switched elsewhere. Fall back to the agent only when the selection object is absent, not when a present selection has a null conversation ID. Preserve successful response-based association and existing nested-span ownership.

`AgentFailedOver` has no prompt. Its finishing path must retain the invocation's existing conversation association instead of re-reading the mutable agent: a retryable attempt has not persisted a new conversation. Do not expand that public event or add another Sentry registry to obtain an ID it does not need.

The selection has only these responsibilities:

```php
class ConversationSelection
{
    public function __construct(
        public ?string $conversationId,
        public readonly ?object $participant,
    ) {
    }
}
```

Add normal method documentation and internal visibility guidance in implementation. Do not clone agents, temporarily change their selection around yielding work, introduce a trait-specific history reader, or bypass application `messages()` overrides. Contract-only remembering agents are supported too.

**Lifecycle and cost:** history capture is invocation setup, alongside provider selection and tool resolution. A failure there surfaces directly before provider execution, without constructing an AgentPrompt solely to emit `PromptingAgent`/`AgentFailed`. Direct provider calls retain their existing failure boundary. Single-provider streams already materialize history before consumption and reuse it on failed re-iteration; completed streams replay cached events. The multi-provider path adopts that same snapshot behavior. It moves its initial history read to factory time and eliminates repeated reads across attempts; it does not add a normal-run query. An unconsumed stream retains its snapshot until released, as single-provider streams already do.

### 1.3 Reusable anonymous agents and valid provider names

- Materialize the constructor-supplied `messages` and `tools` iterables in both `AnonymousAgent` and `StructuredAnonymousAgent`. The structured class has its own constructor; fixing only the parent misses it. Keep the iterable parameter/property contract and constructor names. Arrays remove exhausted-generator reuse and generator serialization failures without claiming every arbitrary tool object is serializable.
- In `AiManager::build()`, reject an explicitly empty provider name before registering it in coroutine context. `instance('')` intentionally selects the default provider, so it cannot represent an on-demand provider name. Preserve `instance()` behavior and generated names for unnamed builds. Use `InvalidArgumentException`; do not add a name registry or broaden validation beyond the verified invalid case.

### Verification

Extend the existing agent event, remembering, stream-failover, prompt, fake/queue and manager tests:

- A tool completes, the following model step throws `RateLimitedException`, and a fallback is configured: no fallback request, one tool execution, original exception, partial turn persisted, one terminal failure event. Exercise the no-tool-listener path so the latch cannot depend on event subscriptions. Retain the existing pre-tool failover coverage.
- A stream with a fallback configured is created for conversation/participant A, the agent is switched to B, then the stream is consumed: A's history reaches the provider and A owns persistence and response metadata. Include a custom `messages()` override in the regression rather than a separate test merely checking method dispatch.
- A new conversation completes after the agent was switched to B: B remains selected, while the operation returns its own created conversation. Retain the existing retry/store-assigned-ID and failed-persistence tests.
- Extend Sentry's existing conversation coverage in `tests/Sentry/Features/AiIntegrationTest.php` to verify captured selection at invocation start and its late ID at failure settlement after the agent switches elsewhere. Include failure/abandonment/failover routing in the existing terminal tests so no finishing path substitutes the agent's newer selection. Reuse the current event fixtures; no real provider or new test framework is needed.
- Verify a contract-only remembering implementation and direct entry paths use the same captured values; use focused fixtures and existing tests, not a second middleware test framework.
- Preserve ad-hoc/conversational history concatenation, empty snapshot semantics, prompt revision data and queued-listener serialization. A history setup failure propagates unchanged without provider requests or provider lifecycle events.
- Reuse anonymous agents supplied generator messages/tools, including the structured constructor; include the existing queue serialization path where applicable. Reject `Ai::build(['name' => '', ...])` without falling back to the default provider.

## 2. Frontend tool history and approval controls

### 2.1 Preserve paired tool-call IDs

**Files:** `Gateway/OpenAi/Concerns/MapsMessages.php`, `BuildsTextRequests.php`, their `Gateway/Xai/Concerns/` counterparts, and `AgentUserInteraction/AgentUserInteraction.php`, all under `src/ai/src`.

- In every function-call and function-output mapping, including stateful continuation bodies, use `resultId ?? id`. Preserve provider-issued result IDs when present and the existing reasoning/item-ID rules. Azure inherits the OpenAI mapping.
- Stop assigning an AG-UI tool message's unrelated message `id` to `ToolResult::resultId`. Its `toolCallId` already pairs it with the assistant call. Both sides then fall back to the same ID, as Vercel history does.
- Do not add provider metadata solely to carry a second ID or invent a `call_` prefix requirement. The official Responses input schema declares `call_id` as a string, without that prefix restriction. This is schema evidence, not live-service verification.

### 2.2 Round-trip failure and denial

**Files:** `Vercel/Vercel.php`, `AgentUserInteraction/AgentUserInteraction.php`.

- Vercel hydration handles both `ToolResultMessage` objects and stored `ConversationMessage` results. For failed results, emit `state: output-error` and string `errorText`, not a successful `output-available` part. Follow the existing Vercel stream protocol's error-text policy: use a string result, otherwise `The tool call failed.`. Remove incompatible stale output/approval fields. Denial remains `output-denied` and takes precedence.
- Preserve the inherited protected `applyUiToolOutput()` signature. Add a small adjacent failed-output helper shared by the two hydration input paths rather than adding a parameter that breaks subclass overrides. Keep existing success/denial dispatch through the original helper.
- In reverse Vercel conversion, derive `failed` from `output-error` and retain the existing denied flag. Failed text comes from `errorText`.
- In reverse AG-UI conversion, restore denial from `metadata.denied === true` and failure from a string `error` when not denied. A denial also has an `error` field in outgoing AG-UI; do not turn an ordinary denied result into an independent execution failure. Preserve string error text, including an empty string, rather than using truthiness to decide whether the result failed.

### 2.3 Suppress stale approval actions

**Files:** `Models/ConversationMessage.php` and both adapters above.

Add one model predicate for whether stored approvals remain available: status is `MessageStatus::Paused` and `approval_claim` is null. Both adapters combine that predicate with `PendingApproval::isPending()` for each tool call. Unclaimed paused calls retain their controls; failed, completed or claimed rows do not offer an approval action. Vercel may retain an `input-available` part without `approval`; AG-UI omits the interrupt. Do not manufacture tool results or copy the store's interrupted/unknown-outcome messages into adapters. This is presentation of stored state, not an authorization check or an extra database query.

### Verification

- Extend `Feature/UiMessageTest.php` and `Feature/AgentUserInteractionMessageTest.php` with failed/denied/success round trips and stored-row approval availability. Update existing pending fixtures to declare a paused status, rather than weakening the new predicate for incomplete test fixtures.
- Send Vercel and AG-UI reconstructed history through the existing OpenAI and xAI message-mapping tests. Assert matching non-null call/output IDs; retain a provider-issued result-ID case. Cover continuation extraction in the existing gateway tests without duplicating every adapter/provider combination.
- Use the existing pending-approval examples for paused/unclaimed, claimed and failed rows. Verify controls disappear while recorded results remain intact.

## 3. Bound preliminary sub-agent output

**Problem:** `Gateway/TextGenerationLoop::preliminaryToolResults()` emits full accumulated text at a fixed byte interval, so retained/transmitted snapshot bytes grow quadratically. Every non-text event currently forces another snapshot too. It also discards `StreamStart` events, although `Streaming/Events/TextDelta::combine()` uses them to separate steps with blank lines.

- Retain text deltas and StreamStart boundaries for the existing combine method; discard unrelated events from this buffer.
- Emit only after text has grown by a threshold that grows with already-reported text:

```php
$threshold = max(self::PRELIMINARY_OUTPUT_BYTES, intdiv($reportedAt, 4));

if ($written - $reportedAt < $threshold) {
    continue;
}
```

- StreamStart, reasoning and tool events must not independently trigger snapshots. The completed ToolResult already carries the final result, so do not add a terminal preliminary flush.
- Reuse `TextDelta::combine()` for formatting; do not introduce a second incremental text assembler, a timer, configuration, or a different stream protocol. Keep completion, cancellation and concurrent child ownership unchanged.

**Tests:** extend `Unit/Gateway/TextGenerationLoopTest.php`. Bound the number and combined byte size of snapshots for a long sequence, including reasoning events after earlier text. Assert the final result is complete. For large output the geometric sum is approximately five times final text size, rather than increasing with every fixed-size chunk; use a tolerant linear bound, not an exact cadence assertion. Fix the existing step-joining test to include actual StreamStart events and enough text to produce progress. Short outputs need only their completed result; fixtures testing early output must cross the threshold. Retain `ConcurrentToolsTest.php`'s live-progress and cancellation coverage. These deterministic byte/count checks are sufficient; no new benchmark harness is required.

Adapt Sentry's nested abandonment fixture to a parent and child with enough text for progress; deeper sequential tool results no longer yield empty snapshots to the outer consumer. Keep its existing cancellation depth. Add one overlapping-stream regression: start two iterators, abandon the earlier one, then finish the later one. Assert that abandonment preserves the later active span and completion restores the transaction. Verify that disabling `AiTracingScope`'s existing out-of-order relink makes this regression fail, then restore it. Update the relink comment to describe overlapping spans.

## 4. Embeddings and numeric choice labels

### 4.1 Send requested dimensions where supported

**Files:** `Gateway/CohereGateway.php`, `Gateway/Mistral/MistralGateway.php`.

Include `output_dimension => $dimensions` in the authoritative request fields after provider options, except for the legacy fixed-dimension model families:

- Cohere: omit for `embed-english-*` and `embed-multilingual-*`; v4 and newer use the requested dimension.
- Mistral: omit for `mistral-embed*`; capable models such as Codestral Embed use the requested dimension.

Do not compare the request with the configured default: a configured/requested 512 can still differ from a provider's native dimension. Use the small family exclusions, not a model registry, extra request or embedding conversion layer. Preserve other provider options and float response handling.

Extend the existing Cohere and Mistral `Feature/Providers/*/EmbeddingTest.php` request assertions with capable and fixed-size models. Include a capable model where requested dimensions equal the configured default, preventing the previously identified omission. Use HTTP fakes, not paid API calls.

### 4.2 Reject invalid fake dimensions at the owning helper

Move the existing positive-dimension check and its RuntimeException message from `Gateway/FakeEmbeddingGateway::generateFakeEmbeddings()` into `Embeddings::fakeEmbedding()`. The documented custom fake closure calls this helper directly; native-dimension providers can pass zero. PHP's `range(1, 0)` otherwise produces two values instead of an empty vector. Keep validation in one place and preserve the existing fake customization API.

Extend `Feature/EmbeddingsFakeTest.php` to cover that documented helper path, including zero/negative dimensions and a normal positive vector. Do not add an empty-input gateway workaround: `PendingEmbeddingsGeneration` already rejects an empty input list.

### 4.3 Accept numeric-string option names

**Files:** `Classification/Choice.php`, `Classification/CollectionChoice.php` documentation, `Gateway/FakeClassificationGateway.php`, `Gateway/Concerns/AnswersQuestions.php`; inspect `Responses/Data/ChoiceAnswer.php` for matching key annotations.

PHP converts numeric-string array keys to integers. Accept those keys in Choice, retaining its minimum option count. Remove the now-invalid key-type rejection rather than adding a redundant int-or-string guard. Do not reject a sequential numeric map based on its descriptions: `['0' => 'Billing', '1' => 'Technical']` and a plain string list have the same PHP representation. CollectionChoice can legitimately construct that map using numeric-string labels and a description resolver. Document the names-to-descriptions shape instead of guessing the caller's intent. Keep chosen labels as strings when constructing a fake ChoiceAnswer; normalize the `array_search()` result at that boundary. Allow integer keys in relevant option/probability docblocks.

Cast choice criteria to an object when building the decisions request so labels `0`, `1`, etc. remain object member names instead of becoming a JSON list. Do not change the public PHP array representation or add a new choice class. The OpenAI mapper's `array_map()` string callback already receives coerced strings and does not need a TypeError fix.

Extend `Feature/CollectionDecideMacroTest.php`/`ClassificationFakeTest.php` where the behavior is already covered: numeric-string items such as `'0'` and `'1'`, with string descriptions supplied by a resolver, must yield the original selected item through the generated fake. Add one wire-format assertion in the existing decisions-provider tests for sequential numeric criteria. Keep ordinary named choices and duplicate-label validation intact.

## 5. Files and provider capabilities

### 5.1 Strict decoding and upload names

Create `Files/Concerns/HasBase64Content.php`, beside the existing file concerns, and use it for `content()` in Base64Image, Base64Document, Base64Audio and Base64Video. Preserve the public method signature and lazy decoding:

```php
$content = base64_decode($this->base64, true);

if ($content === false) {
    throw new InvalidArgumentException('Invalid base64 file content.');
}

return $content;
```

Strict decoding still accepts whitespace. Keep existing constructor empty-content checks; do not decode or cache a second full copy during construction. In Anthropic's `MapsAttachments`, replace the direct Base64Document decode callback with `content()` so text documents share the same validation. Paths that forward base64 unchanged need no new eager decode.

Append `->as($file->getClientOriginalName())` in Base64Audio and Base64Document `fromUpload()` overrides, preserving their current MIME selection. Other upload paths already preserve the name.

Cover the shared decoder's invalid input and whitespace/binary handling in `Unit/Files/Base64ImageTest.php`; do not repeat identical decoder tests in each consuming class. Add filename assertions to the audio/document upload coverage and exercise the Anthropic text-document consumer in its existing attachment mapping test. Do not build a generic codec abstraction.

### 5.2 Express the store provider's required file capability

Change only `Contracts/Providers/StoreProvider` to extend `FileProvider`, which already extends Provider. `Store` already requires both capabilities and concrete providers provide them. Existing intersections and provider declarations remain valid and match upstream; no type cleanup is needed.

Run the existing store/fake tests and static analysis. No README or porting-guide addition is needed: working Laravel store providers already satisfy both capabilities at the Store constructor, and this corrects the incomplete interface declaration. Do not create a second store-only capability architecture.

## 6. Credential redaction

### 6.1 Sentry attachment URLs

In `src/sentry/src/Features/AiIntegration.php::transformAttachment()`, remove userinfo, query and fragment from recorded remote/S3 attachment URIs using the existing Guzzle URI implementation:

```php
try {
    $content = (string) (new Uri($attachment->url))
        ->withUserInfo('')
        ->withQuery('')
        ->withFragment('');
} catch (InvalidArgumentException) {
    $content = null;
}
```

Remote attachment constructors do not validate URI syntax. Catch only URI parsing's `InvalidArgumentException`, following Sentry's `DataCollection/HttpUrlCollector`; malformed input must not make telemetry fail the application. `AiMessagePart::setContent(null)` omits content while preserving type, modality, filename and MIME metadata. Never fall back to recording the malformed raw URL.

Do not mutate attachments or provider requests, read file bytes, perform DNS/network I/O, or change the existing content-capture/PII opt-ins. Extend the existing metadata-only attachment test in `tests/Sentry/Features/AiIntegrationTest.php` with a signed URL containing userinfo, query and fragment, and a malformed URI. Assert the valid recorded URI retains scheme/host/path but no credentials; malformed content is omitted without losing the remaining metadata or interrupting tracing. Update `src/docs/sentry.md`: URL paths and filenames can still contain sensitive information; query/userinfo/fragment are removed.

Declare Sentry's direct `guzzlehttp/psr7` dependency using the framework's existing version constraint.

### 6.2 Telescope bracketed query keys

In `src/telescope/src/Watchers/Watcher.php::hideQueryParameters()`, decode the parameter name and compare its root before the first `[` with the configured hidden names. For example, both `access_token[]=secret` and `access_token%5B0%5D=secret` belong to hidden `access_token`.

Keep the existing pair-by-pair masking. Preserve original key bytes, repeated keys, unrelated values, ordering and fragments; do not use `parse_str()` and rebuild the URL. Extend `tests/Telescope/Watchers/ClientRequestWatcherTest.php`'s URL-redaction coverage, including a configured hidden array parameter. The shared watcher method covers its callers without per-watcher fixes.

## 7. Schema, documentation, types and demo

### 7.1 Distinct index names

In `src/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php`, prefix every explicit index name with its configured table name. Pass the captured table names into the schema callbacks. Keep existing short suffixes, columns, column order, foreign key and constraints unchanged; do not replace them with auto-generated names containing every column.

The longest default name is `agent_conversation_messages_conversation_replay_index`, within PostgreSQL's 63-byte and MySQL's 64-character identifier limits. This prevents schema-wide collisions with unrelated `participant_index` names and allows separately configured AI table pairs to coexist. Follow ordinary schema naming conventions; do not add a hashing/name-shortening subsystem for arbitrary application table names.

Add one collision regression in `tests/Ai/ConversationMigrationTest.php`: create an unrelated table with an index named `participant_index` in the same SQLite database, then run the AI migration successfully. Use the existing configured-table coverage; do not also add exact-name or new rollback assertions. This branch introduces the package into greenfield 0.4; edit its migration rather than adding a compatibility migration for previous Hypervel installations.

### 7.2 Canonical guidance and accurate types

- In `src/docs/ai-sdk.md`'s frontend and human tool approval sections, explain that stateless `withMessages($chat->history())` accepts client-supplied tool calls and arguments. Approval is not authorization: tools must authorize their actions and arguments. Show remembering-agent/server-owned pending calls where the application requires trusted stored history. Do not add signing machinery or imply stored history replaces tool authorization.
- Describe the failover boundary after tool execution and per-invocation history capture alongside their existing feature documentation. State that setup failures occur before provider lifecycle events. Keep documentation as targeted edits to the ported page.
- Update `ApprovalNotResumableException` to mention supplied history as an available continuation path.
- Correct `Contracts/HasTools` and `Contracts/Conversational` return docblocks to iterable element types, and participant wording in both the remembering contract and trait.
- Correct reranking document annotations to `list<string>` throughout contracts, provider traits, concrete/fake gateways, `PendingReranking` and `RerankingPrompt`. `Reranking::of()` still accepts a keyed Collection and normalizes it; do not narrow that Collection contract accidentally.
- In `Concerns/JoinsReasoning`, construct `new Collection($blocks)` rather than `Collection::wrap($blocks)` so a Generator is iterated, not treated as a single block. Extend the existing collection/array coverage in `Unit/Concerns/JoinsReasoningTest.php` with a generator case.
- Declare direct `nesbot/carbon: ^3.13.1` usage in `src/ai/composer.json`, matching sibling packages. This split-package file has no lockfile and may be edited directly. Do not add redundant root dependencies or commit composer.lock.

Correct stale comments/docblocks beside changed code. README differences describe lasting public contracts, not a catalogue of these bug fixes. Do not add TODO entries for fixes implemented by this plan or duplicate upstream dependency skips in documentation.

### 7.3 Local demo

- Add `touch workbench/database/database.sqlite` before migration in `dogfood/ai/README.md`; otherwise Testbench selects the in-memory testing connection when the configured file is missing. Keep the demo explicitly local and maintainer-facing.
- In `dogfood/ai/workbench/app/Tools/ConvertTemperature.php`, use an explicit `'celsius'` match arm instead of `default`. Unknown units fail naturally; retain both valid conversions and the schema enum.
- In `dogfood/ai/workbench/app/Console/Commands/AgentCommand.php::renderMarkdownDelta()`, strip model-supplied terminal controls before inserting trusted demo styling. Stream content is literal; do not escape its markup. When output is undecorated, add no ANSI styling.
- For formatted writes (block notes, tool cards, approvals, citations and errors), escape model/provider text with Symfony's `OutputFormatter::escape()` after stripping terminal controls. Preserve tag-like text visibly instead of deleting it. Share a private helper across those callers; `formatValue()` applies it to the final string, including JSON values. Block notes escape rendered Markdown separately from raw stream deltas.
- Add one focused demo renderer regression alongside `dogfood/ai/tests/DemoTest.php`, covering controls stripped and decorated/undecorated output without adding production APIs for testing. Existing Prompts tests cover the filter itself. The explicit match arm and README setup command need no new automated tests. No live prompts, authentication system, replacement browser demo or unrelated cosmetic cleanup is required.

## 8. Preserve literal text through Prompts rendering

Escaped Symfony tags currently become active styling after table/grid buffering, ANSI wrapping, or undecorated frame stripping. Fix the shared rendering boundaries before relying on escaping in the demo. Interpret intentional markup once; keep raw Stream and Task log content literal. Preserve existing public/protected signatures.

- Add `Utils::stripTerminalControls()` around the existing control filter, leaving markup and escapes untouched. Keep `renderTheme()` unfiltered and route normal frame writes through protected `Prompt::writeFrame()`: decorated output uses the normal write; undecorated output formats once, strips terminal controls, then writes with `OUTPUT_RAW`. Formatting must restore Symfony's escaped trailing backslash before filtering OSC sequences. Keep `prevFrame` unchanged. Raw Title/Clear writes strip controls explicitly when undecorated; direct stream, cursor and erase writes retain their existing paths.
- Pass the current output formatter from `Themes::getRenderer()` through an optional trailing Renderer constructor argument. Preserve direct construction without that argument. Clone and decorate lazily once per renderer, without changing application formatter settings or retaining worker state. Formatting separate values must not carry an unfinished style from one value into another.
- TableRenderer and GridRenderer use a decorated buffer with the configured formatter clone, then escape the already-formatted buffer lines before the outer write. This preserves both custom styles and literal tags.
- Derive ANSI wrapping's plain text and style positions from the same parsed segments. Keep the wrapper literal: it must not strip Symfony tags from raw stream/log data. Escape StreamRenderer and TaskRenderer log lines at their final formatted write.
- Extract existing wrapping, parsing and truncation helpers into `InteractsWithAnsi`, copied before editing. Renderer uses it without importing layout settings. `InteractsWithStrings` composes it and retains its concrete protected surface for Stream/Textarea/Task and custom consumers. Its measurement helper uses the configured formatter for Renderer hosts and preserves formatter-free behavior elsewhere. Keep this one type check: a base-class override is shadowed by renderer concerns re-importing the trait, while a second overriding trait creates collisions in custom compositions. Add no abstract requirement or base renderer box-width property. Point ANSI-specific tests at the extracted trait.
- CalloutRenderer shares a private content-wrapping helper: format with the configured formatter, wrap ANSI, then escape literal brackets. No public Prompt formatting API or new terminal parser.
- Measure formatted renderer text and DataTable's cached column metrics by formatting/unescaping with the configured formatter before filtering terminal controls. This includes custom styles and Symfony's escaped trailing backslash in OSC 8 sequences. Keep typed input and raw logs literal. Do not change the formatter-free utility's existing contract.
- Replace Renderer's use of the raw Truncation trait with a markup-aware method of the same signature, retaining its invalid-width exception. Plain text keeps the existing fast path. Formatted text uses the existing ANSI segments and a shared character-rebuild helper for wrapping and truncation; clip visible text, close styles/links, append the existing ellipsis and escape literal brackets. Search/MultiSearch/Suggest retain their raw-input trait. Correct measurement must not be followed by clipping inside markup.
- Extend existing Table, Grid, Stream, Note, Callout and Task coverage at final output boundaries. Cover intentional/custom styles alongside escaped text, undecorated output, literal complete/incremental logs, and style-position alignment. Add one DataTable case combining a short styled cell with a genuinely truncated styled cell, plus one long styled label through a normal prompt. Keep existing ANSI parser/wrapping tests and avoid a per-renderer test matrix.

Run the Prompts suite and demo tests alongside the existing checkpoint checks. These are framework rendering corrections, not dependency workarounds or new formatting configuration.

## Research and implementation references

These references establish the relevant protocol contracts; use repository source to verify implementation behavior:

- [OpenAI Responses input schema](https://developers.openai.com/api/reference/resources/responses/methods/create): function calls and outputs pair through string `call_id`; the function-call schema does not impose a `call_` prefix. DeepSeek and OpenAI-compatible mappings already use `resultId ?? id` locally.
- [Cohere Embed API](https://docs.cohere.com/reference/embed): `output_dimension` is available for Embed v4 and newer. Legacy English/multilingual model families have fixed dimensions.
- [Mistral embeddings API](https://docs.mistral.ai/api/endpoint/embeddings): `output_dimension` controls dimensions when the model supports it; omission uses its default.
- Local `Streaming/Events/TextDelta::combine()` owns step formatting; `Responses/StreamedAgentResponse` ignores preliminary results when collecting completed tool results.
- Local `Storage/DatabaseConversationStore::assistantTurnFrom()` owns synthetic interrupted/unknown results. UI availability checks must not duplicate that storage reconstruction.
- Local `src/testbench/src/Bootstrap/LoadConfiguration.php` selects the testing database when the configured SQLite file is absent.
- Local `prompts/src/Support/Utils::stripTerminalControls()` owns terminal control filtering; local `telescope/src/Watchers/Watcher` owns query masking.

## Implementation and acceptance

Implement coherent slices in this order, including each slice's tests and documentation:

1. Invocation ownership (including Sentry's conversation readers), failover, anonymous inputs and provider names.
2. Frontend tool history/approvals and bounded preliminary output.
3. Embeddings, classification, file handling and provider capability contract.
4. Sentry/Telescope redaction, index names, remaining documentation/types and demo corrections.

Before modifying a test, inspect its existing coverage. Extend existing tests and preserve applicable upstream assertions; use data providers only for genuinely parallel cases. Exercise observable outcomes, not private implementation details. Test each changed test file immediately. Test doubles and HTTP fakes cover provider requests; tests requiring separately configured services belong in `tests/Integration`, while self-contained SQLite and loopback tests follow existing placement.

Implement the complete set as one review checkpoint. Run each changed test file immediately, then run formatting, PHPStan and the affected suites sequentially from the components root (`composer lint:fix`, `composer analyse`, targeted PHPUnit/ParaTest as appropriate). Run the demo's own tests from `dogfood/ai` when it changes. Use the affected AI, Sentry and Telescope suites for final verification; do not run the entire framework suite merely because several optional packages changed. Do not benchmark obvious local correctness changes or introduce raw-results artifacts.

Self-review the complete diff through callers, callees, supported overrides, failure cleanup, serialization and coroutine ownership. Confirm no extra normal-run database queries, provider requests or repeated history scans were introduced; no worker-lifetime mutable invocation state was added; and no stale helper, import, test assumption or documentation remains. Check every changed protected signature against upstream. Investigate newly exposed substantive defects and include the necessary fixes, even in other framework packages; confirmed dependency defects follow the owner notification rule and receive no workaround.

After self-review, request review of the complete diff and resolve findings until signoff. Then commit logical groups of whole files with detailed commit bodies. Completion requires all planned behavior and meaningful regressions verified, documentation consistent, and no unresolved review findings or unapproved API/schema departures.
