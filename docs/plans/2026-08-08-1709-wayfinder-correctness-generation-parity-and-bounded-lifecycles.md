# Complete Wayfinder correctness, generation parity, and bounded lifecycles

## Objective

Make Wayfinder generate deterministic, type-correct TypeScript whose route parameters,
defaults, bindings, verbs, files, and documentation truthfully match supported Hypervel
Routing behavior. Preserve Laravel's public Wayfinder shapes and Hypervel's request-scoped URL
origin, command cloning, worker-cached binding metadata, and static generated-fixture layout.

This is generation/tooling work, not a PHP request-path redesign. The PHP request-path changes
are confined to Routing URL generation: boolean normalization, binding-aware defaults remaining
authoritative for explicit null input, raw defaults being limited to non-domain roots, consumed
root fallbacks no longer leaking into the query, and forced-root/route placeholder collisions
failing instead of emitting contradictory URLs. Boolean normalization adds one primitive branch
per supplied URL parameter; the other corrections replace existing lookup/removal behavior and
add no I/O. Generated calls gain only the encoding and validation required for correct URLs. The
shared Filesystem correction changes atomic replacement's omitted-mode default from executable
to the ordinary file mode already produced by `put()`. The Foundation and Testbench lifecycle
corrections restore the documented in-memory configuration and route caches across successive
application boots without compiling routes before Testbench has defined them. They add no
production request-path work beyond honoring an explicitly bound route-cache marker.

## Evidence baseline

- Hypervel worktree baseline: `d80d05adfb38` on
  `audit/wayfinder-correctness-generation-parity`.
- Current upstream references checked during diagnosis: Laravel Wayfinder `ca10a4bf`, Laravel
  framework 13.x, and `@laravel/vite-plugin-wayfinder` `0.1.7` / `9492d25f`.
- Current upstream additions include multi-route JSDoc and stale route-cache deployment
  guidance. Historical pull requests are discovery only; port current source and prose.
- The reported findings were reproduced against current generator output, Routing, View,
  Filesystem, package manifests, TypeScript/Vitest configuration, and the Vite plugin.
- Existing `wayfinder-01` owns root package-provider discovery from the Permission work. It is
  revalidated here, not reused. Existing `routing-26` belongs to Translation; this work uses
  `routing-27`.

## Anti-overengineering rules

The following wording is retained verbatim from the core audit plan. Its principle numbering is
also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this
plan” refers to that plan's **Established remediation vocabulary** section.

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

### 7. Preserve hot-path quality

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

### 8. Remove superseded design completely

When a fix changes the owning model, delete obsolete helpers, callbacks, properties, config keys, comments, tests, and documentation. Do not leave a compatibility path or comment describing behavior that no longer exists. Preserve intentional upstream comments unless the new design makes them incorrect.

### 9. Treat remediation patterns as candidates

The established patterns later in this plan are a vocabulary, not a lookup table. Choose among per-call parameters, immutable values, scoped bindings, cloning, CoroutineContext, factories, explicit ownership, static reset, or resource teardown only after proving the real lifetime and owner.

### 10. Reject speculative complexity

Record low-confidence concerns under rejected or unresolved analysis. Do not implement them. Surface every evidence-backed, meaningful non-defect improvement to the owner with its benefit, cost, and alternatives, then stop for explicit approval. This requirement exists to keep worthwhile opportunities visible, not to discourage finding them.

## Retained architecture

- `GenerateCommand` remains a mutable per-invocation object; the console application already
  clones cached command prototypes. Add no reset hook or command registry.
- `BindingResolver` keeps bounded worker-static model/schema/PHPDoc metadata. It retains no
  request data and continues to expose one exhaustive `flushState()` called by Testing.
- View template registration stays command-owned so `Artisan::call()` works in request-context
  processes. Make the mutation idempotent; do not introduce provider ordering or scoped View
  machinery.
- The generated `urlDefaults` resolver is frontend boot configuration. Per-render SSR values
  are passed directly to generated calls; add no AsyncLocalStorage, reset hook, or second
  registry.
- Generated files remain per-invocation output. Publish changed files atomically, but add no
  whole-tree transaction, backup, retry, mutex, or PHP command lock.
- The ignored `.generated` tree remains deterministic output for static ESM imports.
- Vite plugin process scheduling remains owned by the external plugin; Components records the
  required upstream correction and carries no workaround.

## Findings and final decisions

