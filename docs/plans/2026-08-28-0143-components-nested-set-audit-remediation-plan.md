# Nested-Set Audit Remediation Plan

## Scope and outcome

This plan resolves nested-set audit findings 78–80. The final package must make structural decisions from authoritative write-connection data, retain bounded bulk operations, fail loudly on incomplete persisted relation projections, and remove the coroutine-local freshness mechanism that cannot prove database freshness.

The target architecture is deliberately small:

- fluent mutation setup performs no database reads and rejects only incompatibility proven by already-loaded attributes;
- the model lifecycle owns one authoritative preflight for each existing participant immediately before a non-raw structural action;
- mutation-defining queries use the write connection;
- bulk repair and rebuild use one authoritative root preparation plus wholesale snapshots, never per-node preflights;
- raw actions keep caller-supplied coordinates while enforcing scope identity;
- ordinary read helpers retain loaded-model/read-connection semantics;
- relations distinguish unsaved parents from invalid persisted projections;
- concurrent writers still require application-level serialization.

No lock, clock, revision, registry, version stamp, cache, retry layer, or configuration option is added.

## Evidence and invariants

### Freshness state is not authoritative

`NodeFreshness` tracks a revision in coroutine context and model observations in a `WeakMap`. It is unsafe as a database-freshness gate:

- a transaction retry can retain an observation made from the rolled-back attempt because rollback does not rewind the revision;
- a child coroutine without copied context sees no freshness state and `NodeContext::isCurrent()` treats every model as current;
- another worker or process can mutate the same tree without changing the local revision;
- setup, lifecycle, and low-level move paths duplicate preflight reads solely to support the gate.

An existing-node move after this change has the same participant-read shape as the Aimeos reference: one source preflight, one target preflight, necessary writes, and the existing post-write refresh. Removing the gate therefore does not trade performance for correctness; it removes duplicate work around an invalid optimization.

### Required invariants

1. Every existing participant in a non-raw action is prepared once at that action's lifecycle boundary.
2. Preparation reads the exact persisted row from the write connection, validates scope before overwriting dirty attributes, then applies the snapshot.
3. A new participant performs no preflight read.
4. Fluent setup performs no hidden read. Known-invalid loaded facts fail immediately; incomplete facts defer to lifecycle preparation.
5. Raw coordinates are never replaced by a structural reload. Scoped raw nodes still identify a concrete tree.
6. Required post-write refreshes remain separate from preflight and are not counted as duplicate preparation.
7. Bulk read work remains bounded: repair/rebuild use root preparation plus wholesale snapshots, while evented cascade deletion reads one chunk at a time and never preflights each descendant. Existing per-node writes remain proportional to changed nodes.
8. Mutation-defining reads use the writer; ordinary reads do not acquire hidden writer reads.
9. No in-memory state is treated as proof that a database row is current across coroutines, retries, transactions, or processes.
10. The package does not serialize concurrent writers. Applications must serialize writes to the same table and scope.

## 1. Remove freshness machinery and retain structural identity

Delete:

- `src/nested-set/src/NodeFreshness.php`;
- `src/nested-set/src/NodeContext.php`;
- `tests/NestedSet/NodeContextTest.php` after migrating the structural-identity coverage;
- every `markTreeChanged()`, `markCurrent()`, `isCurrent()`, and revision-dependent branch;
- the direct `hypervel/context` requirement from `src/nested-set/composer.json`.

Move the pure store/table identity helper to `NestedSet`, alongside its existing model metadata helpers. It must normalize read/write connection aliases to the base connection and remain length-prefixed so connection/table boundaries are unambiguous:

```php
public static function structuralIdentity(Model $model): string
{
    $name = $model->getConnectionName()
        ?: $model::getConnectionResolver()?->getDefaultConnection()
        ?: 'default';
    $name = ConnectionName::parse($name)->base;

    return strlen($name) . ':' . $name . ':' . $model->getTable();
}
```

Update positional-query and same-store comparisons to use `NestedSet::structuralIdentity()`. Expose a readable structural description built from the same normalized base connection and table for error messages; the length-prefixed identity remains internal. Fold the identity tests into `NestedSetTest`.

