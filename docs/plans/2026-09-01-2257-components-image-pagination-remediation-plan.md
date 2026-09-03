# Image and Pagination Audit Remediation Plan

## Status

Implementation, repository verification, and the six planned commits are complete. The only remaining branch work is this follow-up plan correction.

## Outcome

Resolve audit findings #84–87 and #90–92, reject measured or stale findings #83 and #89, and correct the package-metadata defects exposed by the required standalone install check. The finished code must:

- report HEIC/HEIF dimension failures without falling back to an inaccurate native reader;
- reject invalid image dimensions, effect amounts, and output quality instead of silently changing caller input;
- preserve custom image-driver creator exceptions and Laravel's protected manager surface;
- document image processing as CPU-bound work during which the worker cannot run other coroutines, without advertising an unsupported task-worker API;
- remove duplicate cursor state and make direct simple-paginator construction read and write the same custom page name;
- remove Pagination's direct Database and HTTP requirements while declaring its real Filter dependency and documented resource-conversion and link-rendering suggestions;
- declare the disableable Filter extension in every split package that directly uses it;
- replace the repository's stale Mockery development-branch pins with a stable constraint so stable consumer projects can resolve split packages;
- preserve all Laravel APIs and add no worker-lifetime state, request-time I/O, locks, compatibility fallbacks, or hot-path probes;
- leave one authoritative detailed plan by retiring findings #83–87 and #89–92 from the master ledger.

## Settled decisions and evidence

