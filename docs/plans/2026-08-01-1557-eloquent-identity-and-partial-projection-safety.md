# Eloquent Identity and Partial-Projection Safety

## Outcome

Make existing Eloquent identity and state-dependent APIs fail clearly when a persisted model was loaded without the columns required to perform the requested operation. Correct the same fail-open behavior in Permission, Fortify, Pagination, Queue, Scout, Notifications, JSON:API, and Testing consumers without enabling global strict mode, issuing recovery queries, or narrowing supported Laravel APIs.

The final design preserves Laravel-compatible names, signatures, extension points, ordinary casts/accessors, numeric/string identifiers, UUIDs, compound pivot identifiers, unsaved-model behavior, and intentional nullable inputs. The only behavioral divergences are corrections to accidental behavior that currently conflates an absent identity or state column with a real `null` value. `SoftDeletes::trashed()` specifically requires its real backing column because the write path and global scope already do; an accessor without that column cannot represent valid soft-delete persistence.

## Constraints

- Keep Laravel public and protected APIs compatible. Additive APIs are allowed; no contract method is removed or narrowed.
- Treat a missing loaded attribute differently from a loaded SQL `NULL`. Use `MissingAttributeException` for absent Eloquent attributes and the existing boundary-specific exception type elsewhere.
- Validate at the latest common boundary that owns the invariant. Do not globally change `getAttribute()`, `getAttributeValue()`, `offsetExists()`, strict-mode defaults, or query selection.
- Preserve unsaved and just-created model flexibility where nullable attributes may legitimately be absent.
- Preserve subclass control: a protected `setKeysFor*Query()` override may still replace the base identity strategy.
- Add no automatic reload, extra query, relation load, registry, synthetic object identity, cache, lock, context state, retry, compatibility branch, or new exception class.
- Add no second collection traversal where validation can occur inside an existing map.
- Keep hot paths allocation-light and I/O-free. Permission cache configuration validation runs only during registrar initialization.
- Do not modify source merely to satisfy PHPStan; declarations must describe runtime behavior.
- Do not retain superseded comments, duplicated tests, or dead helpers. Comments should explain only non-obvious invariants.

## References inspected

- Hypervel source, tests, documentation, subclasses, and callers for every method named below, including strict-mode and Sentry violation handling.
- Local current Laravel framework `9f27fa054a`: Eloquent Model/Collection/relations/pivots/soft deletes, cursor pagination, model serialization, database notifications, JSON resources, and their tests. The originating `Model::is()` history confirms stored-row rather than PHP object identity. JSON:API PRs #59418 and #59813, their linked issues, complete changed-file sets, and current source define the request fixes below.
- Local Laravel Fortify `f4f5b81` and Scout `1770ba2`, including their corresponding predicates, indexing keys, and tests.
- Local Spatie Permission `afd2401` plus Hypervel's teams, denied-permission, partition, cache, and coroutine extensions. Hypervel-specific behavior remains authoritative where it deliberately diverges.
- Hypervel's public Pagination documentation, which already states that nullable ordered columns are unsupported by cursor pagination.

## Research and decisions

### Eloquent's existing strictness is not the owning solution

`Model::preventAccessingMissingAttributes()` is present and Sentry can report its violations, but Eloquent intentionally disables that guard for unsaved and just-created models. Several affected paths also bypass normal attribute access through `getKey()`, `isset`, null coalescing, `array_column()`, raw attributes, or collection dictionaries. Turning strict mode on globally would change unrelated Eloquent magic and still leave holes. Each accepted correction therefore sits at a boundary that cannot produce a truthful result without the missing value.

### Identity means stored-row identity

Laravel introduced `Model::is()` to compare database identity: key, table, and connection. Its current `null === null` result for two keyless models is incidental, not object-identity semantics; object identity remains PHP's `===`. Hypervel will require the local model key to be non-null before comparing the existing three identity components. A keyless model is therefore not `is()`-equal even to itself.

