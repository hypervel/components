# Permission Correctness, Extension Parity, and Relation Scope Safety

Status: implementation, authoritative gate, final self-review, and independent code review complete.

## Scope and outcome

Complete the reported Permission maintenance against Hypervel's partitioned, denied-permission, coroutine-safe implementation and current Spatie Permission. Restore replacement-event parity, the documented custom-pivot extension pattern, exact team identifiers, package discovery, split-package metadata, current supported upstream coverage, and concise public documentation. Fix the related Eloquent pivot-scope defects at Database, their lowest owner.

Preserve Hypervel's existing advantages: compact worker-cached permission assignments, zero-query warm authorization checks, immutable partition/team relation contexts, transactional effect synchronization, listener-gated event work, and exact cache invalidation. This is targeted maintenance, not the later fresh package-wide audit; Permission remains unchecked in the core package checklist.

References checked for this design:

- Hypervel baseline 0848a9c05cff9dcf15af0ce2a2b3722bda3981ad;
- every reported Permission source, test, metadata, command, provider, cache, relation, and Boost-documentation surface;
- current Spatie Permission afd24018f68306e8b43ace487c107a59af1be776, including the custom-pivot, sync-event, command, guard, cache, role, permission, and wildcard tests;
- Hypervel BelongsToMany, MorphToMany, InteractsWithPivotTable, Pivot, MorphPivot, their event suites, and the corresponding current Laravel implementations;
- root and split Composer manifests, DefaultProviders, package-discovery tests, and existing package metadata contracts;
- carried support-02 and permission-01 through permission-05.

## What this audit is not

The following wording is retained verbatim from the core audit plan. It includes the complete “What this audit is not” section plus principles 7–10 because those principles govern hot-path quality, superseded design, remediation choice, and speculative complexity; principles 1–6 govern the broader audit procedure and remain in the core plan.

This audit is not permission to add defensive machinery for every imaginable failure. Do not add an abstraction, state machine, retry loop, configurable timeout, registry, mutex, context slot, cache, or compatibility API merely because it sounds robust.

Complexity must pay for itself with at least one of:

- a demonstrated failure;
- a complete source trace proving a realistic vulnerable schedule;
- a clear general capability with real consumers and owner approval;
- deletion of greater or riskier complexity elsewhere.

Typical Laravel lifecycle semantics define the supported contract. A package that intentionally relies on model events, middleware, listeners, transactions, or another documented mechanism is not defective merely because userland can explicitly bypass that mechanism. Do not build a parallel enforcement path for `withoutEvents()`, raw database writes, disabled middleware, direct transport access, or comparable deliberate bypasses unless the public contract explicitly promises behavior through that bypass.

Underengineering is equally a failure. Fix every verified defect completely at its lowest owning boundary, never with a partial fix or a local patch over a broken shared contract, and always surface meaningful evidence-backed improvements rather than dropping them to avoid effort. Restraint applies to speculative machinery and cosmetic change, not to complete fixes or worthwhile opportunities.

Do not treat an upstream difference as a bug without tracing it. Do not treat upstream parity as proof of correctness. A real Hypervel defect remains a defect when Laravel, Hyperf, Symfony, or an SDK has the same hole.

The audit categories are discovery lenses, not boundaries around what may be corrected. Any genuine issue discovered while auditing, implementing, testing, or reviewing must be investigated, assigned to its lowest owning boundary, and taken through the applicable consensus, implementation, validation, review, and approval workflow—even when it is outside the current package, initial taxonomy, or changed diff. Do not dismiss a verified issue as unrelated or defer it merely to preserve package order. This rule applies only after the evidence threshold is met; it does not turn speculative concerns, deliberate bypasses, unsupported use, or contract violations into work.

#### 7. Preserve hot-path quality

For every fix, inspect:

- additional allocations;
- container or facade resolutions;
- locking and atomics;
- hashing and serialization;
- new yields or sleeps;
- retries and polling;
- logging or exception construction;
- retained worker memory;
- cache invalidation and eviction.

A correctness guard on a cold failure path has a different cost from a new lock or resolver on every request. State the difference explicitly.

Any proposed change with a measured or source-proven hot-path regression requires explicit owner approval before implementation, even when it fixes a defect. Present the expected frequency and magnitude, the evidence, and the viable alternatives. Do not hide an unavoidable tradeoff inside a general correctness claim.

Performance improvements must provide a meaningful practical benefit after accounting for code complexity and divergence from upstream. Measure representative behavior where practical. Always surface an evidence-backed opportunity to the owner, but do not implement it without approval; a micro-optimization within measurement noise is neither a reason to diverge nor an actionable finding.

