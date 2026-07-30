# Complete Nested Set Invariants, Performance, and Modern APIs

## Status

Investigation, benchmarks, owner gates, implementation, validation,
self-review, peer code review, and final audit-record review are complete.

## Scope

Modernize `hypervel/nested-set` using
`aimeos/laravel-nestedset@90ea384febeaaa97967f43b1af9661d4edf2354a`
as the sole ongoing reference. Aimeos is a source of current behavior and tests,
not a parity contract. Hypervel keeps its coroutine-local lifecycle, immutable
dates, strict types, supported database policy, and scalability requirements.

The work must:

- support bigint, integer, native UUID where available, and ULID model keys;
- make unscoped and scoped trees correct on MySQL, MariaDB, PostgreSQL,
  and SQLite;
- maintain bounds, parentage, and stored depth as one database invariant;
- make relations, eager matching, repair, and diagnostics scope-correct;
- preserve caller ordering and useful existing APIs;
- remove stale state, dead overrides, duplicate predicates, and misleading
  docs; and
- add no package lock, retry policy, compatibility branch, schema
  introspection cache, worker-retained tree index, or hidden network call.

## Post-compaction recovery and anti-overengineering rules

After compaction, read `AGENTS.md` and this plan in full before resuming. Do not
reread the framework-wide audit plan; this section carries its applicable
rules.

- Require a supported path and meaningful harm before treating a concern as a
  defect. Merely conceivable states do not justify machinery.
- Trace the owner, callers, callees, commit boundary, cleanup, siblings, tests,
  and upstream behavior before changing code. Upstream difference is not proof
  of a bug, and upstream parity is not proof of correctness.
- Fix verified failures completely at the lowest inconsistent owner. Do not
  compensate in callers, retain partial fixes to reduce churn, or leave
  same-family defects behind.
- Prefer existing Eloquent, Schema, PHP, and database facilities. Do not
  duplicate framework metadata, casting, lifecycle, or cleanup locally.
- Add no abstraction, registry, retry, backoff, timeout, lock, config switch,
  context slot, cache, or extension point unless a verified requirement below
  needs it or it deletes greater complexity.
- Do not enforce invariants across deliberate escape hatches such as raw SQL,
  raw builders, or disabled model events. Document their responsibility when
  the public contract requires it.
- For coroutine or worker state, identify the shared state, realistic
  interleaving, and harm before adding isolation. Mutable operation state is
  never process-global.
- Backward compatibility does not preserve flawed Hypervel-only internals, but
  retain useful Laravel/Eloquent-shaped APIs and conventions unless an
  approved improvement requires a precise correction.
- Any newly discovered public divergence or source-proven hot-path regression
  returns to owner review before implementation.
- Account for queries, rows locked, index writes, allocations, sorting,
  hashing, serialization, container lookups, retained worker memory, and cache
  invalidation. Do not implement improvements whose practical effect is noise.
- Keep tests deterministic and behavior-focused. Do not add production seams,
  timing assertions, or exhaustive tests of private mechanics.
- Do not make source awkward or slower for PHPStan. Correct real types first,
  then use local narrowing or a precise ignore for analysis limitations.
- Remove every superseded property, helper, import, ignore, comment, test
  fixture, and documentation statement in the same change.
- If implementation exposes an unexpected defect or contradiction, stop that
  edit, investigate the complete related slice, obtain focused second-opinion
  consensus, replace the affected plan text, and then resume.

## Architecture and research

### Ownership

| Surface | Owner and lifetime |
|---|---|
| Pending model action | Model instance |
| Structural freshness | Coroutine-local, keyed by logical connection and table |
| Bounds, parent, depth | Database row and concrete nested-set scope |
| Visibility | Ordinary Eloquent global scopes; never a tree partition |
| Eager matching index | One relation operation; released after matching |
| Trait membership cache | Worker-static, per model class |
| Blueprint macros | Provider boot state; existing `Blueprint::flushState()` cleanup |
| Concurrent mutation serialization | Application transaction and application lock |

The same physical tree can be addressed through multiple model classes.
Freshness and structural identity therefore belong to resolved connection plus
table, not model class. Nested-set scope attributes partition trees within that
table. Visibility global scopes do not.

### Measured design choices

Benchmarks used PHP 8.4. Eager matching used synthetic 20,000- and
100,000-node PHP fixtures; database measurements used a 50,000-node,
ten-scope UUID-keyed fixture on all four engines. They are research evidence,
not CI timing thresholds.

| Read shape | Current | Aimeos | Final adaptive design |
|---|---:|---:|---:|
| 200 disjoint descendant parents / 77,860 results | 1,434 ms | 113 ms | 21 ms |
| 100 scoped descendant parents / 99,900 results | 558 ms | 3,890 ms | 27 ms |
| Same descendants with custom result order | 1,121 ms | 3,824 ms | 34 ms |
| One root / 99,999 descendants | 9.6 ms | 338 ms | 9.2 ms |
| 100 scoped ancestor chains / 99,900 results | 414 ms | 3,927 ms | 23 ms |
| One-scope ancestors / 500 parents / 189 results | 5.6 ms | 4.9 ms | 5.3 ms |