Eloquent `duplicates()` has a separate dictionary contract: its inherited algorithm first calls no-key `unique()`, which collapses keyless models into one entry, then consumes that entry through `duplicateComparator()`. Correcting `is()` alone prevents the representative from advancing and can misreport later keyed items. The local comparator will therefore retain Laravel's existing keyless equivalence for models with the same table and connection. This exactly preserves current duplicate results while keeping stored-row and PHP object identity truthful elsewhere.

The same rule applies to relationship comparison and scalar collection membership. `0` and `'0'` remain valid keys. Collection scalar membership retains Laravel's existing loose non-null key compatibility, including numeric strings; only a null model key is excluded from matching. Model membership accepts the exact object before applying stored-row identity, so a working set can still find the unsaved instance it already contains without conflating distinct keyless models.

### Persisted partial models must not target `WHERE key IS NULL`

Base Eloquent update, increment/decrement, delete, force-delete, `fresh()`, and `refresh()` paths eventually call `setKeysForSaveQuery()` or `setKeysForSelectQuery()`. Resolving the original-or-current key inside those methods and rejecting only `null` protects the common boundary while retaining event ordering, dirty-free no-op saves, original-key precedence, and protected override behavior.

Pivots without a standalone primary key use two compound columns instead. `AsPivot` needs one protected resolver shared by select/save and delete queries. It must keep this precedence exactly:

```php
$this->getOriginal($column, $this->getAttribute($column))
```

Either compound value being null is invalid; zero, string zero, UUIDs, accessors, casts, and original values remain valid. `MorphPivot` and primary-key pivots retain their existing override/parent paths.

### Missing state is checked only when null has domain meaning

`SoftDeletes::trashed()`, Fortify's two-factor state, and `DatabaseNotification::read_at` currently convert an omitted column into the same result as a loaded null column. Their local guards apply only to persisted, not-just-created models. Fresh model instances continue to treat omitted nullable state as its normal default.

Permission Role and Permission hydration is different: constructors seed `guard_name`, then `newFromBuilder()` replaces raw attributes with the selected database projection. A persisted instance missing `guard_name` is therefore always partial. New public `guardName(): string` methods on the concrete Role and Permission models will own this check and retain `Guard::getDefaultName(static::class)` only for an unsaved model lacking the attribute. Permission contracts remain unchanged so custom implementations are not broken.

### Consumer boundaries fail before side effects or fail-open predicates

Caller-supplied Eloquent models in Permission scopes and mutations must have an identity before SQL or pivot mutation is built. This is especially important for negative scopes: silently dropping a keyless Role or Permission can turn a requested exclusion into a match-all query. Validation occurs per supplied model, including mixed arrays, before `whereIn`, attach, detach, sync, context publication, cache invalidation, or reverse-assignment deletion.

Explicit scalar `null` remains meaningful only for `setPermissionsTeamId(null)`, where it clears team context. A Model argument with a null key is invalid.

Queue serialization, Scout indexing, cursor generation, and JSON:API resource identification likewise cannot restore, index, paginate, or identify a model without a usable key. They will reject the invalid value at their existing publication boundary rather than emit a corrupt payload.

### Deliberate exclusions

- Keep Eloquent Collection `merge`, `diff`, `intersect`, keyless `unique`, `duplicates`, and `modelKeys()` dictionary semantics unchanged. Making keyless models unique would require synthetic identity that cannot consistently represent unsaved models, partial persisted models, and compound pivots.
- Do not make every Permission read predicate throw. Predicates that can safely fail closed remain tolerant; only SQL/mutation/context boundaries that can broaden results or corrupt state validate.
- Do not validate arbitrary Scout documents or custom `getScoutKey()` overrides. Only the default Searchable implementation checks the ordinary Eloquent key of an existing model.
- Do not broaden Fortify eligibility beyond models using `TwoFactorAuthenticatable`.
- Do not add user-facing strictness configuration or partial-select documentation. Correct callers work unchanged; invalid projections now report the existing framework error.

## Findings

