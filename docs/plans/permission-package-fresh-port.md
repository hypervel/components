# Permission Package — Fresh Port (spatie/laravel-permission v8 → Hypervel)

## 0. How to use this document

This is a complete, self-contained implementation plan. It assumes a fresh session with no prior context. Read it in full before starting. Every decision is made; there is nothing left to investigate or decide.

**Upstream reference:** `spatie/laravel-permission` **v8.0.0** (git `c2c871a`, "Creation no longer requires ->value"). Repo: `https://github.com/spatie/laravel-permission`. Clone it to a scratch dir if not present; this plan cites file paths relative to that clone as `spatie:src/...`.

**Working directory for all commands and edits:** `contrib/hypervel/components` (the Hypervel framework PR repo). Never edit outside it. Run all tooling (`phpunit`, `phpstan`, `php-cs-fixer`) from this directory.

**Starting state:** Before implementation begins, the existing `src/permission` package is moved to `_archive/permission` (kept as reference — it holds the original forbidden-permission feature and per-owner cache code), and `src/permission` is reset to a skeleton: `composer.json`, `README.md`, `LICENSE.md` only. Implementation builds the package from that skeleton.

**Authoritative conventions:** `contrib/hypervel/components/AGENTS.md` and the monorepo root `CLAUDE.md`. This plan follows them; where it makes a deliberate divergence from spatie, it says so and records it (README "Differences From Spatie" + source comment), per the AGENTS.md "Record intentional Laravel differences" rule.

---

## 1. Background

### 1.1 What the package is

`hypervel/permission` is role-based access control for Eloquent models: create roles and permissions, assign them to users (or any model), and check access by role, direct permission, or permission inherited through a role. It is a port of `spatie/laravel-permission`.

### 1.2 Why a fresh port (not incremental)

The current package is a 0.3-era (Hyperf-based) rewrite that diverged structurally from spatie: only ~6 of spatie's ~41 source files have any equivalent, trait/method/table names differ (`HasRole` vs `HasRoles`, `owner_*` vs `model_*`, `PermissionManager` vs `PermissionRegistrar`, `hasPermission` vs `hasPermissionTo`), and large feature areas are missing (teams, wildcards, guard support, events, gate integration, blade, route macros, reverse relations, find/create helpers, five commands). The existing code also carries real bugs (half-implemented custom primary keys, missing cache invalidation, `name`-only unique index, no pivot foreign keys, restrictive `fillable`).