Aimeos retained about 37–45 MiB on the large cases; the final design retained
about 6 MiB and avoids worker-retained indexes.

The final schema indexes are:

```text
(scope..., _rgt)
(scope..., _lft)
(scope..., parent_id, _lft)
```

Unscoped trees omit the scope prefix. Compared with the current index, the
layout reduced representative scoped ancestor/children reads from
15.6/27.4 ms to 0.35/0.28 ms on MySQL, 9.2/9.1 ms to 0.21/0.24 ms on MariaDB,
1.1/2.7 ms to 0.21/0.14 ms on PostgreSQL, and 5.6/4.3 ms to 0.56/0.02 ms on
SQLite. Removing the dedicated `_rgt` index caused material MySQL/MariaDB
regressions.

The extra indexes increased representative index storage and made gap updates
about 1.6x slower on MySQL and 1.3x slower on PostgreSQL; MariaDB and SQLite
were neutral or faster. They also prevented unrelated scopes with
reused coordinates from blocking each other in the ten-scope MySQL/MariaDB
probe. They are not a serialization guarantee: MariaDB chose a table scan and
still blocked in a separate two-large-scope probe. This bounded write/storage
cost is approved for the large read and scope-isolation gain.

Stored depth changed a 1,000-node projection from 5,410 ms to 6.2 ms on MySQL,
779 ms to 1.5 ms on MariaDB, 55.6 ms to 0.74 ms on PostgreSQL, and 54.5 ms to
0.30 ms on SQLite. A default depth index is rejected: although it improved
depth filters by about 2–14x, it slowed structural writes by roughly 20–50%.
Applications that frequently filter by depth may add
`(scope..., depth, _lft)`.

The endpoint-window integrity check ran in about 5–29 ms. Aimeos's pairwise
crossing check took about 1.3–9 seconds and exceeded 90 seconds under one
layout. Do not port its pairwise join or whole-tree PHP/Fenwick scanner.

## Public result

Existing useful model and builder operations remain. The intentional public
corrections/additions are:

- `Collection` root/key APIs accept `Model|int|string|null|false` where
  applicable, with `false` alone meaning infer;
- `toFlatTree()` no longer incorrectly accepts only `bool`;
- `getParentId()`, its mutator, and parent-facing metadata support
  `int|string|null`;
- `getDepthName()`, `getDepth()`, and `setDepth()` expose stored depth;
- recursive `create()` truthfully returns `static`, never null;
- a real Eloquent `siblings()` relation supports lazy/eager/existence/count;
- `siblingsAndSelf()` uses the same relation with self inclusion;
- `QueryBuilder::moveNode()` may omit target depth, resolved by the new
  `depthForPosition()` method;
- `fixTree()` accepts explicit observer-required columns;
- rebuild root parameters truthfully require models;
- `countErrors()` returns named invariant categories and scoped models require
  an explicit `scoped([...])` selection;
- static and Blueprint schema helpers support bigint, integer, UUID, ULID, and
  scoped indexes; and
- bulk descendant deletion remains the scalable default, with protected
  Laravel-shaped hooks for evented deletion.

These gates, mandatory stored depth, the integrity result change, and the
bounded `isNode()` cache are owner-approved.

## 1. Provenance, package metadata, and provider

Change the README and Boost guide to name Aimeos as the sole ongoing source.
Lazychaser remains historical Git ancestry and does not need a second active
reference.

Add `NestedSetServiceProvider` and register the macros in `register()`:

```php
Blueprint::macro('nestedSet', function (array $scopes = []): void {
    NestedSet::columns($this, $scopes);
});

Blueprint::macro('integerNestedSet', function (array $scopes = []): void {
    NestedSet::integerColumns($this, $scopes);
});

Blueprint::macro('uuidNestedSet', function (array $scopes = []): void {
    NestedSet::uuidColumns($this, $scopes);
});

Blueprint::macro('ulidNestedSet', function (array $scopes = []): void {
    NestedSet::ulidColumns($this, $scopes);
});

Blueprint::macro('dropNestedSet', function (array $scopes = []): void {
    NestedSet::dropColumns($this, $scopes);
});
```

Macroable rebinds these closures to the active Blueprint, so `$this` is the
table blueprint. Add provider discovery to the split and root Composer
metadata. Do not add package-specific macro reset machinery: database test
cleanup already calls `Blueprint::flushState()`, and each rebuilt application
re-registers the provider.

After restore refactoring, re-scan package imports. Remove `nesbot/carbon` only
if no source file directly uses it. Keep the real Collections, Context,
Database, and Support dependencies.

## 2. Canonical schema and stored depth

Add `NestedSet::DEPTH = 'depth'`. Every schema helper creates `_lft`, `_rgt`,
`depth`, a nullable typed `parent_id`, and the three indexes above:

```php
public static function columns(Blueprint $table, array $scopes = []): void;
public static function integerColumns(Blueprint $table, array $scopes = []): void;
public static function uuidColumns(Blueprint $table, array $scopes = []): void;
public static function ulidColumns(Blueprint $table, array $scopes = []): void;
public static function dropColumns(Blueprint $table, array $scopes = []): void;
```