## 2. Make lifecycle preparation the single action boundary

### Saving lifecycle ownership

The saving hook calls only `callPendingActions()`. That method owns scope validation and preparation:

```php
if (! $this->pending && ! $this->exists) {
    $this->makeRoot();
}

if (! $this->pending) {
    $this->ensureNestedSetScopeIsUnchanged();

    return;
}

$action = array_shift($this->pending);

if ($action === 'raw') {
    $this->ensureNestedSetScopeIsUnchanged();
    $this->ensureConcreteNestedSetScope('mutation');
} else {
    $this->prepareForNestedSetMutation();
}
```

Preserve the existing action dispatch and `moved` semantics around this branch. Default-root scheduling must happen before the no-action return.

Raw coordinates do not depend on current tree state, so raw actions skip structural reload. They still require a concrete scope because the written row must identify its tree. Moving this check from `rawNode()` to `save()` changes timing only; the exception and requirement remain.

### One local snapshot

Make `prepareForNestedSetMutation(array $extraColumns = [])` public because `QueryBuilder` must use the same authoritative preparation boundary for subtree roots. Do not add another public concept or operation-label parameter.

Preparation must:

1. compute mutation-identity plus extra columns;
2. for a new model, run the same unchanged-scope and concrete-scope assertions, then return without reading;
3. read the persisted columns through the shared reload boundary, which requires an existing model's primary key to be selected;
4. read the persisted columns once for an existing model;
5. pass that snapshot into scope-change validation before overwriting attributes;
6. apply the snapshot, sync its originals, and clear nested-set relations;
7. validate concrete scope.

Use a local snapshot, not shared state:

```php
$persisted = $this->getPersistedNodeAttributes($columns);

$this->ensureNestedSetScopeIsUnchanged($persisted);
$this->applyPersistedNodeAttributes($persisted);
$this->ensureConcreteNestedSetScope('mutation');
```

Allow `ensureNestedSetScopeIsUnchanged(?array $persisted = null)` to retain its own fallback read for ordinary/raw saves whose scope originals are absent. When preparation supplies a snapshot it must not read again. Put the selected-key guard in `getPersistedNodeAttributes()` so mutation preparation, public refresh, and the ordinary scoped-save fallback share the same precise failure.

Delete `refreshNodeAttributes()`, `refreshNodeForMove()`, and `ensureMutationIdentityIsLoaded()`. Keep two explicit read/apply callers:

- `refreshNode()` reads and applies structural columns;
- `prepareForNestedSetMutation()` reads and applies mutation identity.

`applyPersistedNodeAttributes()` owns merging, original synchronization, and nested-set relation invalidation. This avoids two overlapping snapshot-application paths.

### Setup semantics

Remove database preparation from `appendOrPrependTo`, `beforeOrAfterNode`, `rawNode`, `saveAsRoot`, and related fluent helpers. Setup checks only loaded facts:

- different store/table identity: throw immediately;
- any selected non-positive bound: throw immediately;
- complete incompatible scope: throw immediately;
- missing bounds/scope: queue the action and defer strict validation to `save()`.

Replace the setup-time strict assertion chain with a loaded-fact guard. Always compare structural store identity; compare scope only when both models have complete scope; reject same-node/known-descendant relationships when the loaded facts prove them. Do not call strict `assertNodeInTree()` or `assertSameTree()` against missing attributes. This preserves partial-model mutation support without trusting partial state. A cross-scope partially projected target may now fail at `save()` rather than during fluent setup; record that timing explicitly in tests.

## 3. Simplify action execution

### Append by parent ID

`actionAppendToParentId()` must resolve the parent once from a write-connection scoped query and pass the prepared parent to a shared append core. The core has two real callers and owns:

- node-in-tree, descendant, and same-tree guards;
- parent assignment and dirty bounds;
- append/prepend cut and target depth;
- insertion and the required parent post-write refresh.