Bringing that to parity means hand-reconciling two different APIs and repairing buggy bespoke code with weak test coverage (~79 tests vs spatie's ~676). A fresh port copies spatie's correct, well-tested logic, re-applies the genuine Hypervel improvements deliberately, and restores upstream merge-ability (a core AGENTS.md goal: keep packages close to 1:1 so upstream changes merge easily). The package is pre-stable (0.4), so this is the cheapest time to realign.

### 1.3 What Hypervel provides (all verified present)

- `enum_value()` — `Hypervel\Support\enum_value` (`src/collections/src/functions.php`), handles backed (`->value`) **and** unit (`->name`) enums.
- `Gate::before(callable)` — `src/auth/src/Access/Gate.php:266`; `canAny` on `Hypervel\Foundation\Auth\Access\Authorizable`.
- `BladeCompiler::if()` / `::directive()` — `src/view/src/Compilers/BladeCompiler.php:667/897`.
- `Hypervel\Routing\Route` and `Router` use `Macroable` — route macros + fluent `->role()` work.
- `morphedByMany` — `src/database/src/Eloquent/Concerns/HasRelationships.php:781`.
- `__()` translation helper — `src/foundation/src/helpers.php:927`.
- `AboutCommand::add(string, callable|string|array, ?string)` — `src/foundation/src/Console/AboutCommand.php:261`.
- `event()` helper — `src/foundation/src/helpers.php`.
- `CoroutineContext::get/set` — `src/context/src/CoroutineContext.php` (`set(UnitEnum|string $id, mixed $value, ?int $coroutineId = null)`, `get(UnitEnum|string $id, mixed $default = null, ?int $coroutineId = null)`).
- Cache drivers: `StackStore` (write-through multi-tier with back-fill), `SwooleStore` (Swoole-table-backed, coroutine-safe), `MemoizedStore` (request-local memo), all config-selectable via `CacheManager::createStackDriver`/`createSwooleDriver`.
- ServiceProvider hooks: `mergeConfigFrom`, `loadMigrationsFrom`, `publishes`, `publishesMigrations`, `callAfterResolving`, `commands`.
- Eloquent boot hooks: `static::saved`, `static::deleting`, `static::deleted` (`src/database/src/Eloquent/Concerns/HasEvents.php`).

So **no spatie feature is blocked by the framework**. Every omission below is a deliberate choice, not a limitation.

---

## 2. Decisions (all final)

| # | Decision | Rationale |
|---|---|---|
| D1 | **Fresh port from spatie v8.0.0**, replacing the archived package. | §1.2. |
| D2 | **Adopt spatie naming and structure**: `HasRoles`/`HasPermissions` traits, `PermissionRegistrar`, `model_*` tables/columns, `model_morph_key`, spatie method names (`hasPermissionTo`, `hasAnyRole`, `getRoleNames`, …). Drop `owner_*`, singular traits, `PermissionManager`, the `Factory` contract. | Plural traits match the many-to-many cardinality and ecosystem norm; `model_*` is the de-facto standard for permission tables → upstream merge-ability + familiarity. The `owner_*`/singular renames were cosmetic 0.3 divergences with no functional benefit. |
| D3 | **Keep the forbidden-permission feature** (an `is_forbidden` pivot flag on `model_has_permissions` and `role_has_permissions`, with `giveForbiddenTo`, `hasForbiddenPermission`, `hasForbiddenPermissionViaRoles`, and the two-argument `syncPermissions($allow, $forbidden)`). A forbidden permission denies access even when granted directly or via a role. | Genuine Hypervel feature; reference impl in `_archive/permission`. Documented divergence. |
| D4 | **Keep per-subject assignment caching** (cache each subject's assigned roles and direct permissions in the cache store), layered on top of spatie's structure. | This is the real performance win over spatie, which re-queries a subject's assignments from the DB on every request. Documented divergence. |
| D5 | **Registrar holds no cached collection / no per-request state.** No `$permissions` in-memory collection, no `$isLoadingPermissions` lock, no `$wildcardPermissionsIndex`, no `config()->set()`/runtime-`bind()` setters, no Octane listener. Immutable config only. | Coroutine safety: a singleton's mutable per-request fields leak across concurrent coroutines on a long-lived Swoole worker. This is the central safety divergence. |
| D6 | **Store-agnostic caching.** All cache reads/writes go through the configured `permission.cache.store` repository. No bespoke tiering (no CoroutineContext two-tier cache). Tiering and request-local memo are deployment/config choices: recommend a `stack` store (`swoole` + `redis`) and/or memoization. | The framework already provides `StackStore`/`SwooleStore`/`MemoizedStore`. The package must not reinvent them. |
| D7 | **Port teams** (optional, default off). Team id stored in **`CoroutineContext`**, never on a singleton/static. `DefaultTeamResolver` reads/writes CoroutineContext. Teams columns live in a separate dormant migration that only runs when `permission.teams` is `true`. | Teams is a permission-*scoping* mechanism (adds `team_id` to roles + assignment pivots), not a teams-management domain — spatie ships no Team model or teams table; `models.team` is the app's own model. It belongs in the permission package. The team id is the textbook per-request value → must be coroutine-scoped. |
| D8 | **Port wildcard permissions.** The wildcard index is computed on demand from the subject's (cached) permissions; it is **not** held on the registrar. | Faithful feature; avoids the singleton `$wildcardPermissionsIndex` coroutine hazard. |
| D9 | **Port Passport client-credentials support now** (config `use_passport_client_credentials`, `Guard::getPassportClient`, middleware bearer-token block). | Passport is on the roadmap. The hook has no hard Passport class dependency (duck-types `client()` via `method_exists`), uses only `Auth`/`Request`/`Authorizable` (all present), is double-gated (config default-false + bearer token), and degrades to inert when no `passport`-driver guard exists. Classify as pending-dependency, not omission. |
| D10 | **Port gate integration** (`register_permission_check_method`, `registerPermissions(Gate)`, `checkPermissionTo`), **blade directives**, **route macros**, **events**, **all commands**, **find/create helpers**, **`HasAssignedModels` reverse relations**, **`Support/Config`**, **`RefreshesPermissionCache`**, **`RoleOrPermissionMiddleware`**, **`Guard`**, **About integration**. | Full parity; all framework prerequisites verified present (§1.3). |
| D11 | **Base models use `protected $guarded = []`** (guard only the primary key, set in constructor), not a `fillable` whitelist. | Lets users add custom columns (`tenant_id`, json `data`, …) to extended models without overriding `$fillable`. Matches spatie. |
| D12 | **Models honor `table_names.*` and `storage.database.connection`**, and the migration is config-driven (reads `table_names`/`column_names`). | Fixes a current bug where the config knobs were advertised but ignored. |
| D13 | **Composite unique index `(name, guard_name)`** on `roles`/`permissions` (or `(team_foreign_key, name, guard_name)` under teams), and **foreign keys with `cascadeOnDelete`** on all pivots. | Fixes the current `name`-only unique index (which blocked same-name-different-guard) and the missing FKs (orphaned pivot rows). Matches spatie. |
| D14 | **Omit Octane reset listener.** | No Octane runtime in Hypervel, and the registrar holds no per-request state to reset (D5); team id auto-clears with the coroutine (D7). Documented. |
| D15 | **Skip spatie's cache alias-compression** (`getSerializedPermissionsForCache` field aliasing). | Complexity for marginal payload reduction; our role→permissions catalog is clearer. Documented simplification. |
| D16 | **No `Factory` contract; bind `PermissionRegistrar` as a singleton concrete** (`$this->app->singleton(PermissionRegistrar::class)`). Traits/middleware/models resolve the concrete. | Matches spatie; removes the current double-singleton (concrete auto-singletoned separately from the `Factory` binding). The registrar holds only immutable state, so a single shared instance is correct. |
| D17 | **Find/create helpers query the database directly** (Eloquent), not via a cached flat-permission catalog. | These run on write/admin paths (assignment, commands), not the hot read path; a direct query is simpler and correct. Hot reads use the per-subject + catalog caches. |
| D18 | **Use `CarbonImmutable`, `declare(strict_types=1)`, full type hints, `===`/`!==`, constructor promotion, enums** throughout, per CLAUDE.md. Method docblocks (title-only, Laravel-style) on all methods. | Repo standards. The archived package violated strict-comparison (`== true`) and lacked types in places — do not carry those over. |

---

## 3. Coroutine-safety & performance contract

These are invariants the implementation must hold. They are the reason the registrar's structure diverges from spatie internally.

1. **No per-request mutable state on the registrar (singleton) or in static properties.** The registrar's constructor reads immutable config (model classes, cache key, TTL, pivot/morph names, teams flag/key) once. Everything per-request (a subject's roles/permissions, the current team id, wildcard indexes) is either request-derived-and-recomputed or stored in the cache store / `CoroutineContext`.
2. **Team id lives in `CoroutineContext`** under key `__permission.team_id`. It auto-clears when the coroutine ends — no terminate listener needed.
3. **The cache store is the only persistence for derived data.** A resolved cache `Repository`/`Store` is a stateless wrapper over the connection pool and is safe to share. `StackStore`/`SwooleStore` are coroutine-safe (Swoole Table is a concurrent structure). Do not hold hydrated collections on the registrar.
4. **No `config()->set()` at runtime, no runtime container `bind()`.** Model classes are fixed at boot from config.
5. **Static caches, if any, expose `flushState()` and register with `AfterEachTestSubscriber`.** (This port introduces none — see §6.4 note on `Guard`.)
6. **Performance:** per-subject caching makes warm permission checks DB-free; pointing `permission.cache.store` at a `stack` store (swoole + redis) makes them network-free within a worker. No bespoke tiering in the package.

---

## 4. Target package structure

```
src/permission/
├── composer.json                 # add files autoload for helpers.php; deps
├── LICENSE.md
├── README.md                     # add "Differences From Spatie" section
├── config/
│   └── permission.php
├── database/
│   └── migrations/
│       ├── 2025_07_02_000000_create_permission_tables.php
│       └── 2025_07_02_000001_add_teams_fields.php
└── src/
    ├── Commands/
    │   ├── AssignRoleCommand.php
    │   ├── CacheResetCommand.php
    │   ├── CreatePermissionCommand.php
    │   ├── CreateRoleCommand.php
    │   ├── ShowCommand.php
    │   └── UpgradeForTeamsCommand.php
    ├── Contracts/
    │   ├── Permission.php
    │   ├── PermissionsTeamResolver.php
    │   ├── Role.php
    │   └── Wildcard.php
    ├── DefaultTeamResolver.php
    ├── Events/
    │   ├── PermissionAttachedEvent.php
    │   ├── PermissionDetachedEvent.php
    │   ├── RoleAttachedEvent.php
    │   └── RoleDetachedEvent.php
    ├── Exceptions/
    │   ├── GuardDoesNotMatch.php
    │   ├── PermissionAlreadyExists.php
    │   ├── PermissionDoesNotExist.php
    │   ├── RoleAlreadyExists.php
    │   ├── RoleDoesNotExist.php
    │   ├── TeamModelNotConfigured.php
    │   ├── TeamsNotEnabled.php
    │   ├── UnauthorizedException.php
    │   ├── WildcardPermissionInvalidArgument.php
    │   ├── WildcardPermissionNotImplementsContract.php
    │   └── WildcardPermissionNotProperlyFormatted.php
    ├── Guard.php
    ├── helpers.php
    ├── Middleware/
    │   ├── PermissionMiddleware.php
    │   ├── RoleMiddleware.php
    │   └── RoleOrPermissionMiddleware.php
    ├── Models/
    │   ├── Permission.php
    │   └── Role.php
    ├── PermissionRegistrar.php
    ├── PermissionServiceProvider.php
    ├── Support/
    │   └── Config.php
    ├── Traits/
    │   ├── HasAssignedModels.php
    │   ├── HasPermissions.php
    │   ├── HasRoles.php
    │   └── RefreshesPermissionCache.php
    └── WildcardPermission.php
```

Tests live in `tests/Permission/` (see §8). Namespaces: source `Hypervel\Permission\…`, tests `Hypervel\Tests\Permission\…`.

---

## 5. Cache design (detailed)

Two store-backed cache layers, both coroutine-safe (no singleton/static state):

### 5.1 Catalog cache — role → permissions

One entry mapping every role (by its primary key) to its record and its permissions (each permission carries its `is_forbidden` pivot value). Used to resolve "what does role X grant" without a DB join on every check.

- Key: `permission.cache.keys.roles` (default `hypervel.permission.cache.roles`).
- Shape: `[ (string)$role->getKey() => ['role' => $role->toArray(), 'permissions' => $role->permissions->toArray()] ]`. Keyed by `getKey()`, **never** hardcoded `id`.
- Built lazily via `Repository::remember(key, ttl, fn)`.
- Invalidated by `PermissionRegistrar::forgetCachedPermissions()`.

### 5.2 Per-subject caches — a subject's roles and direct permissions

For each subject (any model using `HasRoles`/`HasPermissions`), cache its assigned role records and its direct permission records (each with `is_forbidden`).

- Keys (prefix from config; include team id when teams enabled):
  - roles: `{cache.keys.model_roles}:{morphType}:{key}[:team:{teamId}]`
  - permissions: `{cache.keys.model_permissions}:{morphType}:{key}[:team:{teamId}]`
- The morph type is `$subject->getMorphClass()`; the key is `$subject->getKey()`.
- Invalidated (targeted) when that subject's assignments change.
- **Role subjects are the exception:** a Role's own direct permissions are read from the **catalog** entry for that role (its `role_has_permissions`), not from a per-subject permissions cache — the catalog already holds role→permissions, so caching a Role's permissions separately would duplicate it. The per-subject permissions cache applies only to non-Role subjects (users, teams, etc.). Role subjects still use the per-subject **roles** cache only if they themselves are assigned roles, which is not a supported pattern; in practice a Role uses the catalog for permissions and has no assigned roles.

### 5.3 Why two layers / how invalidation stays correct

Per-subject caches store **role records and direct-permission records**; role→permission resolution goes through the catalog. Therefore:

- **Subject assignment change** (assign/remove/sync role; give/forbid/revoke/sync permission on a non-Role subject) → clear that subject's two keys only. Cheap, targeted.
- **A Role's permissions change** (give/forbid/revoke/sync permission on a Role) → `forgetCachedPermissions()` (catalog only). Per-subject role lists remain valid (they store role identity, not the role's resolved permissions).
- **Any Role/Permission model created/updated/deleted** → `forgetCachedPermissions()` via the `RefreshesPermissionCache` boot hook (catalog). This fixes the current bug where model lifecycle changes didn't flush.