Every helper uses unsigned-integer `_lft` and `_rgt` columns with a default of
`0`. PostgreSQL's signed integer range gives the portable limit: at most
1,073,741,823 nodes in one scope because the maximum endpoint is twice the row
count. Widening both indexed, frequently updated columns for a larger
theoretical tree would impose real storage and write cost.

`columns()` uses unsigned-big-integer parent IDs to match `$table->id()`.
`integerColumns()` matches `$table->increments()`. UUID uses each database
grammar's UUID type: native on PostgreSQL and supported MariaDB versions,
compatible storage elsewhere. ULID uses the framework's 26-character type.

Depth is:

```php
$table->unsignedSmallInteger(NestedSet::DEPTH)->default(0);
```

The portable non-negative ceiling is PostgreSQL's 32,767; MySQL/MariaDB allow
65,535 and SQLite uses 64-bit integer affinity. A deeper hierarchy is not a
realistic reason to widen every row and optional index.

Scope names are ordered existing application columns and only prefix indexes.
The helper does not create scope columns. Create them first with their desired
native type:

```php
$table->foreignId('menu_id');
$table->nestedSet(['menu_id']);
```

`dropColumns()` takes the same ordered scope list and deterministically drops
the exact indexes and columns. Public `getDefaultColumns()` remains the
canonical structural column list used by that drop path and includes `_lft`,
`_rgt`, `parent_id`, and `depth`. Document the required symmetry. Add no index
introspection, dynamic schema-method string, arbitrary ID-column parameter,
self-referential foreign key, package migration, or default depth index.

Make the default test fixture use `$table->id()`. Add explicit narrow integer,
UUID, and ULID fixtures.

## 3. Depth mutation and reads

Roots use depth `0`; children use parent depth plus one; sibling insertion uses
the target depth. Every insert, move, raw-node update, repair, rebuild,
recursive create, and replication path must either maintain or deliberately
exclude depth. `replicate()` excludes depth together with parent and bounds.

Update `HasNode`'s trait metadata to:

```php
/**
 * @template TModel of Model
 *
 * @property int|string|null $parent_id
 * @property ?int $depth
 * @property ?static $parent
 */
```

Expose the canonical model surface:

```php
public function getDepthName(): string;
public function getDepth(): ?int;
public function setDepth(?int $value): static;
public static function create(array $attributes = [], ?self $parent = null): static;
public function rawNode(
    int $lft,
    int $rgt,
    int|string|null $parentId,
    ?int $depth
): static;
```

Update depth in the same structural SQL statement as bounds. MySQL/MariaDB
evaluate assignments left-to-right, so movement patch arrays place depth
before `_lft` and `_rgt` whenever the depth expression reads old bounds. Add no
query solely to persist a known depth.

Replace correlated depth reads and filters with the stored column.
`withDepth($as)` selects/aliases the qualified stored column directly and
works for scoped and trashed rows. `getNodeData()` returns named left, right,
and depth values; `getPlainNodeData()` still extracts only `[left, right]` for
range consumers.

Internal model actions pass an explicit target depth only when it is loaded.
Partial model targets and direct low-level callers preserve an omitted depth;
the public builder derives the persisted value:

```php
public function moveNode(
    int|string $key,
    int $position,
    ?int $targetDepth = null,
    array $nodeData = []
): int;

public function depthForPosition(int $position): int
{
    // 0 when no containing node exists; otherwise containing depth + 1.
}

$depthDelta = ($targetDepth ?? $this->depthForPosition($position)) - $currentDepth;
```

The lookup uses the structural, nested-set-scoped builder, includes structural
soft-deleted rows, orders containing nodes by `_lft` descending, and is served
by `(scope..., _lft)`. There is no `-1` sentinel, caller-side `+1`, or shadowed
`$depth` variable.

## 4. Structural state and builder ownership

Delete deleted-at state from `NodeContext`. During `restored`, read the exact
stored previous value from:

```php
$model->getPrevious()[$model->getDeletedAtColumn()]
```

Eloquent publishes that raw, database-precision value before the event. Pass it
to descendant restore without writing it back onto the restored model. Do not
round to the start of a second or add a stack/registry. This fixes nested
same-class restores and prevents a later ordinary save from re-deleting the
restored row.

Delete `HasNode::$hasSoftDelete` and use `Model::isSoftDeletable()`. Eloquent
already owns the correct per-class worker cache and cleanup.

Key freshness by a length-delimited logical identity derived from the resolved
connection's `getName()`, falling back through the resolver's default and then
to a stable default marker when a custom resolver provides neither, plus the
model table. Explicit and default aliases for one logical connection must
converge. Do not use model class, connection object identity, or scope.

Pending-action APIs stage parentage and action intent only; the action owns
bounds and depth. In each `insertAt()` branch, publish freshness immediately
before that branch's first interval mutation. Also publish immediately before
force deletion closes its physical gap and before raw repair/rebuild
mutations. Do not defer publication until `callPendingActions()` returns:
append/prepend refreshes the parent after `insertAt()`, and that in-action
refresh must observe the new `_rgt`.
Table-wide invalidation may cause an extra refresh across scopes but cannot
miss a structural write.