Do not retain two parent queries or duplicate assertions between `actionAppendToParentId()` and `actionAppendOrPrepend()`.

Retain soft-delete semantics: parent-ID assignment resolves only an active parent, while callers may still intentionally pass an explicitly loaded soft-deleted parent model through `appendToNode()` / `prependToNode()`.

### Root actions

Keep `makeRoot()->save()` as a forced reposition operation. Add a dedicated internal pending action for `saveAsRoot()`:

```php
protected function actionSaveAsRoot(): bool
{
    if ($this->exists && $this->isRoot()) {
        return false;
    }

    return $this->actionRoot();
}
```

`saveAsRoot()` schedules this action directly and saves. It must not pre-apply `setParent(null)->dirtyBounds()` because lifecycle preparation restores authoritative identity before execution. The `exists` guard is required: a new model with explicit `parent_id => null` is root-shaped but still needs bounds assigned.

### Up/down selection

`up()` and `down()` are mutations, not loaded-state read helpers. Prepare the source before building sibling predicates, then run the sibling selection on the writer. The normal save boundary prepares the source again before execution; these reads protect two different decisions and must not be collapsed through retained state.

Keep `getNextSibling()`, `getPrevSibling()`, `nextNodes()`, `prevNodes()`, `isLeaf()`, and other read helpers on their existing loaded-state/read-query semantics.

### Post-write refreshes

Retain only refreshes whose result is not safely derivable or whose public method promises updated passed models:

- parent after append/prepend;
- target after `insertBeforeNode()` / `insertAfterNode()`;
- moved source when target depth was not supplied;
- each model returned by recursive `create()` after its create events and any child writes complete.

Keep the final `create()` refresh unconditional. Besides accounting for descendants, it preserves a current returned model when a `created` observer performs another structural write. Inferring freshness from a child append's internal parent refresh would couple `create()` to another method's implementation, while conditionally detecting observer side effects would add machinery for one query on a non-hot convenience path.

`insertNode()` must use separate builders for the gap mutation and writer-routed depth lookup. `makeGap()` mutates its builder's base query, so retaining that builder across the two operations creates hidden coupling even though `depthForPosition()` currently creates its own lookup query. Do not add `useWritePdo()` to update/delete-only builders; those operations already use the writer and the flag controls selects only.

## 4. Route mutation-defining reads to the writer

Add `useWritePdo()` at the mutation call site, not to public read helpers globally. Audit and update:

- `getLowerBound()`;
- deferred parent-ID lookup;
- `up()` / `down()` sibling selection;
- low-level `moveNode()` node-data fallback;
- `moveNode()` and `insertNode()` depth lookup;
- evented descendant selection;
- subtree boundary validation queries;
- `fixTree()` and `rebuildTree()` wholesale snapshots;
- any equivalent mutation-defining query found during implementation review.

`getNodeData()`, `getPlainNodeData()`, positional read helpers, diagnostics, and relationship reads retain normal read routing when called as reads. `ensureNestedSetScopeIsUnchanged()` already reaches the writer through `getPersistedNodeAttributes()`.

`depthForPosition()` remains a public read helper. Add one protected `QueryBuilder` helper that creates a fresh scoped lookup query from the model and calls `useWritePdo()` only when `$this->query->useWritePdo` is already true. Use it in `depthForPosition()` and the low-level `moveNode()` node-data fallback. Mutation callers invoke these methods from a writer-routed builder; ordinary callers retain the normal read route. Repair/rebuild queries originate from the supplied root rather than an existing builder, so they call `useWritePdo()` directly.

Put one focused test class in `tests/NestedSet/` so the contract runs in an ordinary suite pass. Use compact provider cases and a genuine read/write SQLite split backed by one file. Both endpoints must point to the same file so query-log `readWriteType` assertions are meaningful. Define the connection before application services consume it, reuse one schema fixture, and clean up through the standard parallel-safe temporary-directory pattern. Do not put this in the default-driver integration matrix, create one integration test per call site, or use in-memory SQLite, where read and write PDOs would see different databases.

## 5. Prepare subtree repair and rebuild roots once