| ID | Category | Severity | Final decision |
|---|---|---:|---|
| `wayfinder-01` | Root discovery | Minor | Revalidate the existing root `WayfinderServiceProvider` discovery and its generic manifest test. |
| `wayfinder-02` | URL-default identity | Major | Resolve binding-aware default identity once; separate caller-optional arguments from route-optional segments, and validate only after defaults and binding keys resolve. |
| `wayfinder-03` | Middleware parsing | Major | Replace the unsafe first-token reader with a bounded linear parser for every `URL::defaults()` array call. |
| `wayfinder-04` | Generated URL fidelity | Major | Use one backend-equivalent path/domain scalar formatter and make booleans reachable through every generated call shape. |
| `routing-27` | Routing URL defaults | Major | With owner approval for the expanded public scope, normalize booleans, make binding-aware route defaults authoritative, confine raw defaults to non-domain roots, prevent consumed root defaults from leaking into queries, and reject forced-root/route placeholder collisions. |
| `wayfinder-05` | Identifier allocation | Major | Derive actual barrel scopes from flat manifests, preserve and merge leaf/namespace pairs, allocate deterministic declaration names without inspecting output, retain raw controller method keys, and retain camel-normalized barrel keys with raw fallback only for normalization collisions. |
| `wayfinder-22` | Output path collision | Major | Reject a controller module whose path collides with its scope's `index.ts` barrel on case-insensitive filesystems. |
| `wayfinder-06` | Same-URI verbs | Major | Coalesce compatible same-action/same-URI routes into one stable multi-verb definition; reject incompatible default metadata. |
| `wayfinder-07` | Query arrays | Minor | Permit boolean array members and serialize them through the existing scalar normalizer. |
| `wayfinder-08` | Publication/pruning | Major | Preserve no-op writes, atomically replace changed files, and fail truthfully when stale files remain. |
| `filesystem-17` | Atomic file mode | Minor | With owner approval, make mode-less atomic replacement create ordinary non-executable files instead of setting every executable bit allowed by the umask. |
| `wayfinder-09` | View state | Minor | Replace the namespace instead of appending it on every command; retain idempotent extension registration. |
| `wayfinder-10` | Verification gates | Major | Add bounded TypeScript, normal/cached Vitest, and CI gates without putting Node into Composer workflows. |
| `wayfinder-11` | Current parity | Minor | Port current multi-route JSDoc and document the URI-keyed dictionary. |
| `wayfinder-12` | Runtime metadata | Minor | Add only direct split requirements and move the parser to root runtime requirements with package-specific assertions. |
| `wayfinder-13` | Binding inference | Major | Prefer primitive cast evidence before schema/PHPDoc evidence; keep bare bigint numeric and make decimals strings. |
| `wayfinder-14` | Test/build isolation | Minor | Give PHP and Node scratch files exact parallel-safe ownership and remove shell interpolation from fixture generation. |
| `wayfinder-15` | Output path | Major | Reject exactly an explicitly empty `--path=` before the first filesystem operation. |
| `wayfinder-16` | External Vite plugin | Major | Track a plugin-owned context, argv, Windows-path, and single-flight correction for an upstream PR. |
| `wayfinder-17` | Public guidance | Minor | Add a Laravel-prose guide, current README/index/skill guidance, deploy ordering, and safe SSR default usage. |
| `wayfinder-18` | Source metadata | Minor | Preserve relative controller paths only inside the application base; retain normalized absolute paths outside it. |
| `wayfinder-19` | Tuple contract | Major | Emit optional labels only for the contiguous optional suffix supported by index-based tuple unpacking. |
| `wayfinder-20` | TypeScript cleanup | Major | Scope syntax spacing rules to syntax fragments and anchor line rules so developer literals are never rewritten. |
| `wayfinder-21` | Null omission | Major | Type optional null values consistently and prevent bound-parameter shorthand and member extraction from dereferencing null. |
| `foundation-19` | Cached test state | Major | Re-arm cached configuration and routes before each opted-in application boot across Foundation and Testbench, and restore the route marker read. |
| `testbench-05` | Cached route lifecycle | Major | Register the standard route provider and define Testbench routes immediately after application creation so cached-route callbacks are consumed and capture the complete collection. |
| `testbench-06` | Mixed route fixtures | Major | Keep uncached route synchronization independent from provider-owned cached files so a cached route followed by a stash route retains both. |
| `rate-limiter-01` | Cross-package test stability | Minor | Split exact leaky-bucket decisions from bounded recovery so continuous refill cannot make the shared store contract depend on subsecond scheduling. |
| `testing-17` | PHPUnit guidance | Minor | Replace the removed PHPUnit test-history option in the parallel-testing guide; leave ParaTest's worker argument to its existing upstream fix. |

## Implementation

### 1. Resolve URL defaults once and parse middleware safely

Keep raw global and middleware entries until a `Route` knows the placeholder and binding field.
Drop global `null` and unsupported values before merging; middleware parsing omits literal null
but retains a recognized dynamic expression as its `null` sentinel. Normalize the effective
entries into a map keyed by route parameter name:

```php
$globalUrlDefaults = collect(URL::getDefaultParameters())->filter(
    fn (mixed $value): bool => $value instanceof UrlRoutable
        || $value instanceof BackedEnum
        || is_scalar($value),
);

/** @var Collection<string, string|int|float|bool|null> $defaults */
$lookup = $field === null ? $name : "{$name}:{$field}";

if (! $rawDefaults->has($lookup)) {
    continue;
}

$value = $rawDefaults->get($lookup);
$value = match (true) {
    $value instanceof UrlRoutable && $field !== null => $value->{$field},
    $value instanceof UrlRoutable => $value->getRouteKey(),
    $value instanceof BackedEnum => $value->value,
    default => $value,
};

// Routing unwraps enum-valued binding fields before final URL formatting.
if ($field !== null && $value instanceof BackedEnum) {
    $value = $value->value;
}

if ($value !== null && ! is_scalar($value)) {
    continue;
}

$defaults->put($name, $value);
```

Middleware parsing may insert `null` only as the sentinel for a recognized dynamic expression.
Literal/global `null` is omitted, so `has($name)` means the parameter can be supplied by a
compile/runtime default, while `get($name) !== null` means a static fallback is safe to embed.
Cache the normalized scalar map on the command-local `Route` wrapper and use it from both
`parameters()` and `uri()` so `{team:slug}` consistently becomes optional `{team?}`.
Widen `Parameter::$default` from `?string` to `string|int|float|bool|null`; all static defaults
are normalized before construction, and the existing Blade JSON rendering already preserves
their scalar types. Keep `Parameter::$optional` as the caller-facing fact: the argument may be
omitted because the route declares it optional or a URL default exists. Add
`Parameter::$routeOptional` for the distinct URI fact that the original route declares
`{name?}`. A default-backed required segment may be omitted by the caller, but it must resolve
before rendering and may not disappear from the URL.

Replace `extractUrlDefaults()` with one index-based tokenizer that:

```php
for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
    if (! $this->startsUrlDefaultsCall($tokens, $index)) {
        continue;
    }

    [$entries, $index] = $this->readDefaultsArray($tokens, $index);
    $defaults = [...$defaults, ...$entries];
}
```

The bounded reader must:

- compare token IDs directly (`T_STRING`, not `token_name()`);
- prove every look-ahead/back index before reading it;
- depth-match `[...]` and `array(...)`, nested arrays, calls, and parentheses;
- accept literal string keys only and ignore numeric/dynamic keys;
- decode quoted strings, signed integers/floats, and booleans;
- omit literal null and unsupported literal values;
- map a non-literal value expression to the dynamic `null` sentinel;
- process every `URL::defaults()` call without harvesting neighboring arrays.

Do not evaluate PHP or add an AST dependency. Remove the old parser state and misleading
single-call comments completely.

### 2. Make Routing's URL-default owner internally consistent

In `UrlGenerator::formatParameters()`, normalize booleans beside `UrlRoutable` values:

```php
foreach ($parameters as $key => $parameter) {
    $parameters[$key] = match (true) {
        $parameter instanceof UrlRoutable => $parameter->getRouteKey(),
        $parameter === true => '1',
        $parameter === false => '0',
        default => $parameter,
    };
}
```

In `RouteUrlGenerator::formatParameters()`, initialize every unresolved named route slot and
then unconditionally remove its same-name input before query classification. This prevents an
explicit null from re-entering as query data and overwriting the binding-aware default:

```php
$namedParameters[$name] = '';
unset($parameters[$name]);
```

Hoist root and URI replacement before `format()` because both consume `$parameters` by
reference and root replacement must remain first:

```php
$root = $this->replaceRootParameters($route, $domain, $parameters, $defaultParameters);
$uri = $this->replaceRouteParameters($route->uri(), $parameters, []);
$uri = $this->addQueryString($this->url->format($root, $uri, $route), $parameters);
```

Only non-domain roots may receive raw defaults. Route URI and route-domain placeholders are
already route parameters resolved through the binding-aware normalizer:

```php
return $this->replaceRouteParameters(
    $root,
    $parameters,
    $domain === null ? $defaultParameters : [],
);
```

Rename the narrowed arguments on `replaceRouteParameters()` and `replaceNamedParameters()` to
`$rootDefaultParameters`. Add one concise WHY comment at the non-domain call. The nonempty input
branch has already returned before the fallback, so pull the remaining null/empty same-name input
immediately before returning the root default:

```php
if (isset($rootDefaultParameters[$m[1]])) {
    Arr::pull($parameters, $m[1]);

    return $rootDefaultParameters[$m[1]];
}
```

This prevents `?tenant=` from leaking without discarding a meaningful explicit value.

Preserve protected visibility and native types. The agreed internal parameter-name clarification
has no in-repository overrides or callers. A forced-root placeholder colliding with a route
placeholder now throws `UrlGenerationException`; current behavior can put the explicit value in
the host and a different default in the path, so preserving it would require incoherent special
machinery. Boolean normalization is already approved; the binding/default/root corrections also
change observable behavior on Laravel's public URL-generation surface and require the owner's
approval with this expanded plan.

### 3. Format generated route scalars once

Widen the route scalar domain to `string | number | boolean` at each real early return in
`Parameter::resolveTypes()`:

```php
if (! $this->bound) {
    return ['string', 'number', 'boolean'];
}

$model = Reflector::getParameterClassName($this->bound);

if (! $model) {
    return ['string', 'number', 'boolean'];
}

[$types, $this->key] = BindingResolver::resolveTypesAndKey($model, $this->key);

return $types === [] ? ['string', 'number', 'boolean'] : $types;
```

```ts
if (
    typeof args === "string" ||
    typeof args === "number" ||
    typeof args === "boolean"
) {
    args = { parameter: args };
}
```

Remove `false` from `validateParameters()`'s missing set. Keep `undefined`, `null`, and `""`
as omission/empty signals. The central formatter reuses module-private `getValue()` for
`true -> "1"` and `false -> "0"`, rejects a missing/empty required value, permits optional
omission, encodes with `encodeURIComponent`, additionally encodes `'()`, and restores Routing's
allowed percent sequences in one regex/map pass:

```ts
export const formatRouteParameter = (
    value: string | number | boolean | null | undefined,
    optional: boolean,
    name: string,
) => {
    if (value === undefined || value === null || value === "") {
        if (optional) return "";

        throw Error(`Missing required route parameter: ${name}.`);
    }

    const encoded = encodeURIComponent(getValue(value)).replace(
        /['()]/g,
        (character) => `%${character.charCodeAt(0).toString(16).toUpperCase()}`,
    );

    return encoded.replace(/%(?:2F|40|3A|3B|2C|3D|2B|7C|3F|26|23|25)/g,
        (sequence) => routeCharacters[sequence],
    );
};
```

The restore map is explicit and typed; `%21` and `%2A` are absent because
`encodeURIComponent()` leaves `!` and `*` unescaped already:

```ts
const routeCharacters: Record<string, string> = {
    "%2F": "/", "%40": "@", "%3A": ":", "%3B": ";",
    "%2C": ",", "%3D": "=", "%2B": "+", "%7C": "|",
    "%3F": "?", "%26": "&", "%23": "#", "%25": "%",
};
```

Use it for path and domain placeholders through replacement callbacks, not replacement strings;
this also prevents JavaScript `$&`, `$\``, `$'`, and `$$` replacement-token corruption. Static
URI segments remain unchanged; no literal-segment encoder is added.
Export and import `formatRouteParameter` beside `applyUrlDefaults` for every parameterized
generated file, and reserve its name from generated declarations. Emit the parameter name as
the helper's third argument so required-parameter failures are actionable.

Construct `parsedArgs` before optional-gap validation, then validate that resolved map rather
than the raw arguments. Pass only `routeOptional` names to `validateParameters()` and pass
`routeOptional` to `formatRouteParameter()`. Compile-time defaults, frontend defaults, and
binding-key extraction therefore participate in validation; unresolved default-backed path or
domain values fail by name instead of collapsing a segment. Import `validateParameters` only
when a route has a `routeOptional` parameter.