The internal structural builder is:

```php
return $this->applyNestedSetScope(
    $this->newQuery()->withoutGlobalScopes(),
    $table,
);
```

Starting with `newQuery()` is load-bearing: global scopes first install their
builder extensions, then `withoutGlobalScopes()` removes their filtering.
This retains SoftDeletes macros while allowing structural operations to see
every row. `newScopedQuery()` stays user-facing and retains ordinary global
scopes. Parent resolution uses the structural builder plus `withoutTrashed()`.

Apply this boundary to movement, gap changes, deletion/restoration, depth,
integrity, repair, and rebuild. A blank model must never contribute null scope
values to aliased structural queries.

Qualify model-owned structural columns in user-composable read predicates and
ordering so they remain valid after joins. This covers `whereIsRoot()`,
`whereIsLeaf()`, `hasChildren()`, before/after predicates, `defaultOrder()`,
and the next/previous node and sibling queries. `wrappedColumns()` returns
qualified bounds, and `whereAncestorOf()` consumes them without adding a
second table prefix. Keep internal `UPDATE` assignment targets unqualified:
SQLite rejects qualified columns on the left side of `SET`.
`defaultOrder()` uses the base query's `reorder()` API so prior ordinary and
union order clauses and their bindings are removed together. It qualifies the
structural column for ordinary queries and uses the projected unqualified
column for compound queries, whose result-level `ORDER BY` cannot reference a
source table.

Respect Eloquent's cached `#[UseEloquentBuilder]` metadata. A configured
builder that subclasses Nested Set `QueryBuilder` is instantiated; an
incompatible configured builder throws a descriptive `LogicException`; the
default uses Nested Set `QueryBuilder`. Delete dead `newModelBuilder()` and do
not duplicate Model's builder cache.

Truthful builder types:

```php
public function newNestedSetQuery(?string $table = null): QueryBuilder;
public function newScopedQuery(?string $table = null): QueryBuilder;
public static function scoped(array $attributes): QueryBuilder;

/**
 * @template TQuery of BaseQueryBuilder|EloquentBuilder
 * @param TQuery $query
 * @return TQuery
 */
public function applyNestedSetScope(
    BaseQueryBuilder|EloquentBuilder $query,
    ?string $table = null
): BaseQueryBuilder|EloquentBuilder;
```

## 5. Scope values, keys, and predicates

Add one public model-owned `getNestedSetScope()` returning the configured
attribute names and normalized values in declared order. Both SQL predicates
and eager bucket identity consume this map.

Normalize each value as:

```php
$value = enum_value($value);

return match (true) {
    $value === null, is_int($value), is_string($value) => $value,
    is_bool($value) => (int) $value,
    $value instanceof DateTimeInterface => $value->format(
        $this->dateFormat ?: 'Y-m-d H:i:s',
    ),
    $value instanceof Stringable => (string) $value,
    default => throw new LogicException(/* model and attribute */),
};
```

Place `DateTimeInterface` before `Stringable`: a future date implementation's
`__toString()` must not silently change database identity. Honor the model's
resolved date-format override while retaining the framework grammar default
without resolving a connection during per-result matching. Reject floats,
arrays, resources, and other objects. Float text is precision/INI-dependent
and unsuitable as a tree partition key; fail descriptively rather than permit
silent bucket collisions or add a float encoder.

Encode a scope tuple once per model/result using values only, because declared
attribute order is fixed:

```php
$key = '';

foreach ($model->getNestedSetScope() as $value) {
    $key .= $value === null
        ? '-1:'
        : strlen((string) $value).':'.$value;
}
```

This distinguishes null, empty string, false, and delimiter-like values while
converging database integer `1`, request string `'1'`, true, and matching
backed enums. No JSON, PHP serialization, escaping scheme, recursive encoder,
connection lookup, or attribute-name repetition is needed.

Scalar model/parent dictionaries use a separate roots list and plain non-null
PHP array keys. Do not add type prefixes, null markers, or `(int)` casts:
those add measured CPU/memory and a wrong model key type would collapse UUID
or ULID trees. For strict parent/predicate comparisons, after non-null guards,
compare `(string) $left === (string) $right`.

Use `Model::is()` as the row-identity fast path. When its raw default/explicit
connection names differ, compare non-null keys plus the same resolved logical
connection/table identity used by freshness. Exact normalized scope maps then
establish tree identity. Self-inclusive ancestor/descendant predicates require
persisted row and tree identity. Sibling predicates require persisted,
distinct rows in the same concrete scope with equal normalized parent IDs;
two roots in one scope are siblings because null equals null here. Child
predicates require a persisted parent, the same concrete scope, and the
child's non-null normalized parent ID to equal the parent's persisted key.
Only `null` means no parent; `0` and `''` remain valid.

Rename the protected target guard to `assertNodeInTree()`:

```php
if (($node->getLft() ?? 0) < 1 || ($node->getRgt() ?? 0) < 1) {
    throw new LogicException('Node must be part of a tree.');
}
```

Do not grow this into a full interval-integrity check. Positive hand-set bounds
are a deliberate low-level bypass.