#### 8. Remove superseded design completely

When a fix changes the owning model, delete obsolete helpers, callbacks, properties, config keys, comments, tests, and documentation. Do not leave a compatibility path or comment describing behavior that no longer exists. Preserve intentional upstream comments unless the new design makes them incorrect.

#### 9. Treat remediation patterns as candidates

The established patterns later in this plan are a vocabulary, not a lookup table. Choose among per-call parameters, immutable values, scoped bindings, cloning, CoroutineContext, factories, explicit ownership, static reset, or resource teardown only after proving the real lifetime and owner.

#### 10. Reject speculative complexity

Record low-confidence concerns under rejected or unresolved analysis. Do not implement them. Surface every evidence-backed, meaningful non-defect improvement to the owner with its benefit, cost, and alternatives, then stop for explicit approval. This requirement exists to keep worthwhile opportunities visible, not to discourage finding them.

## Final design

### 1. Keep all destructive pivot predicates inside the relation identity

Database owns three live relation defects:

- custom-pivot detach/update selects a scoped row, then Pivot::delete() or save() drops the relation predicates;
- newPivotQuery() replays non-leading or predicates without grouping, allowing SQL precedence to escape the parent identity;
- wherePivotBetween(), including its or/not variants, is applied to reads but never recorded for destructive writes.

BelongsToMany will record a fourth predicate family:

~~~php
protected array $pivotWhereBetweens = [];

public function wherePivotBetween(
    mixed $column,
    array $values,
    string $boolean = 'and',
    bool $not = false,
): static {
    $this->pivotWhereBetweens[] = func_get_args();

    return $this->whereBetween(
        $this->qualifyPivotColumn($column),
        $values,
        $boolean,
        $not,
    );
}
~~~

Both newPivotQuery() and hydrated pivot key queries will replay all four families inside one nested where group. Parent, related, primary, and morph identity clauses remain outside that group:

~~~php
$query->where(function (QueryBuilder $query): void {
    foreach ($pivotWheres as $arguments) {
        $query->where(...$arguments);
    }

    foreach ($pivotWhereIns as $arguments) {
        $query->whereIn(...$arguments);
    }

    foreach ($pivotWhereNulls as $arguments) {
        $query->whereNull(...$arguments);
    }

    foreach ($pivotWhereBetweens as $arguments) {
        $query->whereBetween(...$arguments);
    }
});
~~~

This produces identity AND (recorded predicate OR predicate), never identity OR predicate.

AsPivot will hold one nullable set of relation predicate descriptors. BelongsToMany::newPivot() and MorphToMany::newPivot() attach it whenever any replayable relation constraint exists, regardless of whether the pivot class is stock or custom:

~~~php
public function setPivotConstraints(
    array $wheres,
    array $whereIns,
    array $whereNulls,
    array $whereBetweens,
): static;

$pivot->setPivotConstraints(
    wheres: $this->pivotWheres,
    whereIns: $this->pivotWhereIns,
    whereNulls: $this->pivotWhereNulls,
    whereBetweens: $this->pivotWhereBetweens,
);
~~~

The pivot applies the grouped predicates from the composite foreign/related-key branches of setKeysForSelectQuery(), setKeysForSaveQuery(), and getDeleteQuery(). A pivot table with a real primary key keeps the exact parent save/delete path: its unique key already identifies one row, and appending the old relation scope would incorrectly prevent an intentional constrained-column change. Unconstrained pivots keep null state and add only one predictable null guard on explicit composite-key pivot save/delete/select operations; ordinary relation reads and authorization do not enter this path. MorphPivot's composite-key delete branch must retain the grouped predicates before adding morph identity.

InteractsWithPivotTable::getCurrentlyAttachedPivotsForIds() will hydrate through newExistingPivot() instead of duplicating fromRawAttributes(), key, and related-model setup. The MorphToMany post-map becomes dead and is removed; newPivot() remains the sole owner of class, keys, related model, morph metadata, and constraint descriptors.

For stock pivots, this also aligns explicit current-pivot hydration with ordinary relation hydration: both pass raw database rows through newExistingPivot() and the model's attribute/date normalization. Custom pivots remain byte-identical because Model::newPivot() continues constructing a configured using() class through fromRawAttributes().

The same shared owner has two mass-assignment defects on explicit custom-pivot writes. updateExistingPivotUsingCustomClass() calls fill(), and castAttributes() does the same while preparing attach, sync, and toggle records. A restrictive guarded/fillable policy can silently discard requested values; a totally guarded pivot or strict model mode instead throws MassAssignmentException. Stock relation writes apply no mass-assignment policy.