A deleted permission/role drops out naturally: the per-subject cache may still list its key, but resolution through the catalog finds nothing → not granted (and a deleted forbidden entry can't grant anything either). FKs (`cascadeOnDelete`) remove the pivot rows; cache entries expire or are cleared on the next mutation. No false grants.

### 5.4 Check flow (`hasPermissionTo`)

```
hasPermissionTo($permission, $guard = null):
  if wildcard enabled for this model: return hasWildcardPermission(...)
  resolve $permission to a Permission record (filterPermission → DB find helper)
  if hasForbiddenPermission($permission): return false           # direct forbidden
  if not a Role and hasForbiddenPermissionViaRoles($permission): return false
  return hasDirectPermission($permission) || hasPermissionViaRole($permission)
```

- `hasDirectPermission` / `hasForbiddenPermission` read the per-subject **permissions** cache and filter on `is_forbidden`.
- `hasPermissionViaRole` / `hasForbiddenPermissionViaRoles` read the per-subject **roles** cache, then the **catalog** for each role's permissions, filtering on `is_forbidden`.
- All cache-backed; DB only on cold cache.

### 5.5 Recommended store config (docs, not code)

```php
// config/cache.php
'stores' => [
    'permission' => [
        'driver' => 'stack',
        'stores' => ['swoole', 'redis'], // worker-local L1 + shared L2, write-through + back-fill
    ],
],
// config/permission.php
'cache' => ['store' => 'permission', ...],
```

Document this; ship `store => 'default'` as the safe default.

---

## 6. Implementation — file by file

Port in the order below (dependencies first). For each **faithful port**, copy the spatie file, then apply the standard AGENTS.md transforms: namespace `Spatie\Permission\…` → `Hypervel\Permission\…`; `Illuminate\…` → `Hypervel\…`; add `declare(strict_types=1)`; full type hints + return types; title-only method docblocks; replace `config()`/`app()` per the rules below; `===`/`!==`; remove any deprecated/Octane/Passport-version shims except where D9 keeps them. For each **divergent file**, use the code/spec given here.

### 6.1 `composer.json` (skeleton already has name/license/autoload psr-4)

- Add `autoload.files`: `["src/helpers.php"]` (the skeleton currently has none — required for the global team-id helpers).
- Dependencies (this is a monorepo sub-package with no lockfile, so editing `src/permission/composer.json` directly is allowed per CLAUDE.md): keep the existing `hypervel/*` requires (`auth`, `cache`, `collections`, `console`, `contracts`, `database`, `http`, `support`) and add `hypervel/view` (blade directives), `hypervel/routing` (route macros), `hypervel/events` (event dispatch via the `'events'` binding). All three are confirmed sub-packages (`hypervel/view`, `hypervel/routing`, `hypervel/events`). The `auth()`, `event` dispatcher, and `__()` helpers come from already-required packages — no extra require needed for those.
- Confirm the root `composer.json` still maps `Hypervel\\Permission\\` psr-4 (it does, line 89) and lists `hypervel/permission` (line 252). The root `composer.json` has its own `autoload.files` array (sub-package `files` are **not** auto-aggregated for monorepo dev/tests), so **add `"src/permission/src/helpers.php"` to the root `autoload.files`** and run `composer dump-autoload` from the components root. Without this, `getPermissionsTeamId()`/`setPermissionsTeamId()`/`getModelForGuard()` are undefined in the test suite.

### 6.2 `config/permission.php` (divergent — full spec)

Adopt spatie's keys with `model_*` naming, plus the Hypervel cache shape and the forbidden feature needs no config. Final content:

```php
<?php

declare(strict_types=1);

use Hypervel\Permission\DefaultTeamResolver;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;

return [
    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
        // Teams: the app's own team/tenant/org model. Null until teams are used.
        'team' => null,
        // Used by HasAssignedModels when raw ids are passed; falls back to the guard's model.
        'default_model' => null,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => 'role_id',
        'permission_pivot_key' => 'permission_id',
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    // Storage connection for the permission tables (migration + models honor this).
    'storage' => [
        'database' => [
            'connection' => env('DB_CONNECTION', 'mysql'),
        ],
    ],

    // Register the Gate::before permission check so $user->can('permission') works.
    'register_permission_check_method' => true,

    // Fire RoleAttached/Detached and PermissionAttached/Detached events on assignment changes.
    'events_enabled' => false,

    // Teams (permission scoping by team_foreign_key). Set true BEFORE migrating.
    'teams' => false,

    // Resolver for the current team id (coroutine-scoped in Hypervel).
    'team_resolver' => DefaultTeamResolver::class,

    // Use Passport clients (client-credentials grant) as the authorizable in middleware.
    'use_passport_client_credentials' => false,

    // Include role/permission names in 403 exception messages (info-leak; default off).
    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,

    // Wildcard permission matching.
    'enable_wildcard_permission' => false,
    // 'wildcard_permission' => Hypervel\Permission\WildcardPermission::class,

    'cache' => [
        'expiration_seconds' => 86400, // 24 hours
        'keys' => [
            'roles' => 'hypervel.permission.cache.roles',
            'model_roles' => 'hypervel.permission.cache.model.roles',
            'model_permissions' => 'hypervel.permission.cache.model.permissions',
        ],
        'store' => env('PERMISSION_CACHE_STORE', 'default'),
    ],
];
```

Notes:
- `cache.expiration_seconds` (int) is the Hypervel shape (spatie uses a `DateInterval` `expiration_time`). Documented divergence — keep.
- `cache.keys.{model_roles,model_permissions}` are the per-subject prefixes (renamed from the archived `owner_*`). `cache.keys.roles` is the catalog.
- No `register_octane_reset_listener` (D14). No `cache.key` (single) — we use `cache.keys`.

### 6.3 Migrations (divergent — full spec)

**`2025_07_02_000000_create_permission_tables.php`** — config-driven, with `is_forbidden`, composite unique, FKs, storage connection. Use Hypervel `Migration`/`Blueprint`/`Schema`. Use `id()` for bigint primary keys. **Do not use `$table->morphs()`** for the morph columns — the morph key name is configurable (`column_names.model_morph_key`), so declare `string('model_type')` + `unsignedBigInteger($columnNames['model_morph_key'])` + a composite index explicitly (matches spatie; UUID-keyed subjects adjust the published migration). Key requirements:

- `getConnection()` returns `config('permission.storage.database.connection') ?: parent::getConnection()`.
- Read `$tableNames = config('permission.table_names')`, `$columnNames = config('permission.column_names')`, `$teams = config('permission.teams')`, pivot keys with defaults.
- `permissions`: `id`, `string('name')`, `string('guard_name')`, `timestamps()`, `unique(['name','guard_name'])`.
- `roles`: `id`; if `$teams`, nullable `team_foreign_key` + index; `string('name')`, `string('guard_name')`, `timestamps()`; unique `(team_foreign_key, name, guard_name)` when teams else `(name, guard_name)`.
- `model_has_permissions`: `unsignedBigInteger(permission_pivot_key)`, `string('model_type')`, `unsignedBigInteger(model_morph_key)`, **`boolean('is_forbidden')->default(false)`**, index on `(model_morph_key, model_type)`, FK `permission_pivot_key → permissions(id) cascadeOnDelete`; primary key `(permission_pivot_key, model_morph_key, model_type)` (prefixed with `team_foreign_key` when teams). The `is_forbidden` column is the Hypervel divergence — add a brief comment.
- `model_has_roles`: same shape minus `is_forbidden`, FK to `roles(id)`, primary `(role_pivot_key, model_morph_key, model_type)` (team-prefixed when teams).
- `role_has_permissions`: `unsignedBigInteger(permission_pivot_key)`, `unsignedBigInteger(role_pivot_key)`, **`boolean('is_forbidden')->default(false)`**, FKs to both with `cascadeOnDelete`, primary `(permission_pivot_key, role_pivot_key)`.
- End of `up()`: forget the catalog cache key (best-effort): resolve the configured store and `forget(config('permission.cache.keys.roles'))`.
- `down()`: drop in reverse FK-safe order.

**`2025_07_02_000001_add_teams_fields.php`** — faithful port of `spatie:database/migrations/add_teams_fields.php.stub`, adapted to Hypervel `Schema`/`Blueprint`, reading `model_*` table names. `up()` returns early unless `config('permission.teams')`. Adds `team_foreign_key` (nullable on `roles`, default on the two `model_has_*`), rebuilds unique/primary indexes and FKs, forgets the catalog cache. `down()` empty (matches spatie).

Both migrations are published via the provider (see §6.18); the create migration is also auto-loaded for tests (see §8.1).

### 6.4 `Guard.php` (faithful port)

Port `spatie:src/Guard.php` verbatim in behavior: `getNames`, `getProviderModel`, `getConfigAuthGuards`, `getModelForGuard`, `getDefaultName`, `getPassportClient`. Adapt: `Illuminate\Support\Collection` → `Hypervel\Support\Collection`; `Illuminate\Support\Facades\Auth` → `Hypervel\Support\Facades\Auth`; `Authorizable` contract → `Hypervel\Contracts\Auth\Access\Authorizable`; `config(...)` reads stay as the `config()` helper is acceptable in this static utility, but prefer `Container::getInstance()->make('config')` to match the DI rule — use the latter. `getPassportClient` (D9) is kept as-is; it duck-types `client()` via `method_exists`, so no Passport dependency.

**Static-cache note (decided, do NOT add):** `Guard::getNames` partly depends on per-instance `guard_name` (a DB column), so caching resolved guard names by class is unsafe. Reflection cost is negligible. Do not add a static cache here.

### 6.5 `Support/Config.php` (faithful port, adapted)

Port `spatie:src/Support/Config.php`: `teamsEnabled`, `ensureTeamsEnabled`, `teamModel`, `modelHasRolesTable`, `modelHasPermissionsTable`, `roleHasPermissionsTable`, `rolesTable`, `permissionsTable`, `morphKey`, `teamForeignKey`, `roleModel`, `permissionModel`, `eventsEnabled`, `usePassportClientCredentials`, `displayRoleInException`, `displayPermissionInException`, `wildcardPermissionsEnabled`, `wildcardPermissionClass`. Replace `config(...)` with `Container::getInstance()->make('config')->...` and `app(PermissionRegistrar::class)` with `Container::getInstance()->make(PermissionRegistrar::class)`. Throws `TeamsNotEnabled`/`TeamModelNotConfigured` as spatie does. **Add a Hypervel-specific `storageConnection(): ?string`** returning `permission.storage.database.connection` (used by the models, D12) — this has no spatie equivalent.

### 6.6 Contracts (faithful ports)

- `Contracts/Permission.php`: `roles(): BelongsToMany`; statics `findByName(UnitEnum|string $name, ?string $guardName): self`, `findById(int|string $id, ?string $guardName): self`, `findOrCreate(UnitEnum|string $name, ?string $guardName): self`. Use `UnitEnum|string` (kept Hypervel improvement — `enum_value` handles both backed and unit enums), not spatie's `BackedEnum|string`. Keep `@mixin`/`@phpstan-require-extends` to the base model.
- `Contracts/Role.php`: `permissions(): BelongsToMany`; the same three statics; `hasPermissionTo(string|int|Permission|UnitEnum $permission, ?string $guardName = null): bool`.
- `Contracts/Wildcard.php`: `getIndex(): array`, `implies(string $permission, string $guardName, array $index): bool`.
- `Contracts/PermissionsTeamResolver.php`: `getPermissionsTeamId(): int|string|null`, `setPermissionsTeamId(int|string|Model|null $id): void`.

### 6.7 Exceptions (faithful ports)

Port all eleven from `spatie:src/Exceptions/`, replacing `__(...)` (Hypervel has it), `Illuminate\Support\Collection` → `Hypervel\Support\Collection`, `Symfony\…\HttpException` stays. Files: `RoleDoesNotExist`, `PermissionDoesNotExist` (static `create`/`named`/`withId`), `RoleAlreadyExists`, `PermissionAlreadyExists` (static `create`), `GuardDoesNotMatch` (static `create(string, Collection)`), `TeamsNotEnabled`, `TeamModelNotConfigured`, `WildcardPermissionInvalidArgument`, `WildcardPermissionNotImplementsContract`, `WildcardPermissionNotProperlyFormatted`, and `UnauthorizedException` (the rich one: `forRoles`/`forPermissions`/`forRolesOrPermissions`/`missingTraitHasRoles`/`notLoggedIn`/`getRequiredRoles`/`getRequiredPermissions`, gated by `Config::displayRoleInException`/`displayPermissionInException`).

**Divergence note:** the archived package had `PermissionException`/`RoleException` (HTTP exceptions carrying the required list) and an empty `UnauthorizedException`. The fresh port adopts spatie's single rich `UnauthorizedException` for middleware failures (it carries `getRequiredRoles`/`getRequiredPermissions` and honors `display_*_in_exception`). Drop `PermissionException`/`RoleException`. Middleware throws `UnauthorizedException::forPermissions(...)` / `forRoles(...)` / `notLoggedIn()` / `missingTraitHasRoles(...)`.

### 6.8 Events (faithful ports, simplified)

Plain data-carrier classes with `declare(strict_types=1)`, public readonly promoted properties, no broadcast/serialization traits. They are dispatched from the traits via the container's `'events'` dispatcher — `Container::getInstance()->make('events')->dispatch(new RoleAttachedEvent(...))` — not the `event()` helper, since trait code follows the DI-over-helpers rule (§6.14/§6.15):

```php
final class RoleAttachedEvent
{
    public function __construct(public Model $model, public mixed $rolesOrIds) {}
}
```

Same for `RoleDetachedEvent`, `PermissionAttachedEvent`, `PermissionDetachedEvent`. Keep the docblock noting the payload may be ids or models.

### 6.9 `DefaultTeamResolver.php` (divergent — CoroutineContext)

```php
<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\PermissionsTeamResolver;

class DefaultTeamResolver implements PermissionsTeamResolver
{
    public const TEAM_ID_CONTEXT_KEY = '__permission.team_id';

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        CoroutineContext::set(self::TEAM_ID_CONTEXT_KEY, $id);
    }

    public function getPermissionsTeamId(): int|string|null
    {
        return CoroutineContext::get(self::TEAM_ID_CONTEXT_KEY);
    }
}
```

The resolver instance holds no state; the team id is coroutine-scoped and auto-clears at coroutine end. The registrar may hold the resolver instance (immutable singleton).

### 6.10 `helpers.php` (faithful port)

```php
<?php

declare(strict_types=1);

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;

if (! function_exists('getModelForGuard')) {
    function getModelForGuard(string $guard): ?string
    {
        return Guard::getModelForGuard($guard);
    }
}

if (! function_exists('setPermissionsTeamId')) {
    function setPermissionsTeamId(int|string|Model|null $id): void
    {
        Container::getInstance()->make(PermissionRegistrar::class)->setPermissionsTeamId($id);
    }
}

if (! function_exists('getPermissionsTeamId')) {
    function getPermissionsTeamId(): int|string|null
    {
        return Container::getInstance()->make(PermissionRegistrar::class)->getPermissionsTeamId();
    }
}
```

(Global functions in a non-class file: helper usage is allowed here per CLAUDE.md.)

### 6.11 `PermissionRegistrar.php` (divergent — full spec)

The registrar is the cache layer + team-id delegate + class registry + gate hook. **Immutable config only**; cache derived data in the store. Public surface (parity-named where it maps):

Immutable state (set in constructor from config): `$permissionClass`, `$roleClass`, `$teamClass`, `$teamResolver` (the `PermissionsTeamResolver`), `$teams` (bool), `$teamsKey` (string), `$cacheKey` (catalog key), `$modelRolesCacheKeyPrefix`, `$modelPermissionsCacheKeyPrefix`, `$cacheExpirationSeconds`, `$pivotRole`, `$pivotPermission`, `$cache` (resolved `Repository`). No `$permissions` collection, no `$isLoadingPermissions`, no `$wildcardPermissionsIndex`, no `$alias`/`$except`/`$cachedRoles`.

Constructor: inject `Container` and `CacheManager` (`Hypervel\Contracts\Cache\Factory`). Read config via the injected config repository. Resolve the cache store via `getCacheStoreFromConfig()` (port the archived logic: `cache.store` of `'default'` → `cacheManager->store()`; unknown store → fall back to `'array'`; use the **injected config** for `cache.stores`, not the `config()` helper — fix the archived inconsistency). Build the team resolver: `new (config('permission.team_resolver', DefaultTeamResolver::class))`.

Methods:

- `getPermissionClass(): string`, `getRoleClass(): string`, `getTeamClass(): ?string`. **No setters** (drop `setPermissionClass`/`setRoleClass`/`setTeamClass` — they used `config()->set()`/`bind()`).
- `setPermissionsTeamId(int|string|Model|null $id): void` → `$this->teamResolver->setPermissionsTeamId($id)`.
- `getPermissionsTeamId(): int|string|null` → `$this->teamResolver->getPermissionsTeamId()`.
- `getCacheRepository(): Repository`, `getCacheStore(): Store` (used by `CacheResetCommand`).
- **Catalog:**
  - `getAllRolesWithPermissions(): array` — `remember(cacheKey, ttl, fn)` building `[(string)$role->getKey() => ['role' => $role->toArray(), 'permissions' => $role->permissions->toArray()]]` from `roleClass::with('permissions')->get()`. Key by `getKey()`.
  - `forgetCachedPermissions(): bool` — `$this->cache->forget($this->cacheKey)`.
- **Per-subject cache** (keyed by morph type + key + optional team id):
  - `getModelRolesCacheKey(string $morphType, int|string $key): string`
  - `getModelPermissionsCacheKey(string $morphType, int|string $key): string`
  - `cacheModelRoles(...)`, `cacheModelPermissions(...)`, `getCachedModelRoles(...)`, `getCachedModelPermissions(...)`, `clearModelCache(string $morphType, int|string $key): void`. Team id (when teams enabled) is appended to the key via `getPermissionsTeamId()`.
- **Gate:** `registerPermissions(Gate $gate): bool` — port spatie's `$gate->before(...)` calling `$user->checkPermissionTo($ability, $guard ?? null)`.
- **`isUid(mixed $value): bool`** — port spatie's static UUID/ULID detector (used by trait id-vs-name resolution).

Key snippet (catalog + a per-subject helper):

```php
public function getAllRolesWithPermissions(): array
{
    return $this->cache->remember($this->cacheKey, $this->cacheExpirationSeconds, function (): array {
        $roleClass = $this->getRoleClass();

        return $roleClass::with('permissions')->get()
            ->mapWithKeys(fn ($role) => [
                (string) $role->getKey() => [
                    'role' => $role->toArray(),
                    'permissions' => $role->permissions->toArray(),
                ],
            ])->all();
    });
}

protected function modelCacheKeySuffix(): string
{
    if (! $this->teams) {
        return '';
    }

    return ':team:' . ($this->getPermissionsTeamId() ?? 'null');
}
```

### 6.12 `WildcardPermission.php` (faithful port) + wildcard exceptions

Port `spatie:src/WildcardPermission.php` verbatim (it is pure — operates on the injected `$record`): `getIndex()`, `buildIndex()`, `implies()`, `checkIndex()`, the three delimiter constants. Adapt namespace/types; `Illuminate\Support\Str` → `Hypervel\Support\Str`; throws `WildcardPermissionNotProperlyFormatted`. The index is computed on demand in `HasPermissions::hasWildcardPermission` from `$this->getAllPermissions()` (cache-backed) — **not** cached on the registrar (D8).

### 6.13 `Traits/RefreshesPermissionCache.php` (faithful port)

```php
trait RefreshesPermissionCache
{
    public static function bootRefreshesPermissionCache(): void
    {
        static::saved(fn () => Container::getInstance()->make(PermissionRegistrar::class)->forgetCachedPermissions());
        static::deleted(fn () => Container::getInstance()->make(PermissionRegistrar::class)->forgetCachedPermissions());
    }
}
```

Used by both models (fixes the missing cache-invalidation bug — §5.3).

### 6.14 `Traits/HasRoles.php` (divergent body, spatie surface)

Public surface = spatie's: `bootHasRoles` (deleting → detach roles, and detach users if Permission), `getRoleClass`, `roles()` (morphToMany with team scoping when enabled), `scopeRole`/`scopeWithoutRole`, `teams()`/`scopeTeam`/`scopeWithoutTeam` (team feature), `assignRole(...$roles)`, `removeRole(...$roles)`, `syncRoles(...$roles)`, `hasRole($roles, $guard = null)` (string|int|array|Role|Collection|UnitEnum, pipe-strings, guard filter), `hasAnyRole(...$roles)`, `hasAllRoles($roles, $guard = null)`, `hasExactRoles($roles, $guard = null)`, `getRoleNames(): Collection`, `getStoredRole`, `convertPipeToArray`.

Bodies: port spatie's logic, with these Hypervel changes:
- Use `enum_value()` for normalization; accept `UnitEnum` (not only `BackedEnum`).
- **`hasRole`/`hasAnyRole`/`hasAllRoles`/`hasExactRoles` read the per-subject roles cache** (`PermissionRegistrar::getCachedModelRoles` → hydrate) instead of `loadMissing('roles')` on every call. Cold cache loads from DB and caches.
- **`assignRole`/`removeRole`/`syncRoles`** mutate the pivot (resolve names/ids → keys using `getKeyName()`/`getKey()`, never hardcoded `id`), then `clearModelCache(getMorphClass(), getKey())`, then (if `Config::eventsEnabled()`) dispatch `RoleAttachedEvent`/`RoleDetachedEvent` via `Container::getInstance()->make('events')->dispatch(...)`. Preserve spatie's before-save isolation (register a one-shot `static::saved` hook to attach when the subject is not yet persisted).
- Team pivot: when teams enabled and the subject is not a Permission, include `[teamsKey => getPermissionsTeamId()]` on attach.
- `roles()` relation: when teams enabled, `withPivot(teamsKey)` + `wherePivot(teamsKey, getPermissionsTeamId())` + nullable-team `orWhere`. Replace the spatie pattern of toggling `app(PermissionRegistrar::class)->teams` with coroutine-safe logic — do **not** mutate the registrar's `teams` flag at runtime; in `bootHasRoles` deletion cleanup, detach without the team filter by querying the pivot table directly (see §6.16 note).

`collectRoles(...)` resolves inputs to role keys via `getStoredRole` (which uses `findById`/`findByName`), `ensureModelSharesGuard`, dedup by `getKey()`.

### 6.15 `Traits/HasPermissions.php` (divergent body, spatie surface + forbidden)

Public surface = spatie's plus the forbidden additions: `bootHasPermissions` (deleting → detach permissions/users), `getPermissionClass`, `getWildcardClass`, `permissions()` (morphToMany, `withPivot('is_forbidden')`, team scoping), `scopePermission`/`scopeWithoutPermission`, `convertToPermissionModels`, `filterPermission`, `hasPermissionTo` (with forbidden override), `hasWildcardPermission`, `checkPermissionTo`, `hasAnyPermission`, `hasAllPermissions`, `hasPermissionViaRole`, `hasDirectPermission`, `getPermissionsViaRoles`, `getAllPermissions`, `givePermissionTo`, `syncPermissions`, `revokePermissionTo`, `getPermissionNames`, `getStoredPermission`, `ensureModelSharesGuard`, `getGuardNames`, `getDefaultGuardName`, `forgetCachedPermissions`, `hasAllDirectPermissions`, `hasAnyDirectPermission`.

**Forbidden additions (Hypervel feature, port from `_archive/permission`):** public `giveForbiddenTo(...$permissions)`, public `hasForbiddenPermission($permission)`, public `hasForbiddenPermissionViaRoles($permission)`, and `syncPermissions(array $allow = [], array $forbidden = [])` (two-arg form; forbidden wins on conflict). `permissions()` carries `is_forbidden` in `withPivot`.

**Method-visibility decisions (final):** the public check surface is spatie's (`hasPermissionTo`, `hasAnyPermission`, `hasAllPermissions`, `hasDirectPermission`, `hasAllDirectPermissions`, `hasAnyDirectPermission`, `getPermissionsViaRoles`, `getAllPermissions`) plus the three public forbidden methods above. `hasPermissionViaRole(Permission $permission)` stays **protected** (spatie's internal helper). Do not add a public `hasPermissionViaRoles` — the archived public name is dropped; tests assert via `hasPermissionTo`/`getPermissionsViaRoles`/the forbidden methods.