Direct model targets may be hand-positioned or intentionally loaded with
`withTrashed()`, but append/prepend/before/after mutations require the same
resolved connection, table, and concrete scope through `assertSameTree()`.
This deliberately rejects write coordinates from replicas or other
connections, which may lag or address another tree. Scalar parent assignment
continues to resolve an active row from the source tree.

Defer parent-ID lookup until all filled scope attributes are present at the
saving action boundary. Preserve the `void` mutator and accept
`int|string|null`. Parent lookup is structural and excludes trashed parents.

```php
public function setParentIdAttribute(int|string|null $value): void;
public function getParentId(): int|string|null;
```

Scalar coordinate lookups in `whereAncestorOf()` and before/after predicates
use `newNestedSetQuery($alias)`. This removes ordinary global-scope filters
from the aliased subquery while applying a concrete nested-set scope to that
alias. A scalar key outside the selected tree therefore resolves to no
coordinate. A scoped model without a concrete scope rejects scalar
ancestor/descendant/before/after lookups and names `scoped()` instead of
silently returning inconsistent empty or missing-model results. Node-based
before/after predicates group the node's exact scope and coordinate comparison
under the requested boolean so `boolean: 'or'` semantics remain correct. Pass
node coordinate bindings with their raw predicates and remove comments that
claim scalar lookups are unscoped.

## 6. Relations and adaptive eager matching

Add a real `SiblingsRelation` used by both `siblings()` and
`siblingsAndSelf()`, supporting lazy loading, eager loading, `has`/`whereHas`,
and counts for roots and non-roots. It applies exact scope in constraints and
matching, excludes self where required, and respects a custom parent column.
Its existence query uses portable null-safe parent correlation so roots match
roots. Every Nested Set relation's `getForeignKeyName(): string` returns the
parent model's configured parent column. The sibling relation's qualified
helper delegates to that shared implementation and qualifies the result
through the related model.

`BaseRelation` redeclares the inherited query property with the same native
Eloquent builder type and a truthful `@var QueryBuilder`, matching the subtype
enforced by its constructor. Keep forwarded `select()` calls separate from
`newQuery()` where PHPStan would otherwise infer the underlying base query
builder through Eloquent's mixin. Initialize eager index buckets before taking
references to them.

Order ancestors explicitly root-to-parent. Do not impose a default order on
descendants: a caller's `descendants()->orderBy(...)` remains authoritative.

For ancestor/descendant existence queries, correlate every inner alias scope
column with the corresponding outer row column. Correlated scope equality is
portable and null-safe: equal values or both null. Build each existence alias
from a class-default fresh model on the parent's connection so a prior alias
cannot become the `FROM` source. Copy no attributes or relations, dispatch no
model events, and never bind scope from that blank model. Derive the outer
qualifier from the supplied parent query so nested `whereHas()` levels
correlate against the immediately enclosing alias rather than the outermost
table.

`whereAncestorOf($node)` and `whereDescendantOf($node)` already own their scope
predicate. Remove the duplicate trailing scope predicate from both relation
`addConstraints()` methods. The new sibling relation applies scope exactly
once.

Eager constraints deduplicate and reduce parent intervals within each exact
scope, and constrain to no rows when the parent set is empty. Sibling matching
uses an operation-local scope-and-parent bucket, with a separate root bucket;
self exclusion is applied only for `siblings()`. Ancestor/descendant matching
uses four bounded paths and no others:

1. one parent: direct current scan;
2. results bucketed by exact scope during the existing O(R) construction pass;
3. monotonic descendant bucket: binary lower bound at `_lft + 1`, stop at
   `_rgt`;
4. non-monotonic/custom order or ancestors: scan only the matching scope
   bucket in query order.

For ancestors, retain the direct scan when all parents share one scope.
`matches()` remains the correctness authority. Do not port Aimeos's global
sort, position map, result re-sort, weak ancestor binary index, sweep-line
matcher, or further heuristics.

The base scope index stores only result models. Descendants add left-bound
values and monotonic-order tracking for their binary-search path; ancestors do
not allocate fields they never read.

Qualify sibling parent/key predicates through the related model so joins remain
unambiguous. Keep the configured plain and qualified foreign-key accessors on
the shared relation base.

## 7. Collection linking and traversal

`Collection::getRootNodeId()` returns `int|string|null`. `toTree()` and
`toFlatTree()` accept `Model|int|string|null|false`, where `false` alone asks
the collection to infer the root and explicit `null` selects root-level nodes.
Reject meaningless `true`. Never use `$root ?: null`.

Rebuild linking from array-backed parent buckets plus a separate roots list.
Clear stale parent/children relations before relinking. Each parent gets one
relation-free clone shared by its children; this exposes legitimate loaded
parent data without creating parent/children serialization cycles. Apply the
same rule during recursive model creation, cloning only after every child write
has settled the parent's final bounds and then assigning that clone in one
second pass. Every node, including a leaf, gets a loaded `children` relation;
child collections preserve the input collection order.

Remove the blanket `getArrayableRelations()` suppression. Use iterative
flattening to avoid recursive stack growth, but retain straightforward
recursion for already-nested rebuild input where no realistic stack failure
was demonstrated.

## 8. Structural writes and deletion