| ID | Owner | Failure | Decision |
|---|---|---|---|
| `database-15` | Database | Two keyless models compare as the same stored row | Require a non-null local key in `Model::is()` while retaining key/table/connection comparison |
| `database-16` | Database | Relationship key comparison rejects valid zero identifiers | Reject only null before retaining the existing integer/string normalization |
| `database-17` | Database | Persisted partial models can emit save/select predicates with a null primary key | Reject null at the shared protected query-key boundaries |
| `database-18` | Database | Compound pivots can target or publish an identity with either key column omitted | Resolve both compound columns through one null-rejecting original-or-current owner for queries and queue IDs |
| `database-19` | Database | Scalar collection membership loosely matches a null model key to zero or the empty string | Exclude null model keys while preserving Laravel's other loose scalar-key comparisons |
| `database-20` | Database | `trashed()` treats an omitted persisted deleted-at column as a loaded null | Require the real backing column before normal cast/accessor retrieval |
| `permission-01` | Permission | Cache exclusions can remove fields required to rehydrate built-in Role or Permission models | Validate the configured exclusions once during registrar initialization |
| `permission-02` | Permission | A persisted Role or Permission missing `guard_name` silently selects a fallback guard | Add concrete `guardName()` owners that distinguish partial persisted models from new models |
| `permission-03` | Permission | Keyless Role, Permission, or Team models can disappear from positive or negative scopes | Validate every model input, including mixed arrays, before building SQL |
| `permission-04` | Permission | A keyless Team model silently clears the current team context | Reject a null model key while preserving explicit scalar null as the clear operation |
| `permission-05` | Permission | Assignment, removal, and deletion APIs can query, mutate, invalidate, or report success with null owner or target model IDs | Validate target and owner identities at the existing mutation and cleanup boundaries before side effects |
| `fortify-02` | Fortify | A custom authentication callback may return a partial user whose omitted two-factor columns silently bypass the challenge | Make the canonical trait predicate validate required state and route challenge decisions through it |
| `pagination-01` | Pagination | Cursor extraction treats absent or null order values as usable cursor parameters | Reject null after resolving each supported item shape |
| `pagination-02` | Pagination | The pivot cursor helper's `?string` return type rejects valid integer keys | Make the protected helper's return type truthful |
| `queue-41` | Database / Queue | A null queueable model ID publishes a `ModelIdentifier` that cannot restore the model | Reject null once inside the existing single-model or collection traversal |
| `scout-01` | Scout | Default indexing can publish an existing model under a null key | Reject a missing default key for existing models; retain custom key overrides and unsaved introspection |
| `scout-02` | Scout | `RemoveableScoutCollection` diverges from upstream by replacing custom queueable IDs with primary keys in its fallback | Restore delegation to the parent collection implementation |
| `notifications-08` | Notifications | An omitted `read_at` column is treated as unread and may be written from an incomplete notification | Require loaded read state for persisted notification predicates and mutations |
| `http-04` | HTTP JSON:API | A missing resource key is cast to the empty-string identifier | Reuse `ResourceIdentificationException` when fallback key resolution returns null |
| `http-05` | HTTP JSON:API | Resolving a top-level included relationship passes the parser's null sentinel to `explode()` and fatally fails under strict types | Port Laravel's current non-string guard before parsing nested paths |
| `http-06` | HTTP JSON:API | An explicitly empty sparse fieldset returns every attribute and the request lacks Laravel's fieldset-presence API | Port `hasSparseFieldset()` and filter whenever the fieldset was provided |
| `testing-01` | Testing | View assertions conflate exact unsaved-object identity with stored-row identity | Accept the exact object or a model with the same stored identity at all four assertion sites |
| `testing-02` | Testing | Invalid model view data escapes assertion handling as a type error or null method call | Validate actual models inside the existing PHPUnit assertions and correct the collection path message |

## Implementation

### 1. Database identity and persistence

Update:

- `src/database/src/Eloquent/Model.php`
- `src/database/src/Eloquent/Relations/Concerns/ComparesRelatedModels.php`
- `src/database/src/Eloquent/Relations/Concerns/AsPivot.php`
- `src/database/src/Eloquent/SoftDeletes.php`
- `src/database/src/Eloquent/Collection.php`

`Model::is()` will preserve its current null-argument short circuit, then reject a null local key before retaining strict key/table/connection comparison:

```php
if ($model === null) {
    return false;
}

$key = $this->getKey();

return $key !== null
    && $key === $model->getKey()
    && $this->getTable() === $model->getTable()
    && $this->getConnectionName() === $model->getConnectionName();
```

Both base `setKeysFor*Query()` methods will resolve their current original-or-live value once, throw `MissingAttributeException($this, $this->getKeyName())` when it is null, then add the existing predicate. Do not move the check into `save()`, `delete()`, or the key getter.

Add a protected `getPivotKeyForQuery(string $column): mixed` to `AsPivot`. It will preserve original-value precedence and throw `MissingAttributeException` for a null compound column. Use it in both compound select/save predicates, `getDeleteQuery()`, and the compound branch of `getQueueableId()`; use the same inherited resolver only for the foreign- and related-key attribute values in `MorphPivot::getQueueableId()`. Its relation-supplied morph-type and morph-class properties remain literal components and must not pass through the attribute resolver. Keep standalone primary-key branches delegated to the parent. This prevents partial pivots from publishing non-null but corrupt compound strings such as `user_id::role_id:`.

Change `ComparesRelatedModels::compareKeys()` from `empty()` rejection to null rejection and retain its current integer/string normalization. For scalar `Collection::contains()`, add only a non-null model-key guard around its existing loose comparison:

```php
$modelKey !== null && $modelKey == $key
```

This deliberate local loose comparison preserves Laravel's established scalar-key behavior, including numeric strings such as `'05'`; replacing it with a stricter comparison would exceed the null-identity correction.

Keep a one-line source comment explaining that the loose comparison is intentional Laravel key compatibility. Without it, the expression appears to violate the repository's strict-comparison rule and invites a later behavioral regression.

Model membership inherits corrected `Model::is()` behavior. Duplicate detection retains its current dictionary contract locally:

```php
// unique() collapses keyless models into a single dictionary entry, so the
// comparator must agree with it or an unsaved model can misreport later keyed items.
return fn ($a, $b) => $a->is($b)
    || ($a->getKey() === null
        && $b->getKey() === null
        && $a->getTable() === $b->getTable()
        && $a->getConnectionName() === $b->getConnectionName());
```

This adds one key read after a failed identity comparison and no extra traversal or I/O. It exactly restores current Laravel results for keyed, keyless, mixed, repeated-object, cross-table, and cross-connection collections.

`SoftDeletes::trashed()` will inspect the configured raw deleted-at column directly before normal cast/accessor retrieval, avoiding an unnecessary cached-class-cast merge in this predicate. Throw only for `exists && ! wasRecentlyCreated` with an absent raw column. This intentionally does not support an accessor-only deleted-at value with no real column: soft-delete writes and the global scope already require the backing column.

### 2. Permission cache and guard state

Update:

- `src/permission/src/PermissionRegistrar.php`
- `src/permission/src/Models/Role.php`
- `src/permission/src/Models/Permission.php`
- `src/permission/src/Guard.php` only if its docs/types need alignment after routing concrete models through `guardName()`

During `initializeCache()`, after configured model classes and keys are known, reject `permission.cache.column_names_except` entries that remove fields needed to rehydrate cached records:

- Role: configured model primary key, `name`, `guard_name`, team foreign key when teams are enabled, and partition column when configured.
- Permission: configured model primary key, `name`, `guard_name`, and partition column when configured.

Use each configured model's actual key name. Read `PermissionRegistrar::partitionColumn()` directly; do not invoke the runtime partition resolver during boot validation. Report the offending model kind and columns in one `InvalidArgumentException`.

Add the same concrete method to Role and Permission:

```php
public function guardName(): string
{
    if (! array_key_exists('guard_name', $this->getAttributes())) {
        if ($this->exists) {
            throw new MissingAttributeException($this, 'guard_name');
        }

        return Guard::getDefaultName(static::class);
    }

    /** @var string $guardName */
    $guardName = $this->getAttribute('guard_name');

    return $guardName;
}
```