Bodies: port spatie's logic with Hypervel cache + forbidden integration:
- **`hasPermissionTo`** order (the forbidden override): wildcard short-circuit → resolve permission → if `hasForbiddenPermission` return false → if not Role and `hasForbiddenPermissionViaRoles` return false → `hasDirectPermission || hasPermissionViaRole`.
- `hasDirectPermission`/`hasForbiddenPermission` read the subject's direct permissions, filter on `is_forbidden`. **For a non-Role subject**, read the per-subject permissions cache. **For a Role subject** (a Role uses `HasPermissions`; its direct permissions are its `role_has_permissions`), read them from the **catalog** entry for that role rather than a separate per-subject cache (avoids double-caching). Guard the catalog read: if the role's key is absent from the catalog (e.g. catalog momentarily stale), the catalog is rebuilt on read (the `RefreshesPermissionCache` saved-hook cleared it when the role was created), so the entry will be present; treat a still-missing key as "no permissions" rather than indexing into a missing key (this fixes the archived undefined-key bug).
- `hasPermissionViaRole`/`hasForbiddenPermissionViaRoles` read the per-subject roles cache, resolve each role's permissions via the **catalog**, filter on `is_forbidden`.
- `getAllPermissions`/`getPermissionsViaRoles` exclude forbidden entries (match the archived behavior and our tests).
- `givePermissionTo`/`giveForbiddenTo`/`revokePermissionTo`/`syncPermissions` mutate pivots with `is_forbidden`, then: if the subject is a Role → `forgetCachedPermissions()` (catalog); else → `clearModelCache(...)`. Then (if `Config::eventsEnabled()`) dispatch `PermissionAttachedEvent`/`PermissionDetachedEvent` via `Container::getInstance()->make('events')->dispatch(...)`.
- All key handling via `getKeyName()`/`getKey()` — no hardcoded `id`. No loose `==` — use `===` (the archived `is_forbidden == true` becomes a strict boolean check; cast the pivot value with `(bool)`).
- `getWildcardClass`/`hasWildcardPermission` compute the index from `$this->getAllPermissions()` on demand (no registrar index state). Drop `forgetWildcardPermissionIndex` (no index to forget).