`makeGap($cut, 0)` returns `0` before constructing SQL. Do not also patch the
lower-level column expression for an unreachable zero update.

Use the indexable gap predicate `_rgt >= $cut`. Preserve Hypervel's narrower
movement range rather than Aimeos's broader overlap update, which includes
containing ancestors in no-op updates and widens locks.

Use known source/target bounds and depth in movement SQL. Refresh the source
only when the coroutine freshness marker proves another structural operation
could have made it stale or its bounds/depth were not selected. Reject direct
builder node data with absent or null structural values. Publish freshness
after that check and immediately before the movement update; publish new-node
insertion immediately before its gap update. Refresh `insertAfterNode()`'s
target after success, matching `insertBeforeNode()`, and invalidate structural
relations on refreshed or moved models.

Publication remains before the builder call when a requested movement has zero
distance. That may cause one conservative later refresh, but moving it after
the builder return would publish too late for callers that refresh related
models immediately after the structural operation.

Keep set-based descendant deletion as the default. It scales and preserves
existing Hypervel behavior, but descendant observers do not run. Expose only
these protected switches:

```php
protected function shouldFireDescendantEvents(): bool
{
    return false;
}

protected function getDescendantDeleteChunkSize(): int
{
    return 1000;
}
```

The opt-in evented path uses descending left-bound keyset chunks and a
per-model `deletingAsDescendant` flag to prevent the package's own recursive
cascade/gap handling while leaving application model events enabled. Set and
clear that flag with `try`/`finally`. Hard/force deletion is children-first and
includes trashed descendants. Soft deletion remains after the parent's own
soft delete so descendant timestamps are not earlier than the restore cutoff.
A child veto (`delete() === false`) throws; the documented application
transaction rolls back earlier work.

Every structural mutation, including one append/move/delete call, spans
multiple statements. Public docs require a database transaction and
application-owned serialization for concurrent writers to the same
connection/table/scope. Do not add a package mutex, distributed lock, retry,
timeout, config key, or implicit network operation.

## 9. Integrity, repair, and rebuild

`countErrors()` returns:

```php
[
    'invalid_intervals' => int,
    'duplicate_endpoints' => int,
    'missing_endpoints' => int, // portable 0/1 range invariant
    'crossing_intervals' => int,
    'missing_parent' => int,
    'wrong_parent' => int,
    'wrong_depth' => int,
]
```

`invalid_intervals` covers non-positive, reversed, and even-width intervals.
`duplicate_endpoints` owns endpoint uniqueness. `missing_endpoints` checks the
per-scope `min = 1` and `max = 2 * rows` invariant for non-empty trees; an
empty tree is healthy. It does not duplicate the distinctness category.
`missing_parent` counts non-null parent keys with no same-scope row.
`wrong_parent` covers an existing parent that is not the child's immediate
containing node. Compute it with a two-table child/parent join: a correct
existing parent contains the child and has
`child.depth = parent.depth + 1`. Do not retain the current three-table cross
join. `wrong_depth` covers non-zero roots and parent/child depth deltas other
than one.

Build endpoint events in SQL:

```text
_lft -> delta +1, expected active-before = depth
_rgt -> delta -1, expected active-before = depth + 1
```

Compare expected depth with the preceding window sum ordered by endpoint and
coalesce the first empty frame to zero. Evaluate `crossing_intervals` only
when endpoints are unique; a database-side duplicate guard makes it return
`0` otherwise. This zero is conditional, not proof of no crossing. Allow
crossing and wrong-depth, and wrong-parent and wrong-depth, to co-fire.

For scoped models, `countErrors()`, `getTotalErrors()`, and `isBroken()` require
a concrete `scoped([...])` selection. Presence is the criterion; null may be a
concrete scope value. Check presence with `array_key_exists()` against raw
attributes: `scoped(['menu_id' => null])` is concrete, while a blank model is
not. Otherwise throw a `LogicException` naming `scoped()`.

`getTotalErrors()` remains the sum for compatibility, but documentation states
that non-zero means broken and its magnitude is not a unique-node count.
`isBroken()` performs cheap indexed/aggregate existence checks first and the
window check last. `countErrors()` assembles readable helper subqueries into
one portable select and therefore remains one round trip, including the
database-side duplicate guard. Keep SQL work bounded; do not materialize the
tree in PHP or use a quadratic crossing join.

`fixTree(?Model $root = null, array $extraColumns = [])` and
`fixSubtree(Model $root, array $extraColumns = [])` select only structural
columns plus explicit observer-required fields and use the structural builder.
Before subtree repair/rebuild writes, require a persisted root with a key,
loaded bounds/depth, and every concrete scope attribute. Scope errors retain
the `scoped([...])` guidance and name the absent attribute. A separate
`exists` query with a `whereIn` subquery then rejects a parentage edge that
leaves the supplied root's stored interval. This proves the range-selected
repair set is complete; otherwise the operation fails descriptively rather
than reporting success over rows it could not see. Rebuild performs both
checks before creating any temporary zero-bound nodes.
Repair maintains depth with iterative traversal over a separate ordered roots
list and plain non-null parent buckets. Whole-tree unresolved components become
database roots; subtree unresolved components become direct children of the
supplied root. Components are promoted in dictionary insertion order inherited
from the `defaultOrder()` result. This handles missing parents and cycles
without recursion, a second scan, or a null marker that collides with a valid
empty-string model key. When a subtree gap update can shift selected rows,
reconcile each selected model's original snapshot with the exact gap
transformation, then persist every renumbered row without rereading it. Keep
the pre-gap dirty-node tally separate for the public result. Repair/rebuild use
the structural builder for every read and gap write. Every repair/rebuild save
is checked through one shared helper. A model-event veto throws
`LogicException` naming the model class and key so the caller's transaction
rolls back every earlier structural write.