Route `users()` and `Guard::getNames()` through this method for the built-in models. Normal attribute access after the raw check preserves casts/accessors. Do not add the method to Role or Permission contracts and do not change Guard's tolerant fallback for ordinary user models.

Keep the small method duplicated on the two concrete models. Moving it into `HasPermissions` would also add it to ordinary user models through `HasRoles`, causing `Guard::getNames()` to select a strict `guard_name` path where that attribute is normally absent.

### 3. Permission caller-supplied model identities

Update:

- `src/permission/src/DefaultTeamResolver.php`
- `src/permission/src/Traits/HasRoles.php`
- `src/permission/src/Traits/HasPermissions.php`
- `src/permission/src/Traits/HasAssignedModels.php`

Validate model-derived IDs before use in:

- `DefaultTeamResolver::setPermissionsTeamId(Model)`;
- `scopeRole`, `scopePermission`, and `scopeTeam`, including mixed arrays and negative scopes;
- `collectRoles()` and `collectPermissions()` after resolving stored models;
- `groupModelsByMorphClass()`;
- reverse Role assignment query construction in `newPivotQueryForRole()`.

For persisted assignment subjects, validate the owning model key after the unsaved queue/no-op branch and before relation reads, transactions, pivot mutation, cache invalidation, or events. Cover the shared bodies behind `assignRole`, `removeRole`, `syncRoles`, `givePermissionTo`, `denyPermissionTo`, `syncPermissions`, `syncPermissionEffects`, and `revokePermissionTo`. Validate a reverse-assignment Role once in `assignedModelRelationContext()`, which is used only by the three persisted `assignToModels`, `removeFromModels`, and `syncModels` paths. Keep `HasAssignedModels`' key resolver self-contained rather than creating an implicit private-method dependency on a sibling trait.

The deletion listeners run before base Eloquent reaches its save-query key guard. Resolve and validate the model key at the start of both assignment cleanup helpers, before partition checks, transactions, or SQL, and reuse it for every cleanup predicate. Missing identity intentionally takes precedence over a partition mismatch because the record cannot be identified. Ordinary soft deletes continue to skip assignment cleanup and reach Eloquent's canonical key guard.

Keep explicit cache invalidation consistent with tolerant read-cache key construction: `forgetModelRoleCacheFor()` and `forgetModelPermissionCacheFor()` cast the model key to string, matching their sibling readers and combined invalidator. This lets callers clear the same fail-closed empty-key entry a tolerant read may create; it is not a substitute for mutation and deletion guards.

Use one private `requireModelKey()` resolver in `HasPermissions`, which `HasRoles` already composes, rather than duplicating the same validator across both traits. Keep the separately composable `HasAssignedModels` trait self-contained with `requireAssignedModelKey()`. Otherwise keep one-off checks at their call sites. Throw `MissingAttributeException` naming the model's actual key. Preserve raw integer/string IDs, explicit team-context null, intentional unsaved subject-model assignment queuing in `HasRoles` and `HasPermissions`, the reverse Role API's existing unsaved-owner no-op, partition checks, public legacy scope names, and the currently reachable array handling in stored-permission resolution.

The protected stored-model resolvers retain their Laravel-compatible native signatures for userland overrides, while their PHPDocs describe the existing Eloquent-model requirement precisely: `getStoredRole()` returns `Model&Role`, and `getStoredPermission()` returns `Collection|(Model&Permission)`. Do not retain the inherited `Permission[]` return shape; the array input branch returns an Eloquent collection and no branch returns an array.

### 4. Fortify two-factor state

Update:

- `src/fortify/src/TwoFactorAuthenticatable.php`
- `src/fortify/src/Actions/RedirectIfTwoFactorAuthenticatable.php`
- `src/fortify/src/InteractsWithTwoFactorState.php` only if the final call trace needs a direct canonical guard before its helper reads

The trait's `hasEnabledTwoFactorAuthentication()` is the canonical owner. Before normal property access, require raw `two_factor_secret` for a persisted, not-just-created model and additionally require raw `two_factor_confirmed_at` when confirmation is configured. `RedirectIfTwoFactorAuthenticatable` will first retain its existing trait eligibility test, then call the canonical predicate instead of independently reading both attributes. Do not broaden eligibility to arbitrary contract implementations.