Unbound boolean widening intentionally allows boolean conditional expressions just as current
numeric types allow `0`. Public guidance says optional omission uses `undefined`/`null`, e.g.
`condition ? value : undefined`.

Make that omission contract truthful in generated TypeScript. When a parameter is caller-optional,
append `| null` to its object-member value, tuple value, and direct single-parameter scalar value.
Do not widen binding-object members such as `{ id: null }`: that supplies an invalid binding key
rather than omitting the parameter. Let `applyUrlDefaults()` accept null in its existing generic
input shape. Guard the raw single-binding shorthand against null before `key in args`, and guard a
null parameter member before bracket access. Required null values must reach the named missing
route parameter error rather than a JavaScript `TypeError`.

### 4. Make tuple types match positional runtime behavior

An entry is tuple-optional only when it and every later parameter are optional:

```blade
{{ $parameter->safeName() }}{!! when(
    $parameters->slice($loop->index)->every->optional,
    '?',
) !!}: {!! $parameter->types !!}
```

This emits `[one?: T, two?: T]` for an all-optional suffix, while keeping leading URL-defaulted
entries required when a later positional entry is required. Object members remain independently
optional by key. This tuple rule continues to use caller-facing `optional`; URI segment removal
uses the separate `routeOptional` fact.

### 5. Scope TypeScript cleanup to syntax it owns

The cleaner's global plain replacements currently mutate valid developer route literals, and
the global `.replace` regex can inject a raw newline into a string literal, producing invalid
TypeScript. Move the six argument/type rules plus the closing-bracket rule into the existing
matched-fragment cleanup:

```php
$argumentReplacements = [
    ' ,' => ',', '[ ' => '[', ' ]' => ']', ', }' => ' }',
    '} )' => '})', ' )' => ')', '( ' => '(',
];

$clean = preg_replace('/\s+/', ' ', $match);
$clean = str_replace(array_keys($argumentReplacements), array_values($argumentReplacements), $clean);
$str = $str->replaceFirst($match, $clean);
```

Leave only newline-anchored plain rules global. Anchor return-expression regexes to generated
newlines:

```php
'/\n\s*\.replace/' => PHP_EOL . str_repeat(' ', 12) . '.replace',
'/\n\s*\+ queryParams\(options\)/' => ' + queryParams(options)',
```

Assert exact tuple syntax and exact preservation of representative literals such as
`[ draft ]`, `(bar )`, and `.replace`. Do not claim these unencoded static literals are
byte-identical to backend URLs.

### 6. Allocate names within their real TypeScript scopes

Create one private deterministic allocator used separately for each controller file, named-route
file/object, and barrel file. It is command-local input/output, not a registry. First derive and
count every natural candidate, preclaim candidates that are unique and not reserved, then assign
the remaining names in registration order:

```php
/**
 * Allocate names in registration order.
 *
 * @param Collection<int, array{name: string, createsForm: bool}> $candidates
 */
private function allocateNames(
    Collection $candidates,
    array $reserved,
    string $suffix,
): Collection
{
    $candidates = $candidates->values();
    $natural = $candidates
        ->map(fn (array $candidate): string => TypeScript::safeMethod($candidate['name'], $suffix));
    $counts = $natural->countBy();
    $naturalSet = array_fill_keys($natural->all(), true);
    $formShadowConflicts = [];
    $used = array_fill_keys($reserved, true);
    $allocated = [];

    foreach ($natural as $index => $candidate) {
        if ($candidates[$index]['createsForm'] && isset($naturalSet[$candidate . 'Form'])) {
            $formShadowConflicts[$candidate] = true;
            $formShadowConflicts[$candidate . 'Form'] = true;
        }
    }

    $available = function (string $candidate, bool $createsForm) use (&$used): bool {
        return ! isset($used[$candidate])
            && (! $createsForm || ! isset($used[$candidate . 'Form']));
    };
    $claim = function (string $candidate, bool $createsForm) use (&$used): void {
        $used[$candidate] = true;

        if ($createsForm) {
            $used[$candidate . 'Form'] = true;
        }
    };

    foreach ($natural as $index => $candidate) {
        $createsForm = $candidates[$index]['createsForm'];

        if ($counts[$candidate] === 1
            && $available($candidate, $createsForm)
            && ! isset($formShadowConflicts[$candidate])) {
            $allocated[$index] = $candidate;
            $claim($candidate, $createsForm);
        }
    }

    foreach ($natural as $index => $naturalCandidate) {
        if (isset($allocated[$index])) {
            continue;
        }

        $candidate = $naturalCandidate;
        $createsForm = $candidates[$index]['createsForm'];
        $suffixIndex = 2;

        while (! $available($candidate, $createsForm)) {
            $candidate = $naturalCandidate . $suffixIndex++;
        }

        $allocated[$index] = $candidate;
        $claim($candidate, $createsForm);
    }

    ksort($allocated);

    return collect($allocated);
}
```

Each candidate carries whether it actually declares a form helper; namespace aliases and barrel
imports do not reserve phantom `Form` names. Form-shadow discovery starts only from form-owning
candidates but compares against the complete natural-name set. A real method/form-shadow pair is
excluded from unique preclaim so registration order decides the natural export. Reserve:

- `queryParams`, `applyUrlDefaults`, `validateParameters`, `formatRouteParameter`;
- `RouteQueryOptions`, `RouteDefinition`, `RouteFormDefinition`;
- strict `eval`, `arguments`, existing reserved words, and the file's default binding;
- the invokable default binding's `Form` name only when form helpers are enabled;
- every derived `{allocatedName}Form` when form helpers are enabled.