Use truthful rebuild contracts:

```php
public function rebuildTree(array $data, bool $delete = false, ?Model $root = null): int;
public function rebuildSubtree(Model $root, array $data, bool $delete = false): int;
```

Copy each payload and unset `children` plus the model key before `fill()`.
Primary keys identify matches and are never mass-assigned. Maintain depth and
scope throughout repair/rebuild.

## 10. Bounded immutable metadata cache

Cache `NestedSet::isNode()` results per concrete class using
`class_uses_recursive()`. The measured saving is about 2.5 microseconds per
call and matters across large model/relation construction workloads. The map
is immutable and bounded by model classes loaded in one worker.

Add `NestedSet::flushState()` and register it once in
`AfterEachTestSubscriber::flushNestedSetState()`. Do not add a second
soft-delete cache or automatically make explicit `getAncestors()`,
`getDescendants()`, or `getSiblings()` return loaded relations: those methods
retain fresh-query semantics, while relation properties/eager loading already
provide caching.

## 11. Documentation and cleanup

Update `src/boost/docs/nested-set.md` in nearby Laravel-style language for:

- bigint/integer/UUID/ULID helpers and scoped index prefixes;
- mandatory stored depth and the optional application depth index;
- sibling relations and custom builders;
- literal nested-set scopes versus visibility global scopes;
- error categories and required scoped diagnostics;
- repair extra columns and rebuild contracts;
- bulk versus protected evented descendant deletion;
- per-mutation transactions and application serialization;
- aborting the transaction when an ordinary boolean-returning model mutation
  is vetoed; and
- measured index/read/write tradeoffs without internal algorithm narration.

Update canonical examples from `increments()` to `$table->id()`. Remove stale
parent metadata, dead commented fixture resets, unused `dumpTree()` and
duplicate integrity assertions/helpers, unused `NestedSet::BEFORE` and
`NestedSet::AFTER`, obsolete PHPStan ignores, and any comments that describe
replaced behavior. Review all 112 current package-local PHPStan suppressions;
remove those made obsolete by corrected types and convert every survivor to
the exact diagnostic identifier it owns. Trait-provided Nested Set methods
reached through a base `Model` type require local `method.notFound`
suppressions because PHPStan rejects traits as types. Do not add an
analyzer-only package interface. Add truthful native types to modified fixture
scope methods.

Keep `dirtyBounds()` bounds-only. Ordinary Eloquent dirtiness already detects
a real depth change, so nulling the original depth would add noise rather than
correctness.

Use truthful native types on modified relation, builder, model-key, and
structural setter methods. Add concise Laravel-style method docblocks
throughout every modified source file, with short rationale comments only for
the endpoint-window invariant, isolated relation aliases, and other logic that
is not self-evident from the code.

## 12. Audit records

Add one companion-ledger work unit for the implemented Nested Set findings,
rejected concerns, API result, cross-package revalidation, gates, and review.
Keep `database-10` revalidated and record use of Eloquent's existing
soft-delete/builder metadata rather than a new package cache.

This remediation does not by itself mark the package checklist complete: the
owner will decide afterward whether the prior audit is sufficient or a fresh
package-wide audit is required. Do not replace the independently active
Reverb routing entry.

## Test plan

Audit every current Aimeos test and merge relevant coverage into the existing
Hypervel tests while preserving Hypervel-specific regressions.

### Unit and SQLite/Testbench regressions

- collection inference and explicit roots: null, `0`, empty string, integer,
  numeric string, UUID, ULID, and model;
- parent fill-order, numeric request strings, UUID/ULID parents, missing and
  trashed parents, key `0`, and cross-scope rejection;
- per-class soft-delete metadata and re-entrant exact-timestamp restore,
  including a descendant deleted before the nested restore cutoff;
- shared-table model aliases, logical connection aliases, tables,
  connections, coroutine isolation, and repair/rebuild freshness publication;
- compatible/incompatible/default custom builders and Model cache cleanup;
- persisted/unsaved identity, scope-aware ancestor/descendant/sibling/child
  predicates, scoped scalar and node before/after predicates, boolean
  grouping, structural coordinate lookups across trashed visibility modes,
  default/explicit logical connection aliases, and persisted 0/0 target
  rejection, plus cross-connection mutation-target rejection and
  concrete-scope enforcement for every scalar scoped lookup;