Replace both calls with forceFill(). castAttributes() remains required: a using() class is built through fromRawAttributes(), so this is its only cast-on-write pass. forceFill() runs the same setAttribute() loop and preserves casts, mutators, timestamps, and model events without treating developer-authored relation attributes as request mass assignment:

~~~php
$updated = $pivot ? $pivot->forceFill($attributes)->isDirty() : false;

$attributes = $this->newPivot()->forceFill($attributes)->getAttributes();
~~~

This is coroutine-safe in Hypervel: forceFill() uses GuardsAttributes::unguarded(), whose flag is stored in CoroutineContext and restored in finally. It does not open Laravel's process-global unguarded window across sibling requests.

Do not alter the public read-query rule that an ungrouped orWherePivot() can escape a relationship, matching Laravel's general relationship-orWhere contract. This correction only prevents destructive relation operations and public pivot-instance writes from losing identity while replaying relation-owned constraints.

Durable findings: database-29 (constrained hydrated/custom pivot writes), database-30 (grouped or replay), database-31 (wherePivotBetween write scope), database-32 (custom-pivot explicit updates bypass mass-assignment filtering), database-33 (custom-pivot attach/sync attributes bypass mass-assignment filtering).

### 2. Honor Permission's public custom-pivot extension without slowing authorization

Spatie's supported extension shape aliases the trait relation and appends using():

~~~php
use HasPermissions {
    permissions as traitPermissions;
}

public function permissions(): BelongsToMany
{
    return $this->traitPermissions()->using(CustomPermissionPivot::class);
}
~~~

Database-33 is a prerequisite for this extension contract: Permission's partitioned attach formatter delegates to the shared castAttributes() path, so guarded custom-pivot attributes must survive there before Permission can honor using() on attachPermissions(), syncPermissions(), assignRole(), and syncRoles().

Keep empty and unsaved early returns free of relation construction. Normalize inputs and make pre-write decisions with the ambient assignment context. On a saved path that reaches database comparison or mutation, resolve the public roles()/permissions() relation once at the point it is first needed, then pass that selected relation through the remaining comparison, attach, detach, effect-update, and cache-invalidation work. Its captured PermissionRelationContext becomes authoritative from that boundary onward. Saved assignRole() therefore builds the selected relation and runs one scoped pivot read, limited to the requested IDs, before its already-assigned return; this is required to compare the overridden relation's real rows rather than the compact warm cache.

~~~php
$relation = $this->permissions();
$context = $this->permissionRelationContext($relation);
~~~

EnforcesPermissionPartition exposes the captured immutable value without allowing mutation:

~~~php
public function getPermissionRelationContext(): PermissionRelationContext
{
    return $this->permissionRelationContext;
}
~~~

HasPermissions owns one reused narrowing helper rather than adding an internal interface or repeating annotations at every operation:

~~~php
protected function permissionRelationContext(BelongsToMany $relation): PermissionRelationContext
{
    /** @var PartitionedBelongsToMany|PartitionedMorphToMany $relation */
    return $relation->getPermissionRelationContext();
}
~~~

Keep the native parameter typed BelongsToMany and the union as an inline @var inside the helper. Do not promote the union to @param: callers correctly hold the public BelongsToMany return type, and narrowing the parameter would make every call a static-analysis error. HasRoles composes HasPermissions, so both role and permission operations share this helper.

The context ownership split is exact:

- attachPermissions(), syncPermissions(), syncPermissionEffects(), revokePermissionTo(), assignRole(), removeRole(), and syncRoles() keep their ambient helper for input normalization, empty-input returns, and unsaved queueing, then switch to the selected relation's context when the saved write path first needs that relation;
- keep permissionAssignmentContext() in getCachedDirectPermissions() and roleAssignmentContext() in getCachedRoles(), including warm reads, because those helpers provide the only fail-closed partition check when no relation is built.

Neither ambient context helper is removed. Warm authorization and unsaved queueing must not construct a per-operation relation merely to obtain context. Saved no-op replacement and duplicate-assignment paths build the selected relation because its real rows define the result, but they perform no mutation or cache invalidation. On saved write paths, building the supported relation calls ensurePermissionRelationParentMatches(), which performs the same validation before the relation-derived context replaces the ambient value, so no guard is lost. Both values come from the same coroutine-local state during one method invocation.