Use allocated names consistently in imports, declarations, default objects, form helpers,
barrels, and multi-route temporary names. The latter hashes allocated method plus URI to keep
different-URI declarations distinct. Controller method properties retain the raw
`originalJsMethod()` through `quoteIfNeeded()`; do not add a second named-export alias. Remove
`quoteIfNeeded()`'s numeric shortcut so numeric-looking names cannot change value when parsed as
JavaScript keys. Convert `Stringable` before strict reserved-word membership, and make
`quoteIfNeeded()` handle empty/nonidentifier names. Render arbitrary model binding fields as
quoted member types and bracket access.

Derive controller and named-route barrel scopes directly from their flat grouped keys; do not
use `undot()`, which cannot represent a leaf and child namespace at the same key. Record leaf
modules/rendered methods and child namespaces as separate candidates in registration order and
allocate them together. The root scope owns top-level declaration allocation but retains the
current named-export-only surface; do not add a root aggregate or default export. Pure leaves
use the allocated identifier directly. When both exist,
import the namespace explicitly from `./Name/index` and expose
`Object.assign(leaf, namespace)` so callable leaves remain callable. Barrel properties retain
Laravel's camel-normalized segment key when it is unique in the actual scope. If distinct raw
segments normalize to the same key, expose every member through its raw segment instead; pass
both normalized and fallback keys through `quoteIfNeeded()`, and never expose internal allocator
suffixes as public keys. A child namespace named `index` likewise imports from `./index/index`.
Delete generated content inspection, hash aliases, self-import filtering, and the old suffix
loop.

Before buffering a controller module in a non-root scope, compare its path with that scope's
`index.ts` barrel using ASCII case-insensitive comparison. If they collide, throw
`InvalidArgumentException` naming the controller and both paths. The root actions scope has no
barrel and needs no guard, so a global `Index` controller remains supported. Do not rename a
nested module or add a path registry: two distinct files cannot be represented on macOS or
Windows without changing the generated import surface.

### 7. Coalesce compatible same-URI route entries

Before rendering a multi-route action, group entries by URI. For a group with the same resolved
parameter/default descriptor, merge verbs in registration order and deduplicate them:

```php
$verbs = $sameUriRoutes
    ->flatMap(fn (Route $route) => $route->verbs())
    ->unique(fn (Verb $verb) => $verb->actual)
    ->values();
```

Preserve stable registration order, so separately registered GET then POST routes coalesce as
`get, head, post`. This may differ from `Route::match(['get', 'post'])`, whose constructor appends
HEAD after the supplied methods and therefore reports `get, post, head`; only the first/default
callable selection is equivalent. If middleware produces different resolved defaults for the
same action/URI, throw a generation exception naming the action, URI, and differing metadata.
Use Symfony Console's `InvalidArgumentException` so this route-configuration error renders as a
normal command failure. Do not add verb-qualified dictionary keys or a public registry.

Port current upstream multi-route JSDoc into `multi-method.blade.ts`; the public guide explains
that the action becomes a URI-keyed dictionary.

### 8. Correct query arrays, publication, View mutation, and source paths

Make query arrays accept and normalize booleans:

```ts
| (string | number | boolean)[]

queryValue.forEach((value) => {
    params.append(`${key}[]`, getValue(value));
});
```

Keep equivalent PHP/JS array-index syntax out of public docs; promise endpoint/path behavior,
not byte-identical complete query strings.

Publish only changed content through the existing throwing atomic API:

```php
$this->files->ensureDirectoryExists(dirname($path));

if (! $this->files->exists($path) || $this->files->get($path) !== $content) {
    $this->files->replace($path, $content);
}
```

Let `writeContentIfChanged()` own directory creation for both direct and buffered writes. Its
callers do not repeat the same `ensureDirectoryExists()` operation.

Correct `Filesystem::replace()` itself so an omitted mode uses the ordinary file default rather
than making every generated, compiled, cache, and source file executable:

```php
$mode ??= 0666 & ~umask();
```

Keep explicit modes unchanged. This is one shared primitive correction, not a Wayfinder-only mode
override; focused Filesystem coverage pins mode-less creation and replacement as non-executable
under the active umask, while the Wayfinder publication test checks the generated file mode.
It does not preserve an existing file's mode: mode-less replacement still creates a new inode and
resets its mode, but resets it to the ordinary file default. Callers that need preservation keep
passing the existing mode explicitly. Because this changes observable behavior on Laravel's
public `Filesystem::replace()` API, implementation requires the owner's approval with this plan.

Use strict `in_array(..., true)` for buffered fragments. On stale deletion, throw when
`delete()` is false and the path still exists; a concurrently disappeared path is success.
Keep empty-directory pruning best effort.

Keep View setup in `handle()` and make it idempotent:

```php
$this->view->replaceNamespace('wayfinder', __DIR__ . '/../resources');
$this->view->addExtension('blade.ts', 'blade');
```

Remove the dead `?? false` from touched `with-form` option reads.

Normalize controller paths, strip the application base only at a complete directory boundary,
and otherwise retain the absolute path:

```php
$path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
$base = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', base_path()), '/');

return str_starts_with($path, $base . '/')
    ? substr($path, strlen($base) + 1)
    : $path;
```

### 9. Use truthful binding-type evidence

Refactor `BindingResolver` to return a list of raw primitive evidence types and the binding key.
For a resolved Eloquent model field, use this order:

```php
$types = self::primitiveCastTypes($model->getCasts()[$key] ?? null)
    ?: self::schemaTypes($model, $key)
    ?: self::phpDocTypes($model, $key);
```

`Model::getCasts()` normalizes accepted stringable declarations before publication, so
`primitiveCastTypes()` accepts only `?string` and performs no redundant string cast.

- Retain the existing complete mapping table: `int`, `integer`, `bigint`, `int4`, `int8`,
  `serial`, `bigserial`, `number`, `float`, and `double` map to `number`; `string`,
  `text`, `varchar`, `char`, `json`, and `jsonb` map to `string`; and `bool`/`boolean` map to
  `boolean`.