Replace `assertRepairRootIsComplete()` with a shared `prepareRepairRoot(Model $root, string $operation): void`, called independently by `fixTree()` and `rebuildTree()` when a root is present. `fixSubtree()` funnels through `fixTree()`; `rebuildSubtree()` funnels through `rebuildTree()`.

The helper returns `void` and prepares the supplied instance in place. It must:

1. require a persisted root with a non-null key;
2. prepare the same instance through `prepareForNestedSetMutation()`;
3. reuse the model's complete-scope predicate and, when the builder model has a complete explicit scope, require its normalized scope to match the root;
4. reject non-positive, zero-width, or inverted stored intervals directly;
5. validate parentage containment using the fresh bounds and a writer query.

A bare static `Category::fixSubtree($root)` remains root-owned. A builder created through `scoped([...])` may not silently target another tree:

```php
MenuItem::scoped(['menu_id' => 1])->fixSubtree($rootFromMenu2);
```

That call must throw before any write. Apply the same rule to rebuild.

Use writer routing for the parentage-containment query and subsequent wholesale snapshot. Once preparation has found the exact row and direct interval validation has established valid bounds, a separate query proving that the root selects itself is redundant.

Behavioral outcomes to pin:

- a persisted partial root hydrates its missing mutation identity from the writer and proceeds;
- a persisted root without its primary key selected fails with the shared mutation-projection exception;
- a deleted root row throws `ModelNotFoundException`;
- an inverted or zero-width stored root interval throws a precise `LogicException`;
- authoritative bounds with parent-linked nodes outside the window throw the crossing-boundary `LogicException`;
- stale in-memory bounds with a consistent current database subtree repair/rebuild the correct current window.

Bulk nodes created from the snapshot continue through raw assignment/save without per-node preparation. Read query count remains bounded: one root preparation plus wholesale validation/snapshot queries, independent of subtree size. Existing writes remain proportional to the nodes whose stored structure changes.

Evented cascade deletion retains its existing `deletingAsDescendant` lifecycle guard. Descendants still run their delete events, but they do not repeat authoritative mutation preparation already owned by the cascade. Selection reads scale with the number of chunks, including the final empty chunk, rather than with the number of descendants.

## 6. Make relation projection failures explicit

Centralize parent eligibility/column validation in `BaseRelation`, but keep each relation's parent and related projection requirements distinct.

Required bounds and primary keys must be selected and non-null. Parent IDs and scope columns must be selected, but their values may legitimately be null. Check `exists` before column validation so an unsaved model remains the intentional empty-relation case.

Give each relation separate parent-column and related-column declarations. The shared parent preparation filters unsaved models, validates persisted models, then deduplicates; ancestor/descendant interval reduction runs afterward. The shared `match()` boundary validates returned models once before indexing or per-parent matching. Remove the sibling-specific duplicate projection guards once the shared boundary owns them.

### Parent rules

- unsaved parent: relation is empty; lazy adds `0 = 1`, while an all-unsaved eager set skips its query;
- persisted incomplete parent: throw `LogicException` naming the exact missing column;
- ancestors: left, right, and every scope column;
- descendants: left, right, and every scope column;
- siblings-and-self: parent ID and every scope column;
- strict siblings: parent ID, key, and every scope column.

Place lazy validation after `shouldAddConstraints()`. Relation existence queries run under `noConstraints` and build correlated column expressions; they must remain unguarded and must not read loaded parent coordinates.

Ancestors require both parent bounds even though the lazy predicate consumes only the right bound because eager sorting/matching also consumes the left bound. Record this with one concise WHY comment.

### Related-result rules

Before matching/indexing eager results, validate each returned model once and require:

- ancestor result: left, right, and scope;
- descendant result: left and scope;
- siblings-and-self result: parent ID and scope;
- strict sibling result: parent ID, key, and scope.

Perform this once in the shared `match()` boundary before optional result indexing so multi-parent matching does not repeat projection checks. Do not share one parent/related column set: doing so would unnecessarily require right bounds on descendant results. Reuse the existing projection-error message style and never issue a hidden query to repair a projection.