The supported override aliases the package relation, so it remains one of the two partitioned classes. An override that replaces the package relation entirely fails fast at the undefined accessor; do not add an instanceof fallback that would silently discard captured-context ownership. getPermissionRelationContext() remains non-nullable: both partitioned relation constructors initialize the trait state before parent::__construct(), so the typed property is always set before the relation can escape construction. This preserves using(), custom casts, timestamps, model events, and one authoritative immutable team/partition context without a discovery relation, interface, or registry.

Stock Pivot::class keeps existing bulk/set-based writes. Custom classes use native per-row Eloquent operations:

~~~php
if ($relation->getPivotClass() === Pivot::class) {
    $relation->newPivotQuery()
        ->whereIn($relatedPivotKey, $updatedIds)
        ->update(['is_denied' => $isDenied]);
} else {
    foreach ($updatedIds as $id) {
        $relation->updateExistingPivot($id, ['is_denied' => $isDenied], false);
    }
}
~~~

Touch once after the complete mutation. Replace the now-false comment claiming permission assignment pivots have no custom class with a concise explanation of the stock bulk/custom native branch. Database's corrected predicate ownership makes a Permission-local scope guard unnecessary; no such compensation exists to remove.

syncRoles() reads the current scoped IDs once, indexes current and requested IDs through their collision-safe identities, and detaches or attaches only the set difference. A retained custom pivot row is never deleted and recreated, so application attributes, timestamps, and model identity survive replacement. The detached event still receives the complete pre-operation set, same-set replacements still dispatch the documented pair, and no-op replacements perform no mutation, touch, or cache invalidation.

Unsaved assignments capture the registrar's memoized pivot class beside PermissionRelationContext; they do not construct a per-operation write relation:

~~~php
[
    'permissions' => $permissions,
    'pivot' => $pivot,
    'context' => $context,
    'pivotClass' => $registrar->getAssignmentPivotClass($this, 'permissions'),
]
~~~

Queued-batch identity includes the pivot class. Save-time flush rebuilds the protected captured-context relation, reapplies only a non-stock class with using(), and never consults future ambient context.

PermissionRegistrar::getAssignmentPivotClass() owns a bounded instance memo keyed by subject model class and relation name for boot-stable pivot-class metadata. Its cold resolver builds the actual public relation from the actual model; later unsaved operations only read the memo. Reinitializing registrar model/cache configuration clears this instance memo. It is not static, does not retain request models, and needs no flushState() or subscriber entry.

The compact direct-assignment cache remains permission ID plus is_denied only. App-owned custom pivot attributes must not enter it because arbitrary pivot saves cannot invalidate that catalog.

Authorization and name-only APIs stay on getCachedDirectPermissions():

- hasDirectPermission();
- hasDeniedPermission();
- getPermissionNames().

Only public methods that return Permission models/pivots switch to the real relation for a configured custom pivot:

- getDirectPermissions();
- the direct leg of getAllPermissions().

Keep allowedDirectPermissions() on getCachedDirectPermissions() so getPermissionNames() stays warm and query-free. getDirectPermissions() and getAllPermissions() independently select the memoized stock/custom source, then apply the same is_denied rejection. Do not redirect allowedDirectPermissions() to the public relation.

relationCollection()/loadMissing performs at most one query per model per coroutine; the Eloquent relation cache makes later calls zero-query. getCachedDirectPermissions() already returns a loaded current relation, so subsequent authorization on the same model uses the same source without another query.

Reverse arbitrary-model operations assignToModels(), removeFromModels(), and syncModels() retain upstream behavior and do not honor a subject relation override; document that boundary rather than adding a model/relation registry.

Durable findings: permission-07 and permission-18.

### 3. Build valid pivots from Permission's warm caches

The direct-assignment cache currently attaches an unconfigured base Pivot. Public pivot methods can then fail because foreign/related keys and morph metadata are absent. Put the exact identifiers with their existing metadata family in Support\Config:

~~~php
public const MORPH_NAME = 'model';
public const MORPH_TYPE = self::MORPH_NAME . '_type';
~~~

Replace the constructor literal inside permissionMorphToMany() with the name constant. Replace all five independent model_type column literals—HasPermissions hard-delete fast path, scope-discovery select, hard-delete transaction, warm hydration, and HasRoles scopeTeam subquery—with the type constant. Do not add a new permissionMorphToMany() parameter. Build the stock MorphPivot directly from already-known metadata, with no relation query or larger cache entry:

~~~php
$morphType = Config::MORPH_TYPE;

$pivot = MorphPivot::fromRawAttributes(
    $model,
    $attributes,
    Config::modelHasPermissionsTable(),
    true,
);