`InteractsWithTwoFactorState::ensureStateIsValid()` already invokes the canonical predicate before its confirmation-only helper reads; retain that ordering and remove no transition behavior.

### 5. Pagination cursor parameters

Update `src/pagination/src/AbstractCursorPaginator.php`.

After resolving aliases, array/`ArrayAccess` values, object properties, or pivot values, reject null with the method's existing `Exception` convention. Preserve the existing behavior of every non-null result, including `0`, `'0'`, `''`, and stringable values. Change `getPivotParameterForItem()` from `?string` to `mixed`: the value comes from `getAttribute()` through the `mixed` and pass-through `ensureParameterIsPrimitive()` boundary, so no truthful finite union exists; integer cursor keys are valid and current Laravel leaves this protected result untyped despite its stale string-oriented docblock.

Keep all public and protected names and arguments unchanged. Do not change `ArrayAccess`, Model offset semantics, Cursor encoding, or add a missing-value wrapper.

### 6. Queue model identifiers

Update:

- `src/database/src/Eloquent/Collection.php`
- `src/queue/src/SerializesAndRestoresModelIdentifiers.php`

For an Eloquent collection, replace the higher-order queue-ID map with an explicit map that resolves each `getQueueableId()` once, throws `LogicException` on null, and returns the ID. This is the existing traversal, not a validation pass. In the single-model branch, resolve once and perform the same check before constructing `ModelIdentifier`.

Retain custom non-null queue IDs, morph aliases, relation metadata, valid Pivot/MorphPivot compound IDs, collection ordering, non-Eloquent serialization, and restoration behavior. Do not change `Model::getQueueableId()` globally because it remains a valid introspection/customization method.

### 7. Scout default keys

Update:

- `src/scout/src/Searchable.php`
- `src/scout/src/Jobs/RemoveableScoutCollection.php`

The default `getScoutKey()` will resolve the Eloquent key once and throw `LogicException` only when the model exists and the key is null. Unsaved model inspection and custom method overrides remain untouched.

`RemoveableScoutCollection`'s Searchable branch inherits that check. Restore its non-Searchable fallback to Laravel Scout's `parent::getQueueableIds()` delegation and delete the false primary-key-equivalence comment. The branch is not reachable through Scout's supported construction and handling path, so do not add a separate projection guard or abstraction there; parent delegation nevertheless preserves custom queueable IDs and automatically inherits Database's existing-traversal validation if the collection is used directly.

### 8. Notification read state

Update `src/notifications/src/DatabaseNotification.php`. Add one private state resolver used by `markAsRead()`, `markAsUnread()`, `read()`, and `unread()`. It will require raw `read_at` only for persisted, not-just-created notifications, then return the normally cast attribute. Query scopes remain unchanged.

### 9. JSON:API identifiers and request semantics

Update:

- `src/http/src/Resources/JsonApi/Concerns/ResolvesJsonApiElements.php`
- `src/http/src/Resources/JsonApi/JsonApiRequest.php`

Preserve a non-null custom `toId()` result. For a Model or other `getKey()` resource, resolve the key once and throw the existing `ResourceIdentificationException::attemptingToDetermineIdFor()` when null instead of returning `''`. Preserve zero/string-zero and non-Eloquent resources with valid keys.

Port Laravel's current `sparseIncluded()` guard so the null sentinel created for a top-level include is removed before nested-path parsing. Hypervel otherwise throws a fatal `TypeError`, rather than Laravel's historical deprecation, because the request file uses strict types. Keep the complete upstream non-string-or-empty condition for source parity; the non-string branch owns the reachable null failure.

Also port Laravel's `hasSparseFieldset()` and use fieldset presence, rather than a non-empty parsed value, to decide whether resource attributes are filtered. This distinguishes an omitted fieldset from `fields[type]=`, which correctly requests no attributes. Add no alternate parser or validation layer.

### 10. Testing model assertions

Update:

- `src/testing/src/TestResponse.php`
- `src/testing/src/TestView.php`
- `docs/todo.md`