### 6.16 `Traits/HasAssignedModels.php` (faithful port)

Port `spatie:src/Traits/HasAssignedModels.php`: `assignToModels`, `removeFromModels`, `syncModels`, `relationForModel` (morphedByMany), `groupModelsByMorphClass`, `resolveDefaultModelClass`, `teamPivot`, `newPivotQueryForRole`. Used by `Models/Role` (reverse assignment: assign a role to many models). Adapt namespace/types; `Illuminate\Database\Query\Builder` → `Hypervel\Database\Query\Builder`; team pivot via `getPermissionsTeamId()`. After mutations, also `forgetCachedPermissions()` is unnecessary (role identity unchanged) but the affected subjects' caches should be cleared — since enumerating them is impractical, document that reverse-assignment relies on per-subject TTL/next-mutation for cache freshness, OR clear each touched subject's cache in the loop (preferred: clear per touched model key). **Decision: clear each touched subject's per-subject cache in the loop** (we already iterate the ids).

Note for §6.14 deletion cleanup: instead of spatie's `app(PermissionRegistrar::class)->teams = false` toggle (a runtime singleton mutation — banned), detach in `bootHasRoles`/`bootHasPermissions` by operating on the relation without the team filter. Implement a small protected helper that builds the morph pivot query directly (like `newPivotQueryForRole`) and deletes the subject's rows, avoiding the team-scoped relation. This keeps deletion cleanup correct without mutating shared state.

### 6.17 Models (divergent — full spec)

**`Models/Permission.php`:**