$pivot->setPivotKeys(Config::morphKey(), $registrar->pivotPermission)
    ->setRelatedModel($permission)
    ->setMorphType($morphType)
    ->setMorphClass($model->getMorphClass());
~~~

The related model is the cloned Permission returned to the caller. Team and partition columns already present in the cached hydration remain attached. Because this synthetic warm pivot does not pass through a relation's newPivot(), also attach exact current-context predicates directly: partition and non-null team values become pivot where descriptors; a global-team null becomes a pivot where-null descriptor. This keeps public save()/delete() inside the cached assignment's scope without building a relation or adding a query:

~~~php
$pivotWheres = $context->partition
    ? [[$context->partition->column, '=', $context->partition->value]]
    : [];
$pivotWhereNulls = [];

if ($context->teamScoped) {
    if ($context->team === null) {
        $pivotWhereNulls[] = [$registrar->teamsKey];
    } else {
        $pivotWheres[] = [$registrar->teamsKey, '=', $context->team];
    }
}

if ($pivotWheres !== [] || $pivotWhereNulls !== []) {
    $pivot->setPivotConstraints(
        wheres: $pivotWheres,
        whereIns: [],
        whereNulls: $pivotWhereNulls,
        whereBetweens: [],
    );
}
~~~

Keep this bounded construction beside warm pivot hydration; do not add methods to PermissionRelationContext solely for this one consumer. Add load-bearing comparisons of compiled delete-query SQL and bindings for warm-cached and relation-hydrated pivots on the same edge under four real scope shapes: non-null team, global null team, partition only, and team plus partition. Use test-local protected-method access, not a production seam, and do not assert pivotRelated: setRelatedModel() is part of authoritative pivot construction, but that stored property currently has no reader. Cover getForeignKey(), save(), and delete() on a warm stock pivot, including sibling team and partition rows.

The worker permission catalog has the same structural defect on its role edges. getHydratedPermissionRoleCollection() attaches a Pivot without keys or a related model, so public key accessors and composite-key save/delete fail. Complete that Hypervel-owned Permission::roles pivot with the registrar's permission/role key names, related Role, exists=true, and the already resolved partition descriptor. The role_has_permissions table has no team dimension.

getPermissionsViaRoles() and the via-role leg of getAllPermissions() must not hand out the catalog's Pivot instance. Besides exposing the opposite Permission::roles key orientation from Spatie's Role::permissions result, sharing the object lets caller mutation alter the coroutine-cached denied edge used by later authorization. Clone the Permission first and build one fresh, upstream-oriented Pivot from the cached edge attributes:

~~~php
$pivot = Pivot::fromRawAttributes(
    $role,
    $cachedPivot->getAttributes(),
    Config::roleHasPermissionsTable(),
    true,
);
$pivot->setPivotKeys($registrar->pivotRole, $registrar->pivotPermission)
    ->setRelatedModel($permission);
~~~

Resolve the registrar and current partition once in loadPermissionsViaRolesWithPivots(), pass them through the existing mapping call, and attach the single partition descriptor when present. Do not build owning relations per edge and do not extract a generic pivot builder: the direct MorphPivot, catalog Permission::roles Pivot, and public Role::permissions Pivot differ in parent, class, keys, related model, and constraints.

This result construction is paid only by model-returning via-role APIs and the memoized wildcard-index build, once per edge beside the Permission clone already created there. Ordinary non-wildcard authorization is unchanged; completing catalog pivots adds only setter calls during the existing once-per-coroutine catalog hydration.

The custom-permission-class branch of PermissionRegistrar::getPermissions() cannot feed permissionWithRolePivot() in supported use: HasPermissions::getPermissionClass() memoizes the registrar's configured class, and registrar reconfiguration is boot/test-only. Do not manufacture coverage for mid-coroutine reconfiguration.

Durable findings: permission-13 and permission-16.

### 4. Restore complete Permission replacement events

For a saved model, syncPermissions() and Hypervel's syncPermissionEffects() use one replacement contract:

1. capture the current relation context and selected public relation;
2. only when events are enabled and PermissionDetachedEvent has a targeted listener, hydrate the pre-operation Permission collection through that relation;
3. run the existing transaction and effect-aware synchronization;
4. invalidate the exact catalog/model cache after successful commit;
5. dispatch a nonempty detached pre-operation collection, then the requested attached IDs.

~~~php
$detached = $this->permissionDetachedEventIsListenedFor()
    ? $relation->get()
    : new Collection;

$changes = $this->synchronizePermissionAssignments(...);
$this->forgetPermissionAssignmentCache(...);

if ($detached->isNotEmpty()) {
    $this->dispatchPermissionDetachedEvent($detached);
}