At both Model and EloquentCollection branches, accept a value only when the actual item is a Model and is either the exact object or has the same stored identity. Keep `$value` and all other public names/signatures unchanged, normalize both branches to expected-first comparison, and preserve collection key-sensitive lookup. Correct the malformed collection assertion path while converting missing or non-model actual values into ordinary assertion failures.

Port the five current Laravel `TestResponseTest` cases that directly establish model/collection identity, order, type, and size behavior, then add focused keyless-identity and divergent-key diagnostics. Add proportionate `TestViewTest` coverage for the same changed branches. Use test-specific namespaces with inline local model fixtures; do not introduce a shared test abstraction.

Record the remaining Testing coverage gap under a concise `docs/todo.md` Testing entry: port the rest of current Laravel `TestResponseTest`, and cover TestView's remaining public assertion/string surface where Laravel has no equivalent suite. Do not record transient test counts.

Record a separate concise HTTP todo for a systematic update of the already-ported JSON:API surface and its missing current-Laravel test suite. Do not fold that broader, unreviewed parity update into these two verified request fixes.

### 11. Audit records

After implementation, update:

- `docs/plans/2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md`
- `docs/plans/2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md`

Add dependency-index rows for every finding ID. Amend the completed Database and Queue ledger entries because this work changes those packages after sign-off. Add one compact shared work-unit entry whose findings use the ledger's existing six-column ID/category/severity/confidence/failure/decision table, followed by rejected machinery, tests, and performance assessment. Leave incomplete package checkboxes open for their later full audits.

Update the main plan's three routing lines for this parallel work unit: `Active package or work unit`, `Ledger entries required for the active or completed parallel work`, and `Pending revalidation carried into the active work`. Preserve Reverb as the separately active audit and route each new owner/consumer obligation by its individual finding ID.

## Test plan

All regressions must explicitly run with `Model::preventAccessingMissingAttributes(false)` unless a companion assertion is proving strict-mode behavior. Each new negative test must have a complete-model or loaded-null control and must fail if its exact source guard is removed.

### Database

- `DatabaseEloquentModelTest`: `is()` rejects distinct and self keyless models; keyed equality/table/connection behavior remains; key `0` and `'0'` relation comparisons work.
- `DatabaseEloquentCollectionTest`: model membership accepts the exact keyless object but not a distinct keyless model; duplicates deliberately retain current dictionary semantics for three keyless models, do not let a leading keyless model poison a later keyed item, and preserve keyless table/connection distinctions; scalar null-key membership rejects `0`, `'0'`, and `''`; keyed int/string, leading-zero numeric-string, and numeric-float-string controls preserve existing loose compatibility.
- `DatabaseEloquentCollectionQueueableTest`: complete IDs and valid compound pivot IDs pass; any keyless collection member throws before publication.
- `DatabaseEloquentPivotTest` and/or existing pivot integration coverage: update, delete, and queue-ID publication reject either missing compound key; preserve original-value targeting, integer/UUID controls, and standalone-primary-key pivots across Pivot and MorphPivot.
- `EloquentModelRefreshTest` plus focused model mutation tests: partial persisted `fresh()`, `refresh()`, dirty update, increment/decrement, increment/decrement-each, delete, force delete, and soft delete throw; complete operations still target the correct row; dirty-free `save()` and vetoed events retain their prior behavior.
- Soft-delete tests: loaded null/loaded timestamp and fresh instance behavior remain correct; only a persisted projection missing the configured deleted-at column throws.
- Correct the dead strict-cast assertion in `DatabaseEloquentWithCastsTest` so its exception expectation is the assertion.

### Permission