- Add Eloquent's `real` cast synonym to `number`.
- Normalize ordinary cast arguments (`decimal:2` -> `decimal`) and move `decimal` to `string`;
  PDO and Eloquent decimal casts expose strings.
- Keep bare schema `bigint`/`int8` as `number`; Wayfinder cannot repair precision already lost
  at JSON serialization.
- Parse recognized PHPDoc primitive unions, drop null, and deduplicate mapped TypeScript types.
- Fall back to PHPDoc when schema exists but lacks the target field, not only when the schema
  query throws.

Use strict membership throughout. Keep bounded booted model, schema, docblock, parser, and lexer
caches; extend the existing `flushState()` for every new static slot. Add no custom-cast
reflection or generic type framework.

### 10. Reject unsafe output paths and make the frontend gates real

Reject only an explicitly present empty path before `writeWayfinderHelperFile()`:

```php
use Symfony\Component\Console\Exception\InvalidArgumentException;

if ($this->option('path') === '') {
    throw new InvalidArgumentException('The --path option may not be empty.');
}
```

Use Symfony Console's exception so the command renders a normal CLI error instead of an
uncaught stack trace. An explicit `/` remains trusted CLI intent.

Use exact scratch ownership:

- PHP filesystem tests: `ParallelTesting::tempDir()` with setup/teardown deletion;
- Node app-root tests: `mkdtempSync(join(tmpdir(), 'wayfinder-'))` with `finally` cleanup;
- shared `.generated`: unchanged, ignored, deterministic;
- fixture generation: `execFileSync('php', [script, output], { stdio: 'inherit' })`.

Add native `: void` return types to every touched test method in
`tests/Wayfinder/PruneStaleFilesTest.php` while converting its scratch ownership and extending
its publication coverage.

Add `src/wayfinder/tsconfig.json` covering runtime, tests, and generated fixtures under strict
`noEmit` with bundler module resolution, matching the extensionless generated imports. Add
package/root scripts for normal Vitest, cached-route Vitest, and typecheck. Cached mode compiles
the registered fixture routes and installs them through the real API before generation. Narrow
the collection before calling its concrete cache compiler so a changed fixture lifecycle fails
with a clear invariant error:

```php
if (getenv('WAYFINDER_CACHE_ROUTES') === '1') {
    $routes = $router->getRoutes();

    if (! $routes instanceof RouteCollection) {
        throw new RuntimeException('Cached-route generation requires an uncompiled RouteCollection.');
    }

    $router->setCompiledRoutes($routes->compile());
}
```

Restore the documented Foundation testing caches at their shared owning lifecycle. Both cache
traits capture state after the first application has booted and clear their bootstrap callbacks
at teardown, while `CachedState` intentionally retains the arrays. Add one protected Foundation
`TestCase` seam that stores `class_uses_recursive(static::class)` and directly re-arms non-null
cached config/routes for test cases using the matching traits. Call it after application creation
but before bootstrap in Foundation `TestCase::createApplication()`. In the reusable Testbench
`CreatesApplication` concern, call it at the same point only when `$this` is a Foundation
`TestCase`; standalone application factories do not own PHPUnit trait caches. Reuse the identity
trait map from `setUpTraits()` and remove its no-op `array_flip()`.

Widen both protected marker methods to the application contract. Keep `routes.cached`, restore
its read at the start of `Application::routesAreCached()`, and retain Hypervel's non-memoizing
filesystem fallback. This marker makes both `RouteServiceProvider` and Testbench route setup
recognize the compiled collection. Register the cached route callback with an injected `Router`;
do not capture a discarded application, add a provider-specific gate, or make production
bootstrap classes read testing state directly.

Testbench currently registers routes only from an `afterApplicationCreated` callback, after
Foundation has already run `setUpTraits()` and compiled `WithCachedRoutes`. Move only
`setUpApplicationRoutes()` into Testbench's existing `refreshApplication()` override, immediately
after `createApplication()` returns with providers booted. Leave timer and worker-exit coordinator
cleanup in the existing callback: `refreshApplication()` also has supported mid-test callers, and
runtime cleanup is not application construction. Move the route comment with the call, and use
the supplied application parameter consistently while parsing route attributes. This makes every
fresh Testbench application complete before trait setup and keeps cached reloads on their existing
early-return path.

Testbench registers the framework default providers, but the application route provider is not a
default provider in Laravel or Hypervel. Add `RouteServiceProvider::class` to Testbench's final
resolved provider list before package providers and before provider overrides. Do not add
`withRouting()` to the committed default skeleton: in-process default-skeleton applications are
constructed directly and never execute that bootstrap file, while WithWorkbench already calls
`withRouting()`. Application provider registration deduplicates the two WithWorkbench paths, and
the later forced builder registration preserves its configured routing callback.

The route provider now owns loading disk-cached routes. Delete the duplicate cached-file require
from `HandlesRoutes`; do not leave a collection-type guard around dead work. Rename the remaining
internal seam and flag to `syncTestbenchRoutes()` / `$syncTestbenchRoutesHasRun`, remove their
unused arguments, and call the seam only after Closure normalization has forced `$cached = false`.
Narrow `$this->app` to the application contract inside its after-created callback. This leaves
uncached route files synchronized exactly once while allowing a prior cached route call followed
by a stash route call to perform the synchronization it needs. Keep the upstream-owned
`SyncTestbenchCachedRoutes` class name because the CLI skeleton still uses it.

Add a separate non-matrix Wayfinder job to `.github/workflows/tests.yml`; do not repeat Node work
inside the PHP/Swoole matrix. The job uses the repository's PHP 8.4/Swoole 6.2 container and PHP
extension setup, installs Composer dependencies for fixture generation, installs Node 22 and
pnpm 10.24, installs the frozen workspace lock, then runs all three Wayfinder checks. Keep Node
out of `composer fix` and package runtime installation; add no ESLint.