$this->dispatchPermissionAttachedEvent($permissions);
~~~

The detached payload describes replacement, not a delta: retained permissions appear, and same-set sync still reports the current collection. A failed transaction dispatches neither event. Unsaved sync remains attached-only because there is no persisted set to detach.

Reusing the selected relation after get() is deliberate. get() changes only the relation read builder's selected columns; attach, detach, and effect updates create fresh pivot statements through newPivotQuery(). Direct get() also bypasses the relation's getResults() override, so it does not populate or mark the model relation cache. Keep the one extra detached read separate rather than rebuilding the relation and recapturing ambient context.

Both replacement methods keep the exact synchronization result. They clear loaded relations and invalidate catalog/model caches only when at least one edge is attached, detached, or updated; a same-set replacement still dispatches both events but preserves every warm cache.

The ordinary path gains no event construction. The only listener-driven extra read is on saved replacement when events are enabled and a targeted detached listener exists; the synchronizer's existing pivot comparison read remains required.

Durable findings: permission-06 and permission-17.

### 5. Preserve exact team identifiers and truthful setup status

CreateRoleCommand and AssignRoleCommand read team-id once and distinguish only null/empty from a supplied value:

~~~php
$teamId = $this->option('team-id');
$hasTeamId = $teamId !== null && $teamId !== '';
~~~

Use those values for disabled-team warnings, setPermissionsTeamId(), and the global-role warning. String "0" remains a real team identifier. Preserve and restore the prior team context in finally. CreateRoleCommand continues assigning role permissions after restoration because that edge is not team-scoped.

UpgradeForTeamsCommand returns the native copy result and fails the command when publication fails:

~~~php
if (! $this->createMigration()) {
    $this->error(...);

    return self::FAILURE;
}

return self::SUCCESS;
~~~

~~~php
try {
    return copy($migrationStub, $this->getMigrationPath());
} catch (Throwable $throwable) {
    $this->error($throwable->getMessage());

    return false;
}
~~~

Use a portable invalid destination in the regression. Keep the existing framework warning-to-exception handler; add no temporary global error handler or production test seam.

Durable findings: permission-08 and permission-14.

### 6. Remove unused queued-mutation result computation

The four queued replacement/removal helpers return booleans that no caller reads. Convert them to void and remove comparison-only current/replacement arrays and changed flags. Preserve filtering, no-op early returns, nonempty requeueing, captured context, and pivot-class identity.

~~~php
private function replaceQueuedPermissionAssignments(...): void
{
    $this->queuedPermissionAssignments = array_values(array_filter(...));

    foreach ($assignments as $assignment) {
        if ($assignment['permissions'] !== []) {
            $this->queuePermissionAssignments(...);
        }
    }
}
~~~

Apply the same cleanup to queued roles. Do not alter persistence behavior or add new helper layers.

Durable finding: permission-15.

### 7. Make root discovery and split dependencies self-verifying

Add Permission, Horizon, and Wayfinder providers to the root extra.hypervel.providers list. Do not add optional packages to DefaultProviders.

Extend tests/Composer/PackageManifestConsistencyTest.php with one repository-wide invariant:

~~~php
foreach ($this->splitManifests() as $manifest) {
    $composer = $this->decodeManifest($manifest);

    foreach ($composer['extra']['hypervel']['providers'] ?? [] as $provider) {
        $this->assertTrue(
            in_array($provider, $rootProviders, true)
                || in_array($provider, $defaultProviders, true),
        );
    }
}
~~~

Foundation is the expected DefaultProviders-only case. This prevents future split/root discovery drift without duplicating provider lists in package-specific tests.

Permission split metadata adds the direct dependencies already pinned by the root:

~~~json
"composer-runtime-api": "^2.2",
"nesbot/carbon": "^3.13.1",
"symfony/http-kernel": "^8.1"
~~~

Remove PermissionServiceProvider's class_exists guard around AboutCommand registration and the matching test skips.

Apply the same exact direct-dependency correction to the verified sibling manifests:

| Dependency | Split packages |
|---|---|
| symfony/http-kernel ^8.1 | broadcasting, contracts, passkeys, telescope |
| nesbot/carbon ^3.13.1 | concurrency, contracts, notifications, passkeys, process, telescope |
| composer-runtime-api ^2.2 | di |

Create focused PackageMetadataTest classes for Permission, Concurrency, DI, Passkeys, Process, and Telescope. Extend the existing Broadcasting, Contracts, and Notifications asserted requirement lists with their newly declared dependencies. Assert exact root parity, not merely key presence. Do not add an import scanner.