## 7. Tests

### Remove obsolete freshness coverage

Delete revision/context tests and rewrite tests that pin zero first-operation preflights, revision publication, or freshness-dependent duplicate suppression. Migrate only structural identity assertions to `NestedSetTest`.

The directly superseded `NodeTest` cases include `testFirstMoveDoesNotRefreshAFreshSourceNode`, `testSecondMoveRefreshesEachStaleParticipantOnlyOnce`, `testLowLevelStructuralWritesPublishFreshness`, `testRawNodePublishesFreshnessOnlyWhenStructureChanges`, and `testCreatesTreeWithoutDuplicateIdentityReloads`. Replace their revision assertions with the lifecycle/query contracts below rather than renaming the old expectations.

### Lifecycle and action coverage

Add or update tests for:

- one authoritative preflight per existing source/target at each non-raw boundary;
- no source preflight for a new node;
- no fluent-setup query for incomplete-but-not-known-invalid models;
- immediate no-query failure for known-invalid loaded store, bounds, or complete scope;
- strict save-time failure after incomplete setup;
- stale/deleted existing models fail before any structural write regardless of prior package activity;
- rollback retry reading current committed coordinates rather than attempt-one state;
- nested savepoint rollback in the existing database matrix;
- completed parent/child and sibling coroutine interleavings, including a model passed to a child;
- `saveAsRoot()` idempotence and new explicit-null-parent bounds;
- forced `makeRoot()->save()` repositioning;
- correct `up()`/`down()` sibling selection after an earlier structural shift;
- prepared append-by-ID issuing one parent read;
- required post-write refreshes distinguished from preflight reads;
- flat recursive creation retaining its exact action shape: the root performs its lower-bound read, insert, and final refresh; each child performs one parent preflight, one gap write, one insert, one parent post-write refresh, and its own final refresh; the structural-identity read count is `(3 × child count) + 1`, so four children produce 13 and no other structural-identity reads;
- ordinary reads adding no writer preflight.

The retry test is driver-neutral: deterministically throw a concurrency exception recognized by `ConcurrencyErrorDetector` inside `DB::transaction($callback, 2)`. Keep it in `tests/NestedSet`; matrix coverage is reserved for actual savepoint semantics.

Do not add a permanent benchmark harness. Deterministic participant-read counts, bulk read scaling, and real read/write routing are stronger regression contracts and avoid timing noise from database and machine load.

### Raw coverage

Add the missing scoped raw cases:

- new incomplete scoped model: `rawNode()` queues successfully, `save()` throws the concrete-scope exception naming the attribute;
- persisted partial scoped model: raw save preserves caller coordinates, performs one fallback scope-validation read, and performs no structural reload;
- persisted complete scoped model: raw save performs no read.

### Repair/rebuild coverage

Cover both repair and rebuild where applicable:

- stale in-memory root selects and repairs the current database window;
- persisted partial roots hydrate and operate on their authoritative scope/bounds;
- missing row, inconsistent parentage, and scoped-builder/root mismatch outcomes;
- root preparation mutates the passed instance rather than replacing it;
- validation and snapshot reads remain O(1) with tree size and add no per-node reads; existing writes remain proportional to changed nodes;
- evented cascade deletion performs no per-descendant authoritative preflight, with selection reads scaling by chunks rather than descendants;
- mutation-defining reads use the write endpoint in the focused split-routing test.

The split-routing test must exercise every mutation-owned writer read listed in section 4, including root lower-bound selection, deferred parent-ID lookup, `up()` / `down()`, insert depth lookup, subtree preparation and containment, repair/rebuild snapshots, and evented cascade chunks. Ordinary lookups in the same real read/write split remain on the read endpoint.

### Relation coverage

For ancestors, descendants, siblings-and-self, and strict siblings:

- an unsaved lazy parent remains empty through a false query constraint; an all-unsaved eager set remains empty without querying;
- each missing persisted parent column throws for lazy and eager loading;
- each missing related-result column throws during eager matching/indexing;
- destructive relation queries fail at relation construction/constraint application for an incomplete persisted parent;
- a persisted partial duplicate no longer silently suppresses a complete instance: it throws;
- an unsaved duplicate alongside a complete persisted instance does not suppress the complete eager constraint;
- existence queries remain functional without loaded parent coordinates.

Exception tests must prebuild/capture the intended exception path so PHPUnit assertion failures cannot be swallowed.

Invert the existing partial-projection cases rather than preserving silent-empty behavior: `testPositionalRelationsTreatMissingBoundsAsEmpty`, `testIncompleteBoundsDoNotReachEagerPositionalConstraints`, `testSiblingRelationsTreatMissingParentageAsEmpty`, `testStrictSiblingRelationsTreatMissingModelKeyAsEmpty`, `testPartialEagerParentDoesNotSuppressACompleteInstanceOfTheSameRow`, `testAllIncompleteEagerParentsSkipTheRelationQuery`, `testIncompleteLazyParentsKeepTheirEmptyQueryConstraint`, and scoped `testRelationsTreatMissingScopeAndParentageAsEmpty`. Rewrite `testSubtreeOperationRejectsRootWithoutScopeBeforeWriting` for authoritative hydration, and split `testSubtreeRepairRejectsMissingOrCoordinateStaleRoot` into the distinct outcomes in section 5.

## 8. Documentation and cleanup

Update only `src/docs/nested-set.md`:

- creating roots: distinguish idempotent `saveAsRoot()` from forced `makeRoot()->save()` repositioning;
- relations: unsaved parents are empty; incomplete persisted parent or related projections throw and no hidden repair query is issued;
- state helpers: keep loaded-state semantics while noting that `up()`/`down()` reread before selecting;
- partial mutations: require the primary key because the package reloads the exact persisted row;
- repair/rebuild: a builder created through `scoped([...])` must match the supplied root scope;
- performance: structural participants are reread from the writer immediately before mutation, while applications still serialize concurrent writers.

Do not add a porting-guide entry or README difference. These are package correctness, internal architecture, and narrow timing/error improvements rather than a Laravel application porting decision.

After implementation, grep all `src/` and `tests/` for removed freshness symbols, `Hypervel\\Context` imports, and the direct `hypervel/context` dependency. No stale comments, dead helpers, superseded tests, or redundant documentation may remain.

## 9. Verification

During implementation, run each changed nested-set test file immediately. Then run:

1. the focused nested-set suite;
2. the nested-set database matrix tests available locally;
3. the repository package-metadata tests;
4. `composer validate --strict src/nested-set/composer.json` plus a namespace-to-requirement audit over `src/nested-set/src` that checks both imported external namespaces and declared direct Hypervel component requirements;
5. a clean temporary consumer install that resolves `hypervel/nested-set:0.4.x-dev` from the split manifests under `src/*`, followed by requiring `vendor/autoload.php` and autoloading `Hypervel\\NestedSet\\NestedSet`;
6. package/root formatting and targeted static analysis as appropriate;
7. `composer fix` once at the final checkpoint.

For step 5, generate one wildcard path repository over `src/*` with an explicit `0.4.x-dev` version map for every discovered Hypervel package name. This makes Composer read `src/nested-set/composer.json` and its split dependencies instead of satisfying the request through the root package's `replace` map. Assert that Composer installed `hypervel/nested-set`. Before trusting the recipe, point it at a temporary copy whose nested-set manifest contains an impossible dependency constraint and confirm installation fails; do not mutate the source manifest for this fault injection.

After checks pass, perform a fresh source review through every mutation caller/callee, relation lazy/eager/existence path, repair/rebuild path, and query route. Confirm:

- no participant is trusted through freshness state;
- no duplicate preparation remains inside one lifecycle boundary;
- all mutation-defining reads use the writer;
- ordinary reads retain their current cost;
- repair/rebuild and evented cascade reads remain bounded;
- raw and partial-model behavior matches this plan;
- no Laravel-style or Aimeos public API is broken except the explicit correctness restrictions and timing changes documented above.