The shared rate-limiter store contract must not expect a multi-call leaky-bucket sequence to
finish within one 500 ms emission interval. Test exact decisions and non-mutating denials with a
slow `LeakyBucket::perMinute(2)->burst(3)` policy, clearing its stable physical key before and in
exception-safe cleanup. Test recovery separately with a fast policy emptied by one atomic
cost-three consume, then retain the bounded full-capacity poll. Add no clock seam, sleep, or
tolerance assertion.

### 11. Correct package metadata without a false repository invariant

Keep existing split dependencies and add only direct missing requirements:

```json
{
    "ext-tokenizer": "*",
    "hypervel/reflection": "^0.4"
}
```

Do not add `ext-json` or `ext-hash`, which are guaranteed by Hypervel's PHP floor, or
`hypervel/foundation`. Move
`phpstan/phpdoc-parser` from root `require-dev` to root `require`; it is used by production
Wayfinder generation and the root Facade Documenter bin. Move it through Composer rather than
hand-editing the root manifest:

```shell
composer remove --dev phpstan/phpdoc-parser
composer require 'phpstan/phpdoc-parser:^2.3'
```

Edit the split manifest directly, then run Composer update/validation as needed; do not commit
the ignored root lock.

Create `tests/Wayfinder/PackageMetadataTest.php` for direct split requirements and root parser
placement. Compare shared external constraints with the root manifest and derive internal
package constraints from the split package's branch alias; do not freeze copied version strings
in tests. Extend Facade Documenter's metadata test with the production-bin reason, and replace
the same frozen-version assertions in existing package metadata tests with relationship-based
assertions. Revalidate `wayfinder-01` root discovery. Do not globally require split `require`
entries to live in root `require`: Testbench and Testing intentionally expose
PHPUnit/Mockery/YAML as split runtime dependencies while the monolith owns them as dev tools. A
full manifest check found no other production split/root-section mismatch.

### 12. Complete public guidance and external ownership

Add `src/boost/docs/wayfinder.md` in Laravel-docs prose and index it in
`src/boost/docs/documentation.md`. Cover only user tasks:

- installation and Vite plugin setup;
- generated directories, command flags, and committed/ignored ownership;
- `route:clear` before frontend build when deployment may carry a prior route cache;
- controller and named-route imports;
- scalar, object, tuple, and model binding parameters;
- multiple routes to one action, verbs, and form helpers;
- `query` and `mergeQuery`;
- compile-time middleware/global defaults and boot-time frontend defaults;
- optional omission with `undefined`/`null`;
- Inertia usage;
- request-specific SSR values passed directly per call, not through per-render
  `setUrlDefaults()` mutation;
- Hypervel's request-scoped forced-origin difference.

Keep `src/wayfinder/README.md` minimal: header, documentation link, lasting forced-origin
difference, upstream link. Do not narrate fixes or implementation details. Correct the Boost
skill query example to `show.url(...)`; preserve roster calls until the proper Boost port. Extend
the existing Boost todo so that port includes the already-shipped Wayfinder and Horizon skill
templates.

Update the parallel-testing warning to name PHPUnit's replacement
`--do-not-record-test-run-history` option. ParaTest 7.24 still passes the deprecated predecessor
once per worker; upstream issue #1126 and PR #1127 own that non-failing runner warning. Do not add
a downgrade, suppression, vendor patch, Composer patch layer, fork, compatibility branch, or hard
PHPUnit-deprecation gate.

Record one external Vite-plugin todo in `docs/todo.md` requiring its upstream PR to:

```text
capture hook context per plugin instance; normalize every Windows separator; tokenize the
documented multiword command once into argv (quotes, no shell operators/expansion); execute the
program and generated arguments without shell re-parsing; serialize runs; collapse a burst to
one follow-up; recover after failure; keep plugin instances isolated.
```

The public command-option narrowing needs upstream agreement. Components adds no plugin shim,
lock, retry, or scheduler. Atomic per-file publication does not prevent an older whole generator
run from finishing last.

## Regression plan

### PHP / generated-output coverage

- Defaults/parser: scalar values, signed numbers, floats, booleans, literal null, dynamic
  expressions, short/long/nested arrays, multiple calls, comments/whitespace, unrelated arrays,
  numeric keys, bound keys, `UrlRoutable`, BackedEnum, domain defaults, URI rewriting, validation
  after static/frontend default resolution, and unresolved default-backed path/domain failures.
- Routing: explicit/positional/path/domain/default true/false; bound default with explicit null;
  plain-only default for `{team:slug}` throws; forced-root omitted/null/empty/value without query
  leak; route-domain defaults; root/route placeholder collision throws.
- Formatting: spaces, Unicode, quotes, parentheses, Routing-restored characters, `$&`, `$\``,
  `$'`, `$$`, named required-parameter errors, optional omission, exported/imported helper
  wiring, and backend-equivalent substituted path/domain output.
- Booleans: direct scalar, object, tuple, optional before a later optional, compile-time default,
  domain, and PHPDoc-resolved bound boolean.
- Tuple types: short trailing optionals, all-optional mixed cause, and existing interleaved
  default-before-required fixtures remaining required and valid.
- Optional gaps: ordinary trailing omission, interior gaps, explicit empty default-backed
  values, missing binding keys after object extraction, direct/object/tuple optional null values,
  bound-member null, static default resolution from null, and required bound null failing by name.
  Typecheck negative fixtures for required null and null nested binding keys so the type surface is
  not widened beyond omission positions.
- Cleanup: exact tuple text and preserved developer literals for every relocated rule, including
  the `.replace` SyntaxError reproduction.