Durable findings: permission-09, permission-10, horizon-22, wayfinder-01, broadcasting-17, contracts-12, concurrency-08, di-06, notifications-21, passkeys-15, process-11, telescope-04.

### 8. Port only supported, unique current-upstream coverage

Merge the current Spatie tests whose branches remain meaningful in Hypervel:

- setup-teams disabled, declined, existing migration, and creation failure;
- create-role disabled/global warnings and team "0";
- Guard with no provider, LDAP provider, no Passport guard, and a guard without a client surface;
- cache reset when forget() fails;
- Model team ID;
- missing Role::findById();
- Permission ID checks and unsupported mixed assignment input being ignored;
- exact pipe-delimited role parsing;
- invalid wildcard implementation and blank comma subparts.

Source files:

- tests/Permission/Commands/CommandTest.php;
- tests/Permission/GuardTest.php;
- tests/Permission/Integration/CacheTest.php;
- tests/Permission/Integration/PermissionRegistrarTest.php;
- tests/Permission/Models/RoleTest.php;
- tests/Permission/Traits/HasAssignedModelsTest.php;
- tests/Permission/Traits/HasPermissionsTest.php;
- tests/Permission/Traits/HasRolesTest.php;
- tests/Permission/Traits/TeamHasPermissionsTest.php;
- tests/Permission/Traits/TeamHasRolesTest.php;
- tests/Permission/Traits/WildcardHasPermissionsTest.php.

Do not port the unknown-store array-cache fallback, obsolete retry behavior, Laravel Octane reset wiring, or redundant reflection tests. Do not add production seams solely for tests.

Durable finding: permission-12.

### 9. Complete Laravel-style Permission documentation

Update src/boost/docs/permission.md in the surrounding Laravel-docs prose:

- correct the revoke example to pass an array;
- define a Permission as one ability and a Role as a named permission group;
- describe replacement events, including the pre-operation detached collection, requested attached IDs, same-set behavior, listener gating, and failure ordering;
- show the trait-alias plus using(CustomPivot::class) extension pattern;
- explain that custom-pivot model-returning APIs load the real relation while authorization and permission-name checks retain the compact cache;
- state that reverse arbitrary-model operations do not use a subject's relation override;
- update the performance section with the exact listener-gated replacement read.

Keep implementation internals out of user documentation. Do not claim scoped removals bypass pivot hooks after Database restores native pivot event behavior.

Durable finding: permission-11.

### 10. Update durable audit records without declaring a full audit

Update the core routing lines to this Permission work unit and the exact carried dependencies. Add one compact ledger section with:

- permission-06 through permission-18;
- database-29 through database-33 and Database revalidation;
- provider/dependency sibling IDs and completed revalidation;
- support-02 revalidation;
- final API/performance result;
- important rejected concerns.

Keep Permission unchecked in the core package checklist because this work begins from the completed external finding report rather than a fresh source-wide audit. Do not rewrite historical permission-01 through permission-05.

## Rejected designs and non-findings

- No relation registry, custom-pivot attribute cache, cache-invalidation observer, per-request model map, static pivot memo, subscriber entry, or new CoroutineContext slot.
- No partitioned-relation interface; one shared helper owns the only static-analysis narrowing the existing two concrete relation classes need.
- No ordinary authorization query, custom pivot lookup, event construction, or model hydration.
- No cached/public role-edge Pivot alias, relation construction per cached edge, generic pivot builder, or manufactured mid-coroutine permission-class reconfiguration path.
- No using(Pivot::class); stock pivots retain set-based operations.
- No Permission-local raw-query safety path after Database owns predicate retention.
- No delta-only detached event and no ID-only event payload.
- No custom pivot handling for reverse arbitrary-model methods.
- No redesign of Laravel's public relationship orWhere semantics.
- No generic predicate object or lazy query-builder reconstruction.
- No array-cache fallback, retry compatibility path, Octane reset hook, optional-provider DefaultProviders entry, metadata import scanner, or temporary error handler.
- Existing worker/coroutine ownership remains correct: permission catalogs are worker-cached, request assignment caches and relation provenance are coroutine-local, registrar configuration mutators remain boot/test-only, and no native resource lifecycle exists in Permission.

## Test plan

Run each changed test file immediately after its coherent source slice.

### Database relation safety