- Registrar initialization accepts the default exclusions and custom primary keys; rejects each required Role/Permission field, including conditional team and partition columns, without calling a partition resolver.
- Role/Permission `guardName()` honors constructor defaults, loaded values, casts/accessors, and string zero; persisted missing state throws and `users()`/`Guard::getNames()` use the method.
- Role, Permission, Team, assigned-model, reverse-role, collect, attach/detach/sync, and context APIs cover keyless target and owning Model input, mixed complete/keyless input, and valid scalar/model controls. Cover all eleven public persisted mutation entry points, both allowed and denied permission branches, and prove failed revocations leave assignments intact.
- Partial subject, Role, and Permission deletion throws before assignment cleanup SQL and leaves every affected pivot table intact. A partial soft-deleting subject proves ordinary soft deletion still bypasses assignment cleanup and reaches Eloquent's key guard. A clean partial save remains valid, pinning the persisted-branch placement rather than a shared read/context guard.
- Direct role and permission cache invalidation clears the same fail-closed keyless entry written by tolerant assignment reads.
- Positive and negative Role/Permission scopes cover both wholly keyless and mixed complete/keyless inputs so `array_column()` cannot silently narrow a requested filter. `scopeTeam` has its own single and mixed cases because it builds different SQL.
- Existing partition, team, guard, wildcard, denied-permission, custom-model, cache, and coroutine-isolation suites remain green.

### Fortify

- With strict mode off, a persisted user missing the secret cannot bypass a challenge decision.
- When confirmation is required, missing confirmed-at state throws; when it is not required, that column is not required.
- Loaded null, enabled, unsaved, and just-created controls retain existing results.
- Redirect and `InteractsWithTwoFactorState` prove delegation to the canonical trait predicate without broadening eligible user types.

### Pagination

- Null/missing cursor parameters throw for arrays, ArrayAccess, objects, aliases, models, and pivots.
- Integer pivot parameters return normally, proving the corrected protected type.
- Zero, string zero, empty string, stringable value objects, composite order parameters, forward cursors, and reverse cursors remain valid.

### Queue, Scout, Notifications, and JSON:API

- Queue: single model, mixed collection, custom non-null queue ID, Pivot/MorphPivot ID, and valid restore controls. The Notifications consumer fixture uses a non-null model identity and still proves that a Model notifiable serializes through the collection-shaped identifier path.
- Scout: existing partial default-key model throws before engine publication; complete, unsaved, custom-key, and soft-delete metadata paths remain valid; a focused removal-collection fallback regression proves parent delegation preserves a custom queueable ID rather than substituting the primary key.
- Notifications: all four instance methods reject persisted missing `read_at`; loaded null/timestamp and fresh/just-created controls remain; mutation tests prove no save occurs before rejection.
- JSON:API: null fallback key throws for root and relationship resources; custom `toId()`, valid model key, generic `getKey()` object, `0`, and `'0'` remain valid. A request-owner test proves top-level includes return no nested paths while sibling nested includes remain intact. Request and resource tests distinguish an explicitly empty sparse fieldset from an omitted one and prove the empty fieldset emits identifier members without attributes.

### Testing

- `TestResponseTest`: port the five relevant Laravel baselines; exact keyless models and collections pass, distinct keyless replacements fail, distinct instances with the same stored identity pass, and non-model values plus equal-size divergent-key collections fail through `AssertionFailedError` with the corrected path.
- `TestViewTest`: prove the same identity, stored-row, and invalid-data cases through the public TestView surface.

### Gates

Run focused tests immediately after each coherent package slice. At completion run `composer fix` once for formatting, both PHPStan configurations, and the parallel components, Testbench, and dogfood suites. Then inspect `git diff --check`, all changed manifests/docs, and the full diff from the branch base. Review callers and subclasses of every changed protected method before requesting final code review.

## Completion criteria

- Every finding above has source correction and counterfactual regression coverage.
- No supported Laravel method name, signature, contract, protected extension point, cast/accessor path, or valid identifier form is broken.
- Missing state throws before SQL, cache publication, queue publication, indexing, cursor encoding, context mutation, or notification mutation.
- Fresh/unsaved models and intentional scalar null inputs retain documented flexibility.
- Testing assertions distinguish exact unsaved objects from stored-row identity and contain invalid view data as assertion failures.
- No production path gains I/O, a second collection pass, shared mutable state, or speculative abstraction.
- Audit index and ledger amendments accurately route later package revalidation.
- `composer fix` and final review are green, with no stale ignore, comment, helper, test, or documentation left behind.