```php
<?php

declare(strict_types=1);

namespace Hypervel\Permission\Models;

use Carbon\CarbonImmutable;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Exceptions\PermissionAlreadyExists;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\Traits\HasRoles;
use Hypervel\Permission\Traits\RefreshesPermissionCache;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @property int|string $id
 * @property string $name
 * @property string $guard_name
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Collection<int, Role> $roles
 */
class Permission extends Model implements PermissionContract
{
    use HasRoles;
    use RefreshesPermissionCache;

    protected array $guarded = [];

    public function __construct(array $attributes = [])
    {
        $attributes['guard_name'] ??= Guard::getDefaultName(static::class);

        parent::__construct($attributes);

        $this->guarded[] = $this->getKeyName();
        $this->setTable(Config::permissionsTable() ?: $this->getTable());
        $this->setConnection(Config::storageConnection() ?: $this->getConnectionName());
    }

    public static function create(array $attributes = []): PermissionContract
    {
        $attributes['guard_name'] ??= Guard::getDefaultName(static::class);
        $attributes['name'] = enum_value($attributes['name']);

        if (static::findByParam(['name' => $attributes['name'], 'guard_name' => $attributes['guard_name']])) {
            throw PermissionAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }

        return static::query()->create($attributes);
    }

    public function roles(): BelongsToMany { /* belongsToMany(Role, role_has_permissions, permission_pivot_key, role_pivot_key)->withPivot('is_forbidden') */ }

    public function users(): BelongsToMany { /* morphedByMany(getModelForGuard(guard_name), 'model', model_has_permissions, permission_pivot_key, model_morph_key) */ }

    public static function findByName(UnitEnum|string $name, ?string $guardName = null): PermissionContract { /* enum_value, default guard, findByParam, throw PermissionDoesNotExist::create */ }

    public static function findById(int|string $id, ?string $guardName = null): PermissionContract { /* findByParam([keyName => id, guard]), throw ::withId */ }

    public static function findOrCreate(UnitEnum|string $name, ?string $guardName = null): PermissionContract { /* findByParam or create */ }

    protected static function findByParam(array $params = []): ?PermissionContract { /* query, where each param, first() — NO team filter: the permissions table has no team_foreign_key; only roles + assignments are team-scoped */ }
}
```

Key divergences from spatie's `Models/Permission`:
- `$guarded = []` + guard the key in the constructor (D11).
- `findByName`/`findById`/`findOrCreate`/`findByParam` query the DB directly (D17) — do **not** route through a registrar `getPermissions()` catalog. (spatie's `Permission` uses `getPermission`/`getPermissions`; we replace those with `findByParam`-style direct queries, mirroring spatie's `Role` model.)
- `users()` reverse relation via `morphedByMany`.
- Table/connection from `Config` (D12). Add `Config::storageConnection()` helper to `Support/Config` (reads `permission.storage.database.connection`).
- `roles()` uses `withPivot('is_forbidden')`.

**`Models/Role.php`:** mirror of Permission with `HasRoles` + `HasPermissions` + `HasAssignedModels` + `RefreshesPermissionCache`, `permissions()` (`belongsToMany(Permission, role_has_permissions, role_pivot_key, permission_pivot_key)->withPivot('is_forbidden')`), `users()` (`morphedByMany(..., model_has_roles, role_pivot_key, model_morph_key)`), the three find/create statics, `findByParam`, `create()` (with `RoleAlreadyExists` + team handling), and `hasPermissionTo($permission, $guardName = null)` (forbidden-aware, via `HasPermissions`). Constructor sets guard default, guards the key, table from `Config::rolesTable()`, connection from `Config::storageConnection()`. **Unlike `Permission::findByParam`, `Role::findByParam` applies the team filter when teams are enabled** (the `roles` table has `team_foreign_key`): scope to rows where the team key is null or equals `getPermissionsTeamId()` (port spatie's `Role::findByParam`). `create()` and `findOrCreate()` set the team key from `getPermissionsTeamId()` when teams are enabled.

### 6.18 Middleware (faithful ports, adapted to `UnauthorizedException`)

Port `spatie:src/Middleware/{PermissionMiddleware,RoleMiddleware,RoleOrPermissionMiddleware}.php`:
- `handle(Request $request, Closure $next, $arg, ?string $guard = null)` — resolve `Auth::guard($guard)->user()`; Passport fallback (D9); `UnauthorizedException::notLoggedIn()`; `missingTraitHasRoles($user)`; check via `canAny` (permission) / `hasAnyRole` (role) / both (role-or-permission); throw `forPermissions`/`forRoles`/`forRolesOrPermissions`.
- Static `using(...)` returning `static::class . ':' . $args` (with `,$guard` when set), parsing enums via `enum_value`.
- Keep `BackedEnum`-accepting `using()` signatures but normalize via `enum_value` so unit enums work too.

**Divergence from archived:** the archived middleware used `hasAnyPermissions`/`hasAnyRoles` (plural) + injected `Container` + `PermissionException`/`RoleException`. The port uses spatie's `Auth::guard()` + `canAny`/`hasAnyRole` + `UnauthorizedException`. Note: `canAny` requires the Gate permission hook (D10) to be registered for permission middleware to work via the gate; `RoleMiddleware` uses `hasAnyRole` directly (no gate needed). This matches spatie exactly.

### 6.19 Commands (faithful ports)

Port all six from `spatie:src/Commands/` to `Hypervel\Console\Command` with `#[AsCommand(name: '...')]` (required for lazy resolution): `permission:create-role`, `permission:create-permission`, `permission:cache-reset`, `permission:assign-role`, `permission:show`, `permission:upgrade-for-teams`. Adapt:
- Resolve models via `Container::getInstance()->make(...)` of the configured classes (spatie uses `app(RoleContract::class)`; we have no contract binding for models, so resolve `config('permission.models.role')`/`permission` — or bind the contracts in the provider, see §6.20, and resolve the contract). **Decision:** bind `Contracts\Role`/`Contracts\Permission` to the configured models in the provider (spatie does this), and resolve the contracts in commands. This also serves blade/gate.
- `CacheResetCommand` calls `registrar->forgetCachedPermissions()` (catalog). Document that per-subject caches expire via TTL or clear on next mutation (the global catalog is the user-facing "permission cache").
- `ShowCommand`: port spatie's full version (with teams columns when enabled), using `getKeyName()`.
- `UpgradeForTeamsCommand`: port faithfully (publishes/runs the teams migration concept); adapt to Hypervel console.

### 6.20 `PermissionServiceProvider.php` (divergent — full spec)

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__ . '/../config/permission.php', 'permission');

    $this->app->singleton(PermissionRegistrar::class);

    $this->registerModelBindings();
}

public function boot(): void
{
    $this->registerPublishing();          // config + both migrations (publishesMigrations)
    $this->registerCommands();            // runningInConsole guard
    $this->registerBladeExtensions();     // callAfterResolving('blade.compiler', ...)
    $this->registerMacroHelpers();        // Route::macro role/permission/roleOrPermission via Middleware::using
    $this->registerGateCheck();           // callAfterResolving(Gate::class, ...) when register_permission_check_method
    $this->registerAbout();               // AboutCommand::add (features enabled)
}
```

- `registerModelBindings`: `$this->app->bind(Contracts\Permission::class, fn ($app) => $app->make($app->make('config')->get('permission.models.permission')))`; same for `Contracts\Role`. (No `Factory` contract — D16.)
- `registerGateCheck`: `callAfterResolving(Gate::class, function (Gate $gate) { if (config('permission.register_permission_check_method')) { $this->app->make(PermissionRegistrar::class)->registerPermissions($gate); } })`. No `clearPermissionsCollection()` call (no such state).
- `registerBladeExtensions`: `callAfterResolving('blade.compiler', fn (BladeCompiler $b) => ...)` registering `@haspermission`, `@role`, `@hasrole`, `@hasanyrole`, `@hasallroles`, `@hasexactroles`, `@endunlessrole` via a `bladeMethodWrapper(string $method, $arg, ?string $guard = null)` that calls `auth($guard)->check() && auth($guard)->user()->{$method}($arg)`. Map `@haspermission` → `checkPermissionTo`.
- `registerMacroHelpers`: register macros on `\Hypervel\Routing\Route` (the class is `Macroable`, and `Router::get()`/`post()`/etc. return a `Hypervel\Routing\Route` instance, so the fluent `Route::get(...)->role('admin')` resolves the macro): `\Hypervel\Routing\Route::macro('role', fn ($roles = []) => $this->middleware(RoleMiddleware::using(Arr::wrap($roles))))`; `permission` → `PermissionMiddleware::using`; `roleOrPermission` → `RoleOrPermissionMiddleware::using`. (Use `Middleware::using()` rather than string aliases — no alias registration needed; matches the existing `using()` pattern.) Inside the macro, `$this` is the route instance; map enums via `enum_value` before building the `using()` string.
- `registerPublishing`: publish config (`permission-config`), and both migrations via `publishesMigrations` (`permission-migrations`).
- `registerAbout`: `AboutCommand::add('Permission', fn () => ['Features Enabled' => ...teams/wildcard/passport..., 'Forbidden Permissions' => 'Enabled'])`. Drop spatie's `register_octane_reset_listener` row (D14).
- **No** `registerOctaneListener` (D14).

---

## 7. Documentation updates

### 7.1 `src/boost/docs/permission.md`

The archived doc is accurate to the old API. Rewrite the affected sections (using `Edit` on the published doc once it exists, or write fresh sections) to cover the new surface. Required additions/changes:
- Trait names: `HasRoles`/`HasPermissions` (plural). Method names: `hasPermissionTo`, `hasAnyRole`/`hasAnyPermission`, `hasAllRoles`, `hasExactRoles`, `getRoleNames`/`getPermissionNames`.
- Tables/columns: `model_has_roles`, `model_has_permissions`, `model_morph_key`/`model_id`/`model_type`.
- New sections: **Find & Create Helpers** (`findByName`/`findById`/`findOrCreate`/`create` with guard + enum), **Guards** (default guard, guard matching, multiple guards), **Teams** (enable, team resolver, `setPermissionsTeamId`, team-scoped assignments, custom team model + custom columns), **Wildcard Permissions**, **Events** (`events_enabled` + the four events), **Gate Integration** (`$user->can(...)`, `register_permission_check_method`), **Blade Directives**, **Route Macros** (`Route::role`/`permission`/`roleOrPermission`), **Reverse Assignment** (`Role::assignToModels`), **Role/Permission Middleware + RoleOrPermission**, **Passport client credentials**, **Console Commands** (all six), **Cache** (per-subject + catalog, recommend a `stack` store, `permission:cache-reset`).
- Keep the **Forbidden Permissions** section (the Hypervel feature) updated to the new method names.
- Custom models: document `models.role`/`permission`/`team` swap + that `$guarded = []` lets you add columns (`tenant_id`, json `data`).

### 7.2 `README.md` — "Differences From Spatie"

Add a section listing the intentional divergences (per AGENTS.md):
- Forbidden permissions (`is_forbidden` pivot, `giveForbiddenTo`, two-arg `syncPermissions`).
- Per-subject assignment caching + store-agnostic design (recommend `stack` store); catalog keyed by role.
- Registrar holds no in-memory permission collection / no load-lock (coroutine safety); team id via `CoroutineContext`.
- `cache.expiration_seconds` (int) instead of `expiration_time` (DateInterval); `cache.keys.*` instead of a single `cache.key`.
- No Octane reset listener (N/A); no alias-compression of the cache payload.
- Keep README header `Migrated from: https://github.com/spatie/laravel-permission`.