1. Run BelongsToManyPivotEventsTest and MorphToManyPivotEventsTest before and after the shared fix; preserve per-row deleting/deleted and saving/saved order.
2. Add cross-parent regressions proving grouped wherePivot + orWherePivot cannot escape parent identity.
3. Prove stock detach/update obey wherePivotBetween and its shared or/not funnel.
4. Prove stock and custom hydrated pivot save/delete retain team/partition/value/range scopes.
5. Prove constrained custom detach/update preserves sibling rows and still fires native pivot events.
6. Prove custom attach/sync and updateExistingPivot() force-fill explicit guarded attributes while preserving casts and model events; include one strict-mode regression covering attach and update.
7. Prove an id-bearing constrained pivot retains primary-key save/delete behavior, including an intentional constrained-column change.
8. Prove the MorphToMany guarded-attribute path changes only the intended morph-scoped row and leaves a sibling morph type untouched.
9. Prove unconstrained stock/custom behavior is unchanged.

### Permission behavior

Run targeted PHPStan for src/permission after the first custom-relation/context slice, before proceeding to later Permission changes. This catches accidental promotion of the helper's inline union narrowing to a parameter contract.

1. Replacement events: saved subject and Role; team and partition scopes; same-set, empty, retained, allowed/denied updates; disabled/no listener; transaction failure; unsaved attached-only; detached-before-attached ordering and fresh post-invalidation reads.
2. Custom pivots: immediate and deferred permission/role assignment; casts, timestamps, events, detaching, guarded denied-effect updates, captured relation context, pivot-class batch identity, retained rows across role replacement, and stock bulk-query retention.
3. Cache contract: custom getDirectPermissions()/getAllPermissions() returns real pivot; the second call and later authorization issue no extra query; getPermissionNames() stays warm zero-query.
4. Stock warm pivot: correct class, keys, morph metadata, warm/relation delete-query SQL and binding parity under non-null team, global null team, partition-only, and combined scopes, plus save/delete without crossing sibling rows.
5. Role-permission pivots: catalog Permission::roles and public via-role Role::permissions orientations match live relation delete SQL/bindings in partitioned and non-partitioned configurations; public key accessors work; mutating the fresh public pivot leaves the catalog edge and hasDeniedPermissionViaRoles() unchanged; scoped save/delete cannot cross partitions.
6. CLI/setup: team "0", disabled/global warnings, prior context restoration, publication success/failure status.
7. Queued helper cleanup: existing unsaved assignment/replacement/removal suites remain green.
8. Port the bounded current-upstream cases listed above.
9. Prove same-set permission and effect replacement preserves the warm Role permission catalog while still dispatching the documented events.

### Metadata, documentation, and records

1. Run Composer PackageManifestConsistencyTest and every changed PackageMetadataTest.
2. Run PermissionServiceProvider/Command tests with the installed direct dependencies.
3. Validate root and every changed split manifest with composer validate --strict --no-check-publish.
4. Search for stale class_exists/skip guards, old queued boolean contracts, raw Pivot warm hydration, duplicate MorphToMany post-mapping, and outdated documentation claims.
5. Check plan, ledger, routing, finding IDs, and package checklist consistency.

### Gates and review

1. Run the complete Database relation and Permission suites.
2. Run composer fix once at the implementation checkpoint.
3. Perform a fresh caller/callee, transaction/event ordering, public/protected API, partition/team context, coroutine state, hot-path, retained-memory, and overengineering review.
4. Apply review corrections, rerun affected focused tests, and repeat the complete gate only if changes warrant it.

## Expected final result

- Laravel/Spatie-facing Permission APIs, named arguments, relation overrides, pivot events, command options, and event payloads remain compatible or are restored.
- Hypervel-specific denied permissions, partitioning, immutable relation contexts, compact caching, and transactional synchronization remain intact.
- Warm authorization and permission-name checks gain no query, allocation loop, lock, yield, serialization, or container lookup.
- Custom-pivot model-returning APIs pay one necessary relation query per model/coroutine, then reuse the loaded relation; custom writes honor every explicit attribute and use native per-row casts and hooks only where requested.
- Warm role-permission pivots are structurally complete; model-returning via-role APIs and the memoized wildcard-index build create one fresh correctly oriented Pivot per returned edge so caller mutation cannot alter cached authorization state.
- No-op permission and role replacements preserve warm caches and retained custom-pivot rows while keeping the documented replacement-event payloads.
- The saved replacement event path adds one hydration query only when its targeted detached listener is active.
- Database adds bounded in-memory predicate descriptor retention only to constrained pivot instances; unconstrained explicit pivot writes add one predictable null guard, outside ordinary authorization and relationship reads.
- No stale helper, duplicate hydration path, workaround, compatibility shim, speculative abstraction, unresolved accepted defect, or TODO remains.