- Names: strict words, natural suffix collision, hyphen/camel collision, runtime import names,
  form helper collision in both registration orders with the earlier route retaining its natural
  export, default export, named/barrel objects, empty/nonidentifier fields, and deterministic
  repeat generation. Cover controller and named leaf/namespace pairs in both orders through
  runtime barrel imports, the existing `storage.export` pair, a child namespace named `index`,
  and pure leaves without no-op `Object.assign`. Pin mixed namespace/method `Form` ownership in
  both orders, real method-to-alias form shadows, invokable default-form collisions, and the
  absence of a phantom default-form reservation for non-invokable controllers and barrels.
  Keep the existing `taskStatus` camel-key contract unchanged and pin its bare source form. Prove
  all leaf members and child namespaces under a non-root aggregate remain reachable when
  distinct raw segments normalize to one key, unrelated siblings retain their normal key,
  numeric-looking keys retain their exact value, and repeat generation keeps the same keys and
  bytes. Reject a controller leaf named `index` in any casing when its module would collide with
  the scope barrel. Retain the root named-export-only surface rather than adding a default
  aggregate.
- Same URI: separate GET/POST registration order, HEAD dedupe, distinct `Route::match()` order,
  equivalent default callable, stable output, and differing middleware defaults failing clearly.
- Files/View/path: unchanged write avoidance, atomic changed write, ordinary non-executable mode
  under the active umask, explicit modes applied unchanged, false write/delete, concurrent
  disappearance, environment encryption using the same deterministic ordinary-file mode, two
  command invocations without hint growth, inside/outside/sibling controller paths, explicit
  empty path before writes.
- Binding: cast precedence, decimal cast/schema, bare bigint, nonincrementing string key type
  without a cast remaining schema-driven, field-specific PHPDoc fallback and unions, boolean
  docblock, enum-valued binding fields, and flush of every cache.
- Metadata: direct split requirements, root/split constraint relationships, production Facade
  Documenter bin, and root provider discovery.

### Frontend / cached-route coverage

- Normal Vitest for generated runtime behavior and output imports.
- Cached-route Vitest for named routes and controller actions through
  `CompiledRouteCollection`.
- `tsc --noEmit` for every generated collision, tuple, boolean, form, and binding-key fixture.
- Repeat generation to prove deterministic byte output.
- Foundation and Testbench regressions boot successive applications with `WithCachedConfig` and
  `WithCachedRoutes`, prove the first cache includes Testbench-defined routes, prove the second
  boot uses the retained arrays without redefining routes, and prove lookup refresh leaves the
  `CompiledRouteCollection` intact. Exercise `defineCacheRoutes(Closure, false)` so a reloaded
  application defines and dispatches its uncached route, and run every existing mid-test
  `refreshApplication()` consumer. Pin that cached mode leaves the uncached-sync flag false,
  uncached mode sets it true, a cached route followed by a stash route keeps both dispatchable,
  provider-owned cached-file loading works, the provider remains removable through Testbench's
  provider-override API, and WithWorkbench registers one route provider and loads its route once.
- Rate Limiter: exact leaky-bucket decisions and non-mutating denials under a slow policy, plus
  independently bounded full-capacity recovery under a fast policy across every store contract.

### Commands

Run changed PHP test files immediately, then the complete Wayfinder PHP group. Run package
Vitest, cached Vitest, and typecheck after each coherent frontend slice. At the final checkpoint:

```shell
composer fix
pnpm test:wayfinder
pnpm test:wayfinder:cached
pnpm typecheck:wayfinder
```

After review amendments, rerun the affected targeted gates; repeat the full gate only when the
change can affect it.

## Records and completion

- Amend `docs/plans/2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md` with
  the complete Wayfinder entry, `wayfinder-02`–`wayfinder-22`, revalidated `wayfinder-01`, and
  cross-package `routing-27` and `filesystem-17`.
- Amend the core plan dependency index for `routing-27`, `filesystem-17`, `foundation-19`,
  `testbench-05`, `testbench-06`, `rate-limiter-01`, `testing-17`, and the Wayfinder findings. Record `routing` as owner and `wayfinder` as
  affected by `routing-27`, and add the revalidation note to Routing's completed ledger entry.
  Record `filesystem` as owner and `view`,
  `di`, `database`, `foundation`, `testbench`, and `wayfinder` as affected by `filesystem-17`, and
  add the corresponding revalidation notes to their completed ledger entries. Record `foundation`
  as owner and `testbench` as affected by `foundation-19`. Record `testbench` as owner and
  `wayfinder` as affected by `testbench-05` and `testbench-06`. Record `rate-limiter` as owner of
  `rate-limiter-01`. Record `testing` as owner of `testing-17`, and revalidate Wayfinder under
  `testing-16` without restoring guaranteed-core extension requirements. Mark Wayfinder complete only after implementation,
  all gates, self-review, code-review signoff, and bookkeeping are complete.
- Do not rewrite the historical completed Routing plan. The current core ledger and this
  Wayfinder plan own the later correction.
- Remove superseded code/comments/docs; leave no in-repository workaround or untracked accepted
  defect. The two explicit todos are external ownership: the Vite-plugin PR and the proper Boost
  port that will consume existing skill templates.

## Explicitly rejected machinery

- AST/eval/general PHP expression parsing;
- a process-global identifier registry, configurable naming system, or new alias API;
- verb-qualified public route dictionaries;
- whole-tree transactions, backup trees, retries, PHP command locks, or staging publication;
- provider/scoped View registration or per-run namespace scans;
- generic custom-cast/schema inference or bigint widening for precision already lost upstream;
- broad path containment or forced-root parity;
- moving `.generated`, adding ESLint, or putting Node into Composer gates;
- AsyncLocalStorage, frontend reset hooks, or a second URL-default registry;
- Components-side Vite scheduling or shell compatibility shims;
- ParaTest downgrades, warning suppression, vendor patches, Composer patch layers, or forks;
- documenting internal query encoding or claiming byte-identical complete URLs.