| Area | Decision |
|---|---|
| MIME handle reuse (#83) | Reject the proposed worker-static `finfo` helper. Reusing the raw handle saved only 0.42–0.62 microseconds per detection; constructing it alone cost about 0.21 microseconds against roughly 15 microseconds for detection and millisecond-scale image processing. A benchmark of the complete static-helper path showed no meaningful repeatable gain. Two direct one-line call sites do not justify a helper, retained state, cleanup seam, or dedicated tests. Do not describe the noisy complete-path run as proof that caching is slower. |
| HEIC dimensions (#84) | HEIC/HEIF dimensions remain driver-owned because `getimagesizefromstring()` can report coded or padded frame dimensions. Resolve the selected driver before the decode `try`; wrap only a driver `dimensions()` exception, preserve it as `previous`, and never call the native reader for these MIME types. Type errors and driver-resolution failures retain their native diagnostics. |
| Image validation (#85) | Validate once at the public immutable `Image` boundary. Width and height must be positive when supplied; blur/sharpen accept 0–100; quality accepts 1–100. Negative crop offsets remain valid. Use the existing `ImageException` family, preserving current resize/scale argument-failure behavior. Do not duplicate guards in DTOs or drivers. |
| Image driver creation (#86) | A minimal preflight preserves the existing image-specific unknown-driver message without catching exceptions thrown by registered creators. Let an invalid custom return fail naturally at the typed `Driver` boundary; a second result-type guard would duplicate PHP's error. Apply transformation handlers only after successful creation. |
| Image workload docs (#87) | Decode, transformation, and encode are synchronous CPU work on the event worker. Recommend queued jobs for expensive or batch processing. Hypervel has task-worker receive-side bootstrapping but no public task dispatch API, so do not recommend task workers or invent numeric thresholds. |
| Pagination JSON docs (#89) | Reject as stale. Current Laravel and Hypervel simple paginators both include `current_page_url`; Hypervel's existing README accurately documents only the length-aware difference. Do not change the README or add duplicate serialization tests. |
| Cursor state (#90) | `AbstractCursorPaginator` owns `$hasMore`; remove the duplicate child declaration. Existing behavioral tests are sufficient; a reflection test would couple to implementation. |
| Page-name resolution (#91) | Keep `Paginator::setCurrentPage(?int): int` unchanged and pass `$this->pageName` from inside it. Constructor options are applied first. Keep Hypervel's `??`, not Laravel's `?:`: an explicit invalid page such as `0` normalizes to `1` and must not unexpectedly invoke request resolution. This fixes the reproduced read-`page`/write-`users` mismatch without changing a protected extension point. |
| Pagination metadata (#92) | Remove direct Database and HTTP requirements, matching current `illuminate/pagination`; their source branches are conditional integrations. Add `ext-filter` because Filter remains build-disableable on supported PHP, unlike JSON. Suggest HTTP because the public, documented `toResourceCollection()` method needs it, and suggest View because the documented `links()` and `render()` methods need a concrete view implementation. Database needs no suggestion because a Model cannot exist without Database. Do not claim a minimal dependency closure: Support and Context still bring these packages transitively. |
| Filter metadata | Production source in 16 split packages directly calls Filter functions or constants without declaring the disableable extension. Add `ext-filter: *` to all 16 manifests. This is a Composer contract correction only; do not mirror every platform line in tests. |
| Stable Mockery resolution | Root, Prompts, Testing, and Testbench are the repository's four Mockery `1.6.x-dev` pins. A stable consumer cannot authorize a transitive dev constraint, so pagination's dependency path through Support, Foundation, and Testing cannot resolve. Mockery 1.6.15 is stable and no code or history requires unreleased behavior. Align all four owners on `^1.6.15`; keep Testbench's direct runtime requirement to match its Orchestra Testbench upstream. |
| Foundation/Testing TODO | The existing TODO already owns the production dependency cycle. Correct only its harm clause: Support's runtime Foundation dependency propagates Testing and Mockery to every package that depends on Support. Do not add a Support decomposition task or change the existing remedy. |

## Reference baseline

- `examples/laravel/framework/src/Illuminate/Pagination/composer.json` requires `ext-filter` and does not require Database or HTTP, although `AbstractCursorPaginator` conditionally recognizes Eloquent models, pivots, and JSON resources.
- Current Laravel `Paginator::setCurrentPage()` and `LengthAwarePaginator::setCurrentPage()` have different protected signatures. Hypervel keeps both signatures and fixes the simple paginator internally.
- Current Laravel simple paginator JSON includes `current_page_url`; #89 no longer describes upstream.
- The Image package is Hypervel-owned. Its public API already uses `ImageException` for missing resize/scale dimensions, unsupported formats, decode failures, and processing failures.
- Intervention's crop API accepts ordinary integer offsets, including negative coordinates. Only dimensions are positive.
- Filter is enabled by default but can be compiled with `--disable-filter`; JSON has no build toggle on supported PHP.
- `hypervel/collections` already suggests HTTP for the same `TransformsToResourceCollection` feature used by both paginator base classes.
- The root repository permits dev dependencies, hiding the Mockery pin. Stable consumer roots do not inherit dependency `minimum-stability` flags. Until 0.4 split packages are published, standalone verification must use local path or VCS repositories rather than old Packagist packages.

## Implementation

### 1. Correct image dimensions and argument validation (#84–85)

Files:

- `src/image/src/Image.php`
- `src/image/src/Transformations/Blur.php`
- `src/image/src/Transformations/Contain.php`
- `src/image/src/Transformations/Cover.php`
- `src/image/src/Transformations/Crop.php`
- `src/image/src/Transformations/Resize.php`
- `src/image/src/Transformations/Rotate.php`
- `src/image/src/Transformations/Scale.php`
- `src/image/src/Transformations/Sharpen.php`
- `tests/Image/ImageTest.php`

For HEIC/HEIF, resolve the driver outside the exception boundary and wrap only decode failure:

```php
$driver = $this->resolveDriver();

try {
    return $this->dimensions = $driver->dimensions($contents);
} catch (Exception $exception) {
    throw new ImageException(
        "Unable to determine the dimensions of the image: {$exception->getMessage()}",
        previous: $exception,
    );
}
```

Keep the existing native reader for every other MIME type. Do not catch `TypeError` or configuration/manager errors.

Replace clamps in `cover`, `contain`, `crop`, `resize`, `scale`, `blur`, `sharpen`, and `quality`. Two protected helpers are justified because dimension validation is shared by five methods and range validation by three:

```php
protected function ensureValidDimensions(?int $width, ?int $height): void;

protected function ensureValueIsBetween(string $name, int $value, int $minimum, int $maximum): void;
```

Protected visibility is deliberate: it lets subclasses impose a maximum-dimension policy at the shared boundary and keeps the pair consistent with Laravel's validation-helper convention for open classes. The helpers throw `ImageException` and return no adjusted value. Width and height failures use `Image width must be greater than zero.` and `Image height must be greater than zero.`. Range failures use `Image {$name} must be between {$minimum} and {$maximum}.`, with the names `blur amount`, `sharpen amount`, and `quality`. In `resize()` and `scale()`, keep the existing both-null check before dimension validation so calls with no dimensions retain their current specific message. `optimize()` continues to delegate to `quality()`, so it receives the same validation without another guard. Use `positive-int` for positive dimension PHPDocs, add `@throws ImageException` to all eight newly or already throwing public transformation methods (`cover`, `contain`, `crop`, `resize`, `scale`, `blur`, `sharpen`, and `quality`) and to the direct `process()` and `dimensions()` throwers, document the 1–100 quality range on both `quality()` and `optimize()`, and add `int<0, 100>` constructor PHPDocs to Blur and Sharpen. Every transformation constructor keeps a Laravel-style title docblock; DTO constructors remain passive typed values.

Update the existing tests rather than adding parallel test files:

- replace both `testEffectAndQualityValuesAreClamped` and `testDimensionTransformationsClampNonPositiveDimensions` with boundary and failure coverage;
- keep successful one-dimension resize/scale coverage and move the negative crop-offset assertion to a successful crop with positive dimensions;
- cover exact blur/sharpen boundaries and both invalid sides;
- cover exact quality boundaries and invalid quality through both `quality()` and `optimize()`;
- change the HEIC fallback test to assert the new message and exact previous exception;
- retain success, non-HEIC native reading, and uncaught driver `TypeError` coverage;
- prove an unsupported configured driver is not relabelled as a dimension decode failure.

### 2. Preserve image creator exceptions (#86)

Files:

- `src/image/src/ImageManager.php`
- `tests/Image/ImageManagerTest.php`

Mirror the parent manager's dispatch predicate before delegating:

```php
$method = 'create' . Str::studly($driver) . 'Driver';

if (! isset($this->customCreators[$driver]) && ! method_exists($this, $method)) {
    throw new InvalidArgumentException("Image driver [{$driver}] is not supported.");
}

/** @var Driver $instance */
$instance = parent::createDriver($driver);
```

Import `Hypervel\Support\Str`, then apply handlers and return. Remove the broad `InvalidArgumentException` catch. Do not validate the return twice.

Tests must prove the unknown-name message, identity-preserving propagation of a custom creator's `InvalidArgumentException`, natural `TypeError` for a non-Driver result, valid custom creation, and transformation-handler application.

### 3. Document synchronous image processing (#87)

File:

- `src/docs/images.md`

Refine the existing performance warning to explain that decode, transformation, and encode are CPU-bound work during which the worker cannot run other coroutines, and that expensive/batch work belongs in queued jobs. Document that supplied dimensions must be positive and use the fully qualified `Hypervel\Image\ImageException` on its first mention; later validation references use the short class name. Change the quality text from clamping to its accepted 1–100 range. The existing blur/sharpen range text stays, with a concise statement that out-of-range transformation values throw. Do not add task-worker, threshold, README, or porting-guide text.

### 4. Correct paginator state and page-name resolution (#90–91)

Files:

- `src/pagination/src/CursorPaginator.php`
- `src/pagination/src/Paginator.php`
- `tests/Pagination/PaginatorTest.php`
- `tests/Pagination/LengthAwarePaginatorTest.php`

Remove only the child `$hasMore` declaration. Change the simple paginator lookup to:

```php
$currentPage = $currentPage ?? static::resolveCurrentPage($this->pageName);
```

Keep both protected signatures unchanged. Add resolver tests for both simple and length-aware paginators that capture the exact custom/default page name. Keep or strengthen explicit-page tests so they prove the resolver is bypassed, including the existing explicit-zero behavior. Assert that the simple paginator's resolved page and generated URL use the same custom key.

### 5. Correct pagination package ownership (#92)

Files:

- `src/pagination/composer.json`
- `tests/Pagination/PackageMetadataTest.php`
- `tests/Pagination/CursorPaginatorTest.php`

Set the relevant manifest shape to:

```json
"require": {
    "ext-filter": "*"
},
"suggest": {
    "hypervel/http": "Required to convert paginators to API resources (^0.4).",
    "hypervel/view": "Required to render pagination links (^0.4)."
}
```

The existing requirements remain except direct `hypervel/database` and `hypervel/http`. Do not add `require-dev` entries.

Extend the existing metadata test to assert Database/HTTP/View are absent from `require` and the HTTP/View suggestions are present and non-empty. Do not assert `ext-filter`; Composer validation owns platform-line syntax. Add a `JsonResource` cursor-parameter test proving `getParametersForItem()` unwraps the resource before reading configured parameters. Existing Model/Pivot tests continue to cover the optional Database branches.

### 6. Declare Filter at every direct owner

Add `"ext-filter": "*"` to the `require` section of these lockfile-free split manifests, preserving sorted package metadata:

- `src/cache/composer.json`
- `src/collections/composer.json`
- `src/console/composer.json`
- `src/database/composer.json`
- `src/foundation/composer.json`
- `src/grpc/composer.json`
- `src/opentelemetry/composer.json`
- `src/pagination/composer.json`
- `src/redis/composer.json`
- `src/saloon/composer.json`
- `src/scout/composer.json`
- `src/sentry/composer.json`
- `src/server/composer.json`
- `src/socialite/composer.json`
- `src/support/composer.json`
- `src/view/composer.json`

Insert `ext-filter` alphabetically beside any existing `ext-*` entries, or immediately after `php` when the manifest has no extension block. Leave each manifest's existing vendor-dependency order untouched. The five other production users—HTTP, Queue, Routing, Sanctum, and Validation—already declare Filter.

Do not create metadata tests for these mechanical lines. Six affected packages already maintain an exhaustive direct-dependency list, so add `ext-filter` to the existing arrays in:

- `tests/Console/PackageMetadataTest.php`
- `tests/Database/PackageMetadataTest.php`
- `tests/Redis/PackageMetadataTest.php`
- `tests/Saloon/PackageMetadataTest.php`
- `tests/Scout/PackageMetadataTest.php`
- `tests/Server/PackageMetadataTest.php`

The other ten packages intentionally use selective metadata assertions or have no package metadata test; do not introduce platform-line mirroring there. Pagination's step 5 instruction remains deliberately selective.

### 7. Replace unstable Mockery constraints

Files:

- `composer.json`
- `src/prompts/composer.json`
- `src/testbench/composer.json`
- `src/testing/composer.json`

Use Composer for the root dependency update:

```bash
composer require --dev "mockery/mockery:^1.6.15"
```

Do not commit the generated root `composer.lock`. Directly change the three split manifests, which have no lockfiles, to the same constraint. Keep Mockery in Testbench `require`, Testing `require`, and Prompts `require-dev`. The existing Testing metadata test already proves root/Testing alignment; do not add version-literal tests elsewhere.

### 8. Keep planning sources current

Files:

- `docs/plans/2026-08-22-0604-components-04-audit-remediation-plan-codex.md`
- `docs/todo.md`

After this plan owns the complete design, remove master-ledger rows #83–87 and #89–92 and remove their slice from the commit-order list. Do not duplicate corrected requirements there. Keep #83 and #89 rejection evidence in this plan.

Change the existing Foundation/Testing TODO sentence only enough to record that Support's runtime Foundation dependency propagates test dependencies to every package depending on Support. Preserve its existing remedy and do not create a Support decomposition item.

No package README or `src/docs/porting-from-laravel.md` change is warranted: the source edits are bug fixes/internal cleanup, protected Laravel signatures remain intact, and the only user guidance belongs in `src/docs/images.md`.

## Verification

Run changed test files immediately after their edits, and run affected existing contract suites after their manifest changes:

```bash
./vendor/bin/phpunit --no-progress tests/Image/ImageTest.php
./vendor/bin/phpunit --no-progress tests/Image/ImageManagerTest.php
./vendor/bin/phpunit --no-progress tests/Pagination/PaginatorTest.php
./vendor/bin/phpunit --no-progress tests/Pagination/LengthAwarePaginatorTest.php
./vendor/bin/phpunit --no-progress tests/Pagination/CursorPaginatorTest.php
./vendor/bin/phpunit --no-progress tests/Pagination/PackageMetadataTest.php
./vendor/bin/phpunit --no-progress tests/Console/PackageMetadataTest.php
./vendor/bin/phpunit --no-progress tests/Database/PackageMetadataTest.php
./vendor/bin/phpunit --no-progress tests/Redis/PackageMetadataTest.php
./vendor/bin/phpunit --no-progress tests/Saloon/PackageMetadataTest.php
./vendor/bin/phpunit --no-progress tests/Scout/PackageMetadataTest.php
./vendor/bin/phpunit --no-progress tests/Server/PackageMetadataTest.php
./vendor/bin/phpunit --no-progress tests/Testing/PackageMetadataTest.php
composer test:testbench
```

Validate every changed Composer manifest. Scan the root and all split manifests after the Mockery updates and confirm no `require` or `require-dev` constraint contains a development constraint or an `@alpha`, `@beta`, or `@RC` stability flag.

Use a temporary OS directory and local path/VCS repositories for a stable-minimum pagination install/autoload smoke test. Generate repository definitions and synthetic stable `0.4.0` versions for the complete local dependency closure rather than hand-assembling a partial list. The consumer must retain Composer's default stable minimum, install pagination, and instantiate/paginate a plain array. The smoke test checks resolution and autoloading, not a minimal dependency count.

Run targeted PHPStan on the changed Image/Pagination source only if a type question arises. At the implementation checkpoint, run `composer fix` once. After it passes, review every diff and retrace:

- HEIC success/failure and exception ownership;
- image validation boundaries and immutable cloning;
- built-in/custom manager dispatch and handler ordering;
- simple/length-aware resolver semantics and explicit-zero behavior;
- cursor Model/Pivot/JsonResource branches;
- Composer constraints, sorting, suggestions, and stable consumer resolution;
- documentation against final source;
- absence of added worker state, hot-path work, API signature changes, redundant tests, or stale plan text.

## Commit structure

Keep whole files together. Prefer reviewable commits in this order:

1. image correctness and tests;
2. image documentation;
3. pagination correctness and tests;
4. Filter dependency declarations;
5. stable Mockery constraints and their metadata verification;
6. branch plan, master-ledger retirement, and TODO correction last.