- lazy/eager/existence/count sibling relations, custom parent columns,
  configured plain foreign keys across sibling/ancestor/descendant relations,
  qualified relation predicates after joins, null-root correlation, null
  scopes, root-to-parent ancestors, nested `whereHas()` alias correlation,
  event-free connection-preserving existence-query construction, and exactly
  one scope predicate per relation;
- joined root/leaf/has-children/before/after/default-order,
  `withoutRoot()` / `hasParent()`, and all next/previous node and sibling
  queries with qualified structural columns;
- blank scoped model family across relation existence, `withDepth()`,
  `fixTree()`, and diagnostics, including Aimeos's global-scope fixture proving
  structural paths ignore visibility scopes;
- scope tuple int/string/enum/date/stringable/null/empty/bool/delimiter cases,
  model date-format overrides, plus descriptive float/non-stringable
  rejection;
- one-parent, one-scope, multi-scope, monotonic descendant, custom-order
  descendant, and ancestor eager matching;
- parent serialization, relation-free clones, partial relink, recursive create,
  multiple roots, wide/deep iterative flattening, and no cycles;
- every insertion/move direction, root/sibling/child depth, partially selected
  targets and sources through both insert and move paths, root-position
  derivation, source/target truthfulness, relation invalidation,
  append/prepend returning with the caller's parent `_rgt` refreshed in
  memory, no redundant first-operation source refresh, replication depth
  exclusion, and zero-height gap with no query;
- `defaultOrder()` replacing raw and union ordering without leaving stale
  bindings;
- bulk and evented deletion query/chunk behavior, order, veto, partial-source
  soft/force paths, independently deleted descendants, and exact restoration;
- every integrity category, scoped refusal, conditional crossing under
  duplicates, short-circuit behavior, and healthy empty/deep/scoped trees;
  replace the existing `oddness`/`duplicates` assertions with the final named
  category contract;
- repair/rebuild depth, scopes, extra observer columns, whole-tree orphans,
  subtree missing/outside/null parents, cycles, healthy named diagnostics,
  incomplete root and complete subtree selection refusal before writes,
  post-gap persistence of unchanged rows, `rebuildSubtree()` through the
  shared repair path, model-only roots, key exclusion, delete-missing,
  iterative traversal, and transaction-backed rollback of ordinary and
  structural model writes when a repair or rebuild save is vetoed;
- `isNode()` class separation, trait-of-trait detection, non-object/null
  handling, and framework cleanup registration; and
- Blueprint macro use in separate test methods, proving flush/re-registration.

### Database integration

Add driver-routed coverage under:

```text
tests/Integration/NestedSet/Database/MySql
tests/Integration/NestedSet/Database/MariaDb
tests/Integration/NestedSet/Database/Postgres
tests/Integration/NestedSet/Database/Sqlite
```

Use the existing `RequiresDatabase`/database test infrastructure. Share a base
only where setup and assertions are genuinely common.

Across the matrix, prove:

- exact bigint/integer/UUID/ULID parent types, mandatory depth, scoped and
  unscoped index column order, and symmetric drops;
- native UUID trees where the driver supports them and compatible UUID storage
  elsewhere;
- integer and UUID structural mutation/depth, scope isolation, soft deletes,
  repair, relations, and diagnostics;
- MySQL/MariaDB depth-before-bound assignment correctness; and
- persisted moved-subtree depth across every database driver; and
- portable endpoint-window SQL and schema introspection.

Do not assert elapsed time, a chosen optimizer plan, or cross-transaction lock
timing in normal tests. Benchmark evidence remains in this plan; functional
tests assert schema and results deterministically.

### Validation

Run changed files immediately, then:

```sh
./vendor/bin/phpunit --no-progress tests/NestedSet
./vendor/bin/phpunit --no-progress tests/Integration/NestedSet
composer fix
```

Run driver integration groups with the repository `.env` credentials for
MySQL, MariaDB, PostgreSQL, and SQLite. Finish with a fresh diff review of
every caller/callee, scope and lifecycle boundary, SQL shape, retained memory,
public API/doc statement, dead path, and added mechanism before requesting
code review.

## Explicit rejections

- no mechanical Aimeos wholesale port or strict future merge parity;
- no optional-depth fallback, `hasColumn()` branch, or schema cache;
- no default depth index, widened `_lft` index, driver hint, or FK;
- no package-owned lock, retry, timeout, or config;
- no model-class/object-identity freshness key or scope-specific invalidation;
- no restore timestamp stack/registry or rounded timestamp;
- no whole-tree PHP/Fenwick scan or quadratic crossing join;
- no global eager sort, position map, result re-sort, ancestor binary index,
  sweep-line matcher, or additional adaptive branch;
- no default descendant ordering that overrides caller ordering;
- no encoded scalar ID dictionaries or `(int)` UUID/ULID normalization;
- no automatic explicit-relation getter cache;
- no unconditional movement refresh or locally reimplemented target algebra;
- no broad overlap movement update without new evidence;
- no default event-per-descendant deletion;
- no iterative rewrite of already-nested rebuild input;
- no `replicateQuietly()` or `newInstance()` existence-alias construction, and
  no per-result `fromDateTime()` connection resolution;
- no arbitrary dynamic schema type or introspective drop helper; and
- no source/test compatibility layer for the old schema or result categories.