### 7.3 Config comments

In `config/permission.php`, do **not** claim auto-flush in a misleading way: the cache *is* flushed automatically on role/permission model changes (now true via `RefreshesPermissionCache`) and on assignment changes — phrase accurately.

### 7.4 `src/boost/todo.md`

Remove the existing find-helpers entry (it is implemented by this port). Add no new "deferred" entries — there is nothing deferred. Add a one-line note for the Passport follow-up: when the Passport package lands, verify its `passport`-driver guard exposes a `client()` method matching `Guard::getPassportClient`.

---

## 8. Test plan

Tests extend `Hypervel\Testbench\TestCase` (DB + container) or `Hypervel\Tests\TestCase` (unit/mock). Coroutine isolation is inherited from the base class — never add `RunTestsInCoroutine`. Mockery as `m`; never add `Mockery::close()`. Fixtures in `tests/Permission/Fixtures/`. Run each file immediately after writing it (`./vendor/bin/phpunit --no-progress tests/Permission/<File>.php`), then the whole group, then `composer test:parallel`.

### 8.1 Test harness

- `tests/Permission/PermissionTestCase.php`: extend `Testbench\TestCase`, `use RefreshDatabase`, set `permission` config (with `model_*` names, the new `cache.keys`), set a working `cache` config, and `migrateFreshUsing()` loading `src/permission/database/migrations` + `tests/Permission/migrations` (users table). Remove the archived dead `cache.keys.owner` key. **Set `permission.storage.database.connection` to the test/refresh connection (or `null`)** — required now that the models honor the storage connection (D12); otherwise the models query the package default (`mysql`) while the schema is on the test connection. Provide a teams-enabled variant (or a `withTeams()` helper that re-publishes config with `teams => true` and runs the teams migration) for team tests.
- **Registrar reset + cache flush in `setUp()` (required).** The `PermissionRegistrar` is a singleton that reads `permission` config once at construction; the cache store (e.g. `array`) is memoized for the worker lifetime. Both persist across tests. So in `setUp()`, **after** setting `permission`/`cache` config: call `$this->app->forgetInstance(PermissionRegistrar::class)` (so the next resolution reconstructs it from this test's config — essential for the teams-enabled variant) and flush the configured permission cache store (e.g. `$this->app->make('cache')->store(...)->flush()`) so cached catalog/per-subject entries don't leak between tests. `RefreshDatabase` resets the DB but not the cache.
- **Auth config (required).** Guard defaulting (`Guard::getDefaultName`), guard matching, and the `users()` reverse relation depend on `auth` config. In the harness, set `auth.defaults.guard = 'web'`, `auth.guards.web = ['driver' => 'session', 'provider' => 'users']`, `auth.providers.users = ['driver' => 'eloquent', 'model' => Fixtures\User::class]`. Multi-guard tests add a second guard/provider pointing at a second fixture model.
- Fixtures: `Fixtures/User.php` (uses `HasRoles`, implements `Authenticatable` + `Authorizable`, `$guarded = []`), `Fixtures/Admin.php` / other guard models for multi-guard tests, `Fixtures/Team.php` for team tests, `Fixtures/enums` (`Role`/`Permission` backed enums + a unit enum), `migrations/...create_users_table.php` and any guard tables.

### 8.2 Port spatie's tests (adapt to Hypervel + our method names)

From `spatie:tests/`, port and adapt (namespace `Hypervel\Tests\Permission\…`, base class, strict types, `m::`, typed model props, `andReturnSelf()` where needed). Map spatie helpers to ours where names match (they now do, since we adopted spatie naming). Files to port:

| Spatie test | Port to | Notes |
|---|---|---|
| `Traits/HasRolesTest.php` (60) | `tests/Permission/HasRolesTest.php` | Full assign/remove/sync/has matrix, scopes, guard enforcement, before-save isolation, soft-delete non-detach, lazy-load restriction. |
| `Traits/HasPermissionsTest.php` (59) | `tests/Permission/HasPermissionsTest.php` | Direct/role matrix, scopes, guard enforcement, type/null errors, before-save isolation. |
| `Traits/HasRolesWithCustomModelsTest.php` (65) | `tests/Permission/HasRolesWithCustomModelsTest.php` | Custom models, **custom primary key (non-`id`)**, cascade/touch. Verifies the custom-primary-key fix (§1.2 bug list; all key handling via `getKeyName()`/`getKey()`). |
| `Traits/HasPermissionsWithCustomModelsTest.php` (66) | `tests/Permission/HasPermissionsWithCustomModelsTest.php` | Same, permissions. |
| `Traits/HasAssignedModelsTest.php` (20) | `tests/Permission/HasAssignedModelsTest.php` | Reverse assignment. |
| `Traits/TeamHasRolesTest.php` (64) | `tests/Permission/TeamHasRolesTest.php` | Teams (teams-enabled harness). |
| `Traits/TeamHasPermissionsTest.php` (63) | `tests/Permission/TeamHasPermissionsTest.php` | Teams. |
| `Traits/TeamScopeTest.php` (13) | `tests/Permission/TeamScopeTest.php` | Team scopes + not-enabled/not-configured errors. |
| `Traits/WildcardHasPermissionsTest.php` (17) | `tests/Permission/WildcardHasPermissionsTest.php` | Wildcards. |
| `Models/RoleTest.php` (27) | `tests/Permission/Models/RoleTest.php` | create/find/findOrCreate, duplicate, guard, string-`"0"`. |
| `Models/PermissionTest.php` (11) | `tests/Permission/Models/PermissionTest.php` | Same. |
| `Models/WildcardRoleTest.php` (9) | `tests/Permission/Models/WildcardRoleTest.php` | Wildcard role checks. |
| `Models/RoleWithNestingTest.php` (1) | `tests/Permission/Models/RoleWithNestingTest.php` | `withCount` nested. |
| `Middleware/PermissionMiddlewareTest.php` (24) | `tests/Permission/Middleware/PermissionMiddlewareTest.php` | guest/wrong-guard/super-admin/via-role/exception-payload/`using()`/enum/Passport. |
| `Middleware/RoleMiddlewareTest.php` (23) | `tests/Permission/Middleware/RoleMiddlewareTest.php` | Same for roles. |
| `Middleware/RoleOrPermissionMiddlewareTest.php` (14) | `tests/Permission/Middleware/RoleOrPermissionMiddlewareTest.php` | Combined. |
| `Middleware/WildcardMiddlewareTest.php` (7) | `tests/Permission/Middleware/WildcardMiddlewareTest.php` | Wildcards. |
| `Integration/CacheTest.php` (16) | `tests/Permission/CacheTest.php` | Cache flush on create/update/delete + assignment; `permission:cache-reset`; adapt to our catalog + per-subject keys. |
| `Integration/GateTest.php` (7) | `tests/Permission/GateTest.php` | Gate `before` grants via direct/role. |
| `Integration/CustomGateTest.php` (2) | `tests/Permission/CustomGateTest.php` | `register_permission_check_method` off. |
| `Integration/PolicyTest.php` (1) | `tests/Permission/PolicyTest.php` | Policy before interception. |
| `Integration/MultipleGuardsTest.php` (3) | `tests/Permission/MultipleGuardsTest.php` | Multi-guard + `guardName()` override. |
| `Integration/BladeTest.php` (25) | `tests/Permission/BladeTest.php` | All directives. Requires blade view fixtures. |
| `Integration/RouteTest.php` (10) | `tests/Permission/RouteTest.php` | `Route::role/permission/roleOrPermission` macros (incl. enums). |
| `Integration/WildcardRouteTest.php` (2) | `tests/Permission/WildcardRouteTest.php` | Wildcard via route. |
| `Integration/PermissionRegistrarTest.php` (7) | `tests/Permission/PermissionRegistrarTest.php` | `isUid`, get class, set team id, `forgetCachedPermissions`. Drop spatie's `clearPermissionsCollection`/`setPermissionClass` cases (no such methods — D5/D16); replace with our catalog/per-subject cache assertions. |
| `Commands/CommandTest.php` (17) | `tests/Permission/Commands/CommandTest.php` | create-role/permission, assign-role, show, about. |
| `Commands/TeamCommandTest.php` (3) | `tests/Permission/Commands/TeamCommandTest.php` | Team assignment via command. |

**Signature adaptation:** our `syncPermissions(array $allow = [], array $forbidden = [])` is two-arg (the forbidden divergence, D3), whereas spatie's is variadic (`syncPermissions(...$permissions)`). When porting spatie's sync cases, convert `syncPermissions('a', 'b')` → `syncPermissions(['a', 'b'])`. All other method names already match because we adopted spatie naming (D2), so spatie test bodies port with only namespace/base-class/type changes.

When porting, remove cases that exercise spatie-only internals we intentionally don't have (the in-memory collection clear, `config()->set()` model-class swap). Replace each with the equivalent Hypervel behavior assertion (catalog/per-subject cache). Do not silently drop a case — if it maps to a real behavior, assert that behavior; if it tests an internal we removed by design, replace it with the divergent-design equivalent and note it.

### 8.3 Carry over the forbidden-permission tests (from `_archive/permission`)

Port the archived `HasPermissionTest.php` forbidden cases into `HasPermissionsTest.php` (or a dedicated `ForbiddenPermissionsTest.php`), adapted to spatie method names (`hasPermissionTo`, `hasAnyPermission`):
- give/check forbidden direct; forbidden overrides allowed (direct + via role); role-forbidden overrides user-direct; `syncPermissions($allow, $forbidden)` with forbidden precedence; `getAllPermissions`/`getPermissionsViaRoles` exclude forbidden; pivot `is_forbidden` values; priority matrix (direct-forbidden, role-forbidden). These are the D3 spec — keep all of them.

### 8.4 New tests for gaps (Hypervel-specific)

- **`tests/Permission/CoroutineSafetyTest.php`** (name per AGENTS.md): using `parallel()` + `usleep()`:
  - Two coroutines operating on **different subjects** never see each other's cached roles/permissions; `clearModelCache(A)` does not affect B.
  - Concurrent read vs `forgetCachedPermissions()` rebuild of the catalog yields a consistent snapshot (no torn read).
  - With teams enabled, `setPermissionsTeamId` in one coroutine does not leak into another (CoroutineContext isolation) — assert each coroutine resolves its own team id and team-scoped assignments.
- **`tests/Permission/PermissionRegistrarTest.php`** additions: `getCacheStoreFromConfig()` all three branches (`'default'`, named store, unknown→`array`); per-subject cache round-trip (`cacheModelRoles`→`getCachedModelRoles`); team-aware cache key suffix.
- **Custom primary key regression** (covered by the WithCustomModels ports, but add a focused case asserting `assignRole`/`hasRole`/`getAllPermissions` work with a non-`id` key) — verifies the custom-primary-key bug fix (§1.2).
- **Connection/table config**: a test setting `table_names.roles`/`storage.database.connection` and asserting the model uses them.
- **`guarded = []` custom column**: a custom Role with an extra column, `create([... extra column ...])` mass-assigns it (D11).
- **Schema**: assert `(name, guard_name)` composite unique allows same name under two guards; assert pivot FK `cascadeOnDelete` removes pivot rows when a role/permission is deleted.

### 8.5 Coverage target

Every public method on the registrar, both traits, both models, `Guard`, middleware, commands, and the wildcard/teams/events/gate/blade/route surfaces has at least one test. The forbidden feature and coroutine safety are fully covered. No `@coversNothing`/`#[CoversClass]` (per AGENTS.md). No weakened assertions.

---

## 9. Verification & completion checklist

Run from `contrib/hypervel/components`:

1. After each source file: re-read it in full, confirm strict types, types, docblocks, `===`, no `config()->set()`/runtime-`bind()`, no hardcoded `id`.
2. After each test file: `./vendor/bin/phpunit --no-progress tests/Permission/<File>.php`.
3. Group: `./vendor/bin/phpunit --no-progress tests/Permission`.
4. `./vendor/bin/phpstan` (tests excluded) — fix at the source per AGENTS.md narrowing order; no new neon ignores.
5. `./vendor/bin/php-cs-fixer fix`.
6. `composer test:parallel` — full suite green.
7. Confirm the package is discovered: provider auto-registered, `permission:*` commands listed, migrations publishable.
8. Docs: `permission.md` updated; README "Differences From Spatie" added; `todo.md` find-helper entry removed + Passport follow-up noted.
9. Register any new static-cache `flushState()` with `AfterEachTestSubscriber` — **none expected** (the port introduces no static caches; if one is added, register it).

---

## 10. Intentional divergences from spatie (record in README + source comments)

1. **Forbidden permissions** — `is_forbidden` pivot + `giveForbiddenTo`/`hasForbiddenPermission`/`hasForbiddenPermissionViaRoles` + two-arg `syncPermissions`. (Feature.)
2. **Cache architecture** — store-backed catalog (role→permissions) + per-subject caches; no in-memory registrar collection; no load-lock; `cache.expiration_seconds` (int) + `cache.keys.*`. (Coroutine safety + performance.)
3. **Team id via `CoroutineContext`** (`DefaultTeamResolver`); no Octane reset listener. (Coroutine safety.)
4. **No registrar model-class setters** (`setPermissionClass`/`setRoleClass`/`setTeamClass`) — classes fixed at boot from config. (No `config()->set()`/runtime-`bind()`.)
5. **No `Factory` contract** — `PermissionRegistrar` bound as a singleton concrete. (Simplification; removes double-singleton.)
6. **Find helpers query the DB directly**; no flat-permission catalog. (Simplification.)
7. **No cache alias-compression.** (Simplification.)
8. **Wildcard index computed on demand** from cached permissions; not held on the registrar. (Coroutine safety.)

Each gets a one-line README entry and, where a method/feature is omitted at its natural location, a concise source comment per the AGENTS.md "Record intentional Laravel differences" rule.

---

## 11. Parity map (quick reference)

| spatie file | Hypervel disposition |
|---|---|
| `PermissionRegistrar.php` | Divergent (§6.11) — store-backed, no per-request state. |
| `Guard.php` | Faithful (§6.4). |
| `WildcardPermission.php` | Faithful (§6.12). |
| `DefaultTeamResolver.php` | Divergent (§6.9) — CoroutineContext. |
| `helpers.php` | Faithful (§6.10). |
| `Support/Config.php` | Faithful + `storageConnection()` (§6.5). |
| `Traits/HasRoles.php` | Spatie surface, cache-backed body (§6.14). |
| `Traits/HasPermissions.php` | Spatie surface + forbidden, cache-backed body (§6.15). |
| `Traits/HasAssignedModels.php` | Faithful (§6.16). |
| `Traits/RefreshesPermissionCache.php` | Faithful (§6.13). |
| `Models/Role.php`, `Models/Permission.php` | Divergent (§6.17) — `$guarded=[]`, config table/conn, direct-query finds, `is_forbidden` pivot. |
| `Contracts/*` | Faithful, `UnitEnum|string` (§6.6). |
| `Middleware/*` (3) | Faithful, `UnauthorizedException` (§6.18). |
| `Commands/*` (6) | Faithful (§6.19). |
| `Events/*` (4) | Faithful, simplified (§6.8). |
| `Exceptions/*` (11) | Faithful (§6.7); drop archived `PermissionException`/`RoleException`. |
| `PermissionServiceProvider.php` | Divergent (§6.20) — singleton concrete, gate/blade/macros/about, no Octane. |
| `config/permission.php` | Divergent (§6.2). |
| `database/migrations/*` | Divergent (§6.3) — config-driven, `is_forbidden`, composite unique, FKs. |
| Octane reset listener | Omitted (D14). |
| Cache alias-compression | Omitted (D15). |

---

*End of plan.*
