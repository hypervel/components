# Image Package Port

## Outcome

Port Laravel 13.x's Image component as the first-party `hypervel/image` package, including its contracts, facade, request/filesystem/validation integrations, configuration, documentation, GD and Imagick drivers, tests, split metadata, and framework package integration.

Keep Laravel's public API and ordering except for Hypervel's approved type, container, provider, coroutine, worker-lifetime, correctness, and performance adaptations. The finished API must also support a completely independent driver such as vips; only the bundled GD and Imagick drivers use Intervention Image.

This is a new Laravel package port. Implement it serially, one file at a time. Copy every upstream file with `cp`, read the complete copy, then edit it. Read the relevant source and docs again before each checklist item. Run every changed test file immediately before moving to the next one.

## Status

The implementation and local targeted verification are complete. Before the consumer workflows run, the canonical `hypervel/components` repository must publish both public CI image tags; the expanded PHP 8.4/8.5 workflows must then pass.

## References and verified facts

Primary references:

- `examples/laravel/framework/src/Illuminate/Image`
- `examples/laravel/framework/src/Illuminate/Contracts/Image`
- `examples/laravel/framework/src/Illuminate/Support/Facades/Image.php`
- `examples/laravel/framework/config/images.php`
- `examples/laravel/framework/tests/Image`
- `examples/laravel/framework/tests/Integration/Image`
- `examples/laravel/docs/images.md`
- `examples/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php` and `examples/laravel/docs/validation.md` for the AVIF/HEIC/HEIF validation surface added by Image follow-up commit `ae89eb5025`.
- Laravel's originating framework PR: `laravel/framework#59276`; use current 13.x source, not its historical diff.
- Intervention Image's official `4.2.1` tag. `ImageManager` retains one driver, `AbstractDriver` retains only `Config`, GD/Imagick drivers add no fields, and every decode creates a new image/core graph. Cached bundled drivers therefore retain no per-image native resources.

Hypervel references:

- `src/cache/{composer.json,LICENSE.md,README.md}` for a first-party Laravel package skeleton.
- `src/support/src/Manager.php` for worker-cached drivers and boot-only `extend()`.
- `src/coroutine/src/Locker.php` for single-flight lazy resolution.
- `src/foundation/src/Application.php` for canonical service aliases.
- `src/scout/src/ScoutServiceProvider.php` for optional config merging/publishing and discovery.
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php` for optional first-party static cleanup.
- `src/contracts/src/Filesystem/Factory.php` and `src/filesystem/src/FilesystemManager.php` for `UnitEnum|string|null` disks.
- `src/filesystem/src/ScopedFilesystemProxy.php` and `src/filesystem/src/Concerns/InteractsWithPooledFilesystem.php` for tenant-prefix capture and lease-safe lazy filesystem reads.
- `src/contracts/src/Support/Responsable.php` for the native Hypervel response signature.

Verified upstream defects to fix in the port:

| Defect | Final correction |
|---|---|
| A lazy closure runs on every untransformed `toBytes()` call; streams are consumed once; variants independently re-read one source. | Share one internal, single-flight `ImageSource` across an image family. |
| Materialization replaces the source and clears the pipeline, so inspection can make later transformations decode/re-encode an intermediate result despite the documented “encoded once at the end” contract. | Retain the original source and full recipe; cache final bytes per image instance. |
| `dominantColor()` runs a copied pipeline and discards the result, so `toBytes()` repeats it. | Inspect `toBytes()` and retain its processed-byte cache. |
| The Intervention requirement check runs after manager construction, so a missing package throws a raw class-not-found `Error`. | Check before `createManager()` and remove `ImageManager`'s hidden optional-method hook. |
| Intervention v3 is installable even though the adapter calls v4-only APIs. | Suggest Intervention, conflict with `<4.0 \|\| >=5.0`, and test against current 4.x. |
| HEIC fallback and processing catch `Throwable`, masking custom-driver `TypeError`/PHP `Error` as image input failures. | Catch decoder/processing `Exception`; let programming errors surface. `ImageSource` remains `Throwable`-wide only to publish the identical object, never to translate it. |
| Stream/base64 lazy resolvers use `?: throw`, so they accidentally reject the non-empty byte string `'0'` along with the deliberately invalid false/empty results. | Reject only `false` and `''`; preserve empty-input errors while allowing every non-empty byte string to reach normal image validation. |
| A hash already materialized on a parent is copied to a variant. | Reset all output/derived caches in `Image::__clone()`. |
| The hash-cache test never calls the clone; PNG integration coverage calls `toWebp()`; invalid-stream cleanup is not exception-safe. | Correct the tests while porting them. |
| Docs claim `(string) $image` returns bytes, but `toString()` returns a data URI; custom-driver example omits two required methods. | Correct and complete the Hypervel docs. |
| The Image HEIC/AVIF follow-up expanded Laravel's `image` validation rule to AVIF, HEIC, and HEIF without adding a focused validation regression, while current Laravel and Hypervel validation docs still list only the older formats. | Port the validator change, add explicit coverage for all three formats, and correct both Hypervel validation-documentation locations. |
| CI's GD build lacks WebP/AVIF and installs no Imagick, leaving the headline paths failing or permanently skipped. PECL also rewrites Imagick's shipped arginfo headers before their stub sources, so `make install` needlessly regenerates them and depends on a second network download of PHP-Parser that can fail after the extension has compiled. Installing the same toolchain and extensions in every matrix job also duplicates slow work and makes one transient release-host failure fan out across the suite. | Build focused PHP 8.4 and 8.5 CI images once a week from the Swoole 6.2.2 base. Build GD with both codecs and use checksum-verified PIE 1.4.9 for the latest compatible stable `phpredis/phpredis` and `imagick/imagick` releases. Validate every required extension and codec before publishing, then run all PHP jobs from those images. |
| The global container slot accepts any `ContainerContract` but `getInstance(): static` cannot return one; the inherited slot also cannot guarantee the called subclass. Laravel's own global-container consumers call concrete-only `makeWith()`, so unrelated contract implementations are not functional framework containers. | Type the shared slot, getter, and setter as `self`, retaining `Container`, `Application`, subclasses, and concrete mocks while removing the PHPStan assignment suppression. |

Do not add finfo, encoder, transformation-handler, or format metadata caches. A 20,000-call real-JPEG measurement found fresh `finfo` construction adds about 0.5 microseconds / 2.6% to MIME detection, which is noise beside image decode/encode. Do not optimize the linear custom-handler scan either; it is bounded by the short transformation pipeline and negligible beside processing.

### Complete framework-facing surface

| Surface | Port decision |
|---|---|
| `Request::image(string $key): ?Image` | Port the uploaded-file conversion and preserve the original `UploadedFile` on the image. |
| `FilesystemAdapter::image(string $path): Image` / `Storage::disk(...)->image(...)` | Port the lazy filesystem source and adapt it across Hypervel's scoped and pooled wrappers. |
| Returning an `Image` from a route/controller | Preserve `Image implements Responsable` and Hypervel's native typed `toResponse(Request): Response`; the router already handles it. Laravel has no `Response::image()` API, so do not invent one. |
| Validation `image` rule and `File::image()` | Accept AVIF, HEIC, and HEIF in addition to the existing formats and update both documentation surfaces. Otherwise the Image package can encode formats that the framework's own `image` rule rejects. The validation path uses guessed extensions and remains independent of `hypervel/image`, Intervention, codecs, and fixtures; `File::image()` already delegates to the same rule. |
| `UploadedFile::fake()->image()` / `FileFactory::image()` | Existing test-file generation is unrelated to the new component; retain its current API and `ext-gd` suggestion. Do not add real AVIF/HEIC generation: that would make a currently portable core test helper depend on optional codec capabilities and fail where its JPEG fallback succeeds. |

The preliminary `docs/notes/image-package.md` handoff and its `docs/todo.md` Image entry are fully superseded by this audited plan and have been removed. Do not leave a second stale specification or completed TODO behind.

Redis cache tag requirements remain mode-specific. The default `all` mode uses Laravel-style tag namespaces and the general PhpRedis 6.1 requirement. The `any` mode uses hash-field expiration and requires Redis 8.0 or later, Valkey 9.0 or later, and PhpRedis 6.3.0 or later. Keep the root components and `hypervel/redis` baseline at `^6.1`; state the conditional `^6.3` requirement in the cache package and framework aggregate suggestions, document it on the public tag-mode setter, and run CI with PhpRedis 6.3 or later so both modes receive coverage. The Redis doctor must check PhpRedis 6.1 for `all` mode and 6.3 for `any` mode, matching its existing mode-specific server and command checks. Its remediation and the live cache/session docs use PIE's documented `pie install phpredis/phpredis` command for both installation and upgrades; archived release docs remain unchanged. Any-mode batch writes must use one multi-field `HSETEX` per tag instead of `HSET` followed by `HEXPIRE`; all fields in the batch share one positive TTL, so the native command preserves atomic field creation/expiration while reducing commands. Do not replace the native hash-field commands with compatibility machinery or describe the Any-mode requirement as a general Redis connector requirement.

## Final lifecycle and API design

### State ownership

| State | Owner / lifetime |
|---|---|
| Image manager, custom creators, transformation handlers, resolved drivers, Intervention managers | Worker-lifetime singleton. Register only during worker boot. |
| Original source bytes | One `ImageSource` shared by clones for the image-family lifetime. The lazy resolver and captured resources are released after its terminal result. |
| Pipeline, driver override, processed bytes, MIME/dimension/color/hash caches | Per `Image` object. Every public manipulation clones and invalidates the derived caches. |
| Decoded GD/Imagick/vips handles | Local to one driver call and released before it returns. Never retain on a cached driver. |
| Macros | Worker-lifetime static state, cleared centrally between tests. |

Bind the canonical service as a worker singleton, not Laravel's scoped binding:

```php
$this->app->singleton(
    'image',
    fn (Container $container): ImageManager => new ImageManager($container),
);
```

Add `'image' => [ImageManager::class]` to `Application::registerCoreContainerAliases()` so string, facade, and concrete resolution share the one manager. Do not add `ImageServiceProvider` to `DefaultProviders`; it is an optional discovered package and needs no early bootstrap. Omit `DeferrableProvider` and `provides()` because Hypervel does not defer provider work per request.

`Manager::extend()`, `ImageManager::transformUsing()`, and driver `transformUsing()` mutate worker-lifetime registries. The latter two need this warning beneath their title:

```php
Boot-only. The handler persists on a cached driver for the worker lifetime
and affects every subsequent image processed by that driver.
```

Custom drivers may use ext-vips, a PHP vips library, a CLI, or a remote service without Intervention. They must implement all four `Driver` methods and remain stateless/coroutine-safe: retain only immutable configuration or a concurrency-safe client, never image contents, pipelines, native image handles, or request state. They must treat the supplied `ImagePipeline` as read-only; mutating or retaining it would break image immutability and race across callers.

Custom `Transformation` objects must be immutable. Pipeline clones share transformation instances; deep-cloning arbitrary user objects would be defensive machinery with unclear semantics. State this invariant on the contract and in the docs; keep the built-in transformations `readonly`.

### Multi-tenancy boundary

Do not add an image-level tenant resolver, key prefix, or row-partitioning API. The package owns no database rows, shared cache keys, tenant-addressed catalog, or persistent namespace: source bytes, recipes, rendered bytes, and derived metadata belong to one `Image` family, while the only shared manager/driver state is tenant-neutral boot configuration.

Tenant isolation for reads and writes belongs at the filesystem/path boundary. `Image::store*()` already delegates to the selected disk, so configured scoped disks and Hypervel's dynamic `ScopedFilesystemProxy` remain authoritative. Complete the new filesystem convenience method across every Hypervel wrapper rather than bypassing that boundary:

- `FilesystemAdapter::image()` creates the normal lazy image source and reports a missing path clearly.
- `ScopedFilesystemProxy::image()` resolves and captures its validated prefix and inner disk once when the image is created, then lazily reads only that scoped path. This prevents an image created for one tenant from switching prefixes or disks if materialized after coroutine context changes; `ScopedCloudFilesystemProxy` inherits the mapping. Empty prefixes fail immediately at `image()` creation unless the caller deliberately constructed the proxy with `allowRootPassthrough: true`; that explicit opt-out preserves existing behavior and permits an unscoped read.
- `InteractsWithPooledFilesystem::image()` creates a lazy source around the pool proxy's own `get()`. It must not borrow an adapter merely to return an `Image`, because the borrow would be released before lazy materialization. Both client-pooled and whole-driver pooled filesystems receive the method through the trait.

The two wrapper shapes are deliberately different:

```php
// ScopedFilesystemProxy: capture the validated tenant boundary now; read later.
$prefix = $this->prefix();

// Capture the disk with the prefix so tenant-varying resolvers cannot switch
// tenants between image creation and lazy materialization.
$disk = $this->resolveDisk();
$scopedPath = $this->applyPrefix($prefix, $path);

return new Image(
    fn () => $disk->get($scopedPath)
        ?? throw new ImageException("Unable to read image from path [{$path}]."),
);

// InteractsWithPooledFilesystem: borrow only when the lazy read occurs.
return new Image(
    fn () => $this->get($path)
        ?? throw new ImageException("Unable to read image from path [{$path}]."),
);
```

The scoped method deliberately does not use the otherwise-standard `call()` forwarding helper: late forwarding would re-resolve a tenant-varying disk during materialization, while eager forwarding would defeat lazy reads. Nothing from `call()`'s safety boundary is lost because `get()` is declared on the `Filesystem` contract, so its method-existence guard and unsupported-method remapping cannot apply. The scoped exception must report only the caller-supplied `$path`, never `$scopedPath`; tenant prefixes are privileged boundary state. Adapter, scoped, and pooled variants use the identical `Unable to read image from path [...]` message.

Custom creators, drivers, and transformation handlers are worker-global. They must not read tenant context while a cached driver is being constructed or capture tenant-specific credentials/configuration in boot callbacks. A backend that varies by tenant may retain a resolver or concurrency-safe credential/client provider and resolve it inside each `process()`, `dimensions()`, or `dominantColor()` call; it must never retain the resolved tenant value after that call. Per-image tenant choices may also travel in an immutable custom transformation. No additional `...Using` method is needed because `extend()` and `transformUsing()` already provide the backend extension points, while filesystem scoping owns persistent name isolation.

### Lazy source and retained recipe

`ImageSource` is an `@internal` implementation class in `Hypervel\Image`. It is absent from contracts, facade metadata, examples, and user docs. It accepts an eager string or `Closure`, then publishes exactly one string or original exception to every clone/coroutine:

```php
/**
 * Share one lazy source across every image derived from it.
 *
 * @internal
 */
class ImageSource
{
    protected const string LOCK_KEY_PREFIX = '__image.source.';

    protected ?string $contents = null;
    protected ?Closure $resolver = null;
    protected ?Throwable $exception = null;

    /**
     * Create a new image source instance.
     */
    public function __construct(Closure|string $contents)
    {
        if (is_string($contents)) {
            $this->contents = $contents;
        } else {
            $this->resolver = $contents;
        }
    }

    /**
     * Resolve the image source contents.
     */
    public function contents(): string
    {
        if ($this->contents !== null) {
            return $this->contents;
        }

        if ($this->exception !== null) {
            throw $this->exception;
        }

        // This object stays alive while its lock is held, so PHP cannot reuse its object ID for another source.
        $key = self::LOCK_KEY_PREFIX.spl_object_id($this);

        if (Locker::lock($key)) {
            try {
                /** @var Closure $resolver */
                $resolver = $this->resolver;
                $contents = $resolver();

                if (! is_string($contents)) {
                    throw new ImageException(sprintf(
                        'Image source resolver must return a string, %s returned.',
                        get_debug_type($contents),
                    ));
                }

                $this->contents = $contents;
            } catch (Throwable $exception) {
                $this->exception = $exception;
            } finally {
                $this->resolver = null;
                Locker::unlock($key);
            }
        }

        return $this->contents ?? throw ($this->exception
            ?? new ImageException('Image source resolution was interrupted.'));
    }
}
```

The source holder catches `Throwable` only to publish and rethrow the identical terminal object to every waiter; unlike processing/fallback handling, this is not error translation and therefore must also publish `Error` and `TypeError`. `Locker::unlock()` runs in `finally`, removes its static key, and wakes all waiters, so source families do not grow worker state. The final interruption exception is the impossible-state fallback and type narrowing for a lock released without publication, which is reachable only through abnormal forced lock cleanup such as test-time `Locker::flushState()` racing a waiter. A missing storage object gets a path-specific `ImageException` at the adapter/manager boundary; the generic non-string check covers public constructor closures and future sources.

`Image` retains the recipe and caches its rendered result:

```php
protected ImageSource $source;
protected ImagePipeline $pipeline;
protected ?string $processedContents = null;
protected ?string $mimeType = null;
/** @var array{0: int, 1: int}|null */
protected ?array $dimensions = null;
protected ?string $dominantColor = null;
protected ?string $hashName = null;

public function toBytes(): string
{
    if (! $this->pipeline->hasChanges()) {
        return $this->source->contents();
    }

    return $this->processedContents ??= $this->process();
}

/**
 * Process the image recipe.
 */
protected function process(): string
{
    try {
        return $this->resolveDriver()->process($this->source->contents(), $this->pipeline);
    } catch (ImageException $exception) {
        throw $exception;
    } catch (Exception $exception) {
        throw new ImageException("Failed to process image: {$exception->getMessage()}", 0, $exception);
    }
}
```

Do not clear the pipeline after processing and do not keep Laravel's redundant `processed` flag. Materializing the same recipe twice calls the driver once. Appending a transform after materialization clones the original source plus complete pipeline and renders once at the new endpoint. Switching drivers after materialization correctly re-renders the recipe rather than returning bytes from the old driver.

The retained source raises bounded per-request peak memory, because a materialized variant owns its output while the family retains one shared original. That is required for correct immutable/one-final-encode behavior. The docs must prominently direct large workloads to queued jobs.

Do not add a second lock around one `Image` object's processing. Concurrently materializing the exact same object may duplicate deterministic CPU work but does not corrupt bytes; callers should derive separate variants before concurrent work. The source fetch remains single-flight because duplicate network/disk reads and non-repeatable streams are correctness failures.

Use instance caches rather than coroutine-scoped `once()` for MIME type, dimensions, and dominant color. Public methods are immutable and `__clone()` resets every derived value:

```php
/**
 * Clone the pipeline and reset all derived state.
 */
public function __clone(): void
{
    $this->pipeline = clone $this->pipeline;
    $this->processedContents = null;
    $this->mimeType = null;
    $this->dimensions = null;
    $this->dominantColor = null;
    $this->hashName = null;
}
```

Delete `newClone()`. Use `clone $this` directly in `using()` and `withClone()` so PHP's clone hook is the single owner of pipeline cloning and cache invalidation.

`dominantColor()` delegates to `toBytes()`. HEIC dimensions catch `Exception` for decoder failure and fall back to `getimagesizefromstring`; `TypeError` and `Error` escape. `toBytes()` likewise wraps ordinary driver exceptions only. Add `Image::flushState()` delegating to `flushMacros()` at the class cleanup position required by `AGENTS.md`.

`Image` ends with upstream's serialization/string-representation methods, but those are not magic dispatch/lifecycle placement anchors named by `AGENTS.md`. Place `flushState()` at the actual end of the class, after `__toString()`, with only the standard `Flush all static state.` title docblock.

### Dependency contract

`hypervel/image` directly requires PHP 8.4, `ext-fileinfo`, and the Hypervel conditionable, container, contracts, coroutine, filesystem, foundation, HTTP, macroable, and support packages. Foundation owns the `config_path()` helper used by the package provider. The package does not require Intervention, GD, or Imagick because a custom driver needs none of them.

Package metadata:

```json
"suggest": {
    "ext-gd": "Required to use the GD image driver.",
    "ext-imagick": "Required to use the Imagick image driver.",
    "intervention/image": "Required to use the GD and Imagick image drivers (^4.0)."
},
"conflict": {
    "intervention/image": "<4.0 || >=5.0"
}
```

Before implementation, check Packagist again. Add the current compatible test dependency with:

```shell
composer require --dev 'intervention/image:^4.2' --no-interaction
```

The root components manifest also needs the conflict because it replaces the split package. Its untracked lock is local only; run `composer update --no-interaction` after all manifest edits and never commit the lock.

## Implemented design

### 1. Dependency and CI foundation

- [x] `composer.json`, every split-package manifest that declares `ext-swoole`, and the installation/deployment requirement lists — require Swoole 6.2.2 as the framework minimum; use PIE's `pie install swoole/swoole` command in the installation guide.
- [x] `.github/docker/ci/Dockerfile` — build a focused reusable test image from `phpswoole/swoole:6.2.2-php${PHP_VERSION}` for PHP 8.4 and 8.5. Install the shared native libraries plus `git` and `procps`; build missing bundled extensions, always configure GD with FreeType, JPEG, WebP, and AVIF; disable Swoole short names; download PIE 1.4.9 with bounded retries and verify its independently confirmed SHA-256 digest; install unversioned `phpredis/phpredis` and `imagick/imagick` one package per invocation so PIE selects the latest compatible stable releases and owns extension enabling. Keep Composer from the base image and do not add Node or pnpm. Link the OCI source directly to the canonical `hypervel/components` repository. End the build with executable checks for the expected PHP minor, Swoole 6.2.2, every required extension, phpredis 6.3.0 or newer for full any-tag-mode integration coverage, `imagewebp()`, `imageavif()`, and Composer. An invalid image must fail before publication.
- [x] `.github/workflows/ci-images.yml` — on a weekly non-peak-hour schedule, manual dispatch, and relevant `0.4` changes, build with `--pull` and publish the two moving tags `ghcr.io/hypervel/components-ci:php8.4-swoole6.2.2` and `php8.5-swoole6.2.2`. Guard the build by the canonical `hypervel/components` repository identity so forks and temporary mirrors remain pull-only consumers even when their schedule or manual trigger runs. Run the build matrix sequentially so only one PIE download/build reaches external services at a time. Grant only `contents: read` and `packages: write`, serialize overlapping workflow runs, and delete old untagged container versions after both builds while retaining the newest two. Tagged current images remain intact when a build fails.
- [x] `.github/actions/setup-php/action.yml` and consumers — delete the obsolete per-job installer after every consumer uses the public canonical CI image. Runtime suites (`tests`, databases, Redis/Valkey, engine, gRPC, Reverb, and Scout) use a direct `php: ["8.4", "8.5"]` matrix with `fail-fast: false`, PHP-labelled check names, matching image tags, and PHP-specific Composer cache keys. Static analysis, Doctum, Wayfinder, and Passkeys synchronization run once on the PHP 8.4 image. Keep the static-analysis `include` matrix because it maps names to configs. Move Doctum and Wayfinder from Node 22 to Node 24, with pnpm still supplied by the repository's pinned Corepack version rather than baked into the PHP image. Do not add container credentials or package-read permissions: the image is deliberately public, and existing workflow permissions—including Passkeys' content and pull-request write access—remain authoritative. Test workflows run on pull requests and on pushes to `0.4`, avoiding duplicate push and pull-request suites for feature branches.
- [ ] Canonical image activation — publish both tags from `hypervel/components` before switching consumers, link the organization-owned package to that repository, and make it public once in GitHub's package settings. GitHub exposes no workflow or REST setting for changing container-package visibility. Public access lets other repositories and local developers pull the exact CI environment without credentials. If the temporary package's OCI source label prevents unlinking it in the package settings, delete that temporary package and let the canonical guarded builder recreate it.
- [x] `src/cache/composer.json`, `src/cache/src/RedisStore.php`, Redis doctor, Any-mode batch writes, cache/session docs, and the framework aggregate manifest — keep the general PhpRedis floor at `^6.1`, state that the optional `any` tagging mode requires `^6.3`, place the Redis 8.0 / Valkey 9.0 / PhpRedis 6.3 requirements on the public mode-selection API, make the doctor select its PhpRedis minimum from the configured mode and recommend PIE for installation or upgrades, and batch each tag's fields and TTL into one `HSETEX`. The existing `hypervel/redis` suggestion remains the authoritative general connector requirement.

- [x] `composer.json` — use Composer to add Intervention 4.2 dev dependency, then add `"Hypervel\\Image\\": "src/image/src/"` autoload, `hypervel/image` replace entry, `ImageServiceProvider` discovery, and the Intervention conflict in existing alphabetical sections. Do not add Image to default providers.
- [x] `src/jwt/composer.json` — declare the existing provider's direct `hypervel/foundation` dependency because it also calls Foundation's `config_path()` helper; do not rely on `hypervel/support` to supply that package transitively.

After the package skeleton exists, run:

```shell
composer update --no-interaction
./vendor/bin/phpunit --no-progress tests/Composer/PackageManifestConsistencyTest.php
```

### 2. Package skeleton

- [x] `src/image/composer.json` — copy `src/cache/composer.json`, then edit it to the dependency/discovery metadata specified above. Keep root support/authors/branch conventions and `"Hypervel\\Image\\": "src/"` autoload.
- [x] `src/image/LICENSE.md` — copy Laravel Image's license, preserve Taylor Otwell, and add Hypervel copyright as the cache package does.
- [x] `src/image/README.md` — copy the cache README as the skeleton, then reduce it to:

```markdown
Image for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/image)

Documentation: https://hypervel.org/docs/images

## Differences From Laravel

Custom image drivers and transformation handlers must be registered during worker boot. Registrations affect every subsequent request handled by that worker.

Ported from: https://github.com/laravel/framework
```

- [x] `src/image/config/images.php` — copy Laravel's config, add strict types, replace Laravel prose with Hypervel, retain `IMAGE_DRIVER`, default `gd`, and the supported built-in list.

Do not copy Laravel's package-local `.gitattributes` or read-only split-repository pull-request workflow. Hypervel's components root owns repository attributes and CI, while the split tooling owns package repository publication; no existing `src/*` package carries either file.

### 3. Contracts, one file at a time

- [x] `src/contracts/src/Image/Driver.php` — copy upstream; add strict types/Hypervel namespaces and fully typed methods. Keep the backend-neutral four-method surface. Document that `process()` treats its pipeline as read-only and never retains it. Add the boot-only warning to `transformUsing()` because every conforming first-party/custom driver is worker-cached by the manager.
- [x] `src/contracts/src/Image/Transformation.php` — copy upstream; add strict types/namespace and a concise contract docblock requiring immutable implementations because clones share transformation objects.

The public driver surface remains:

```php
interface Driver
{
    public function process(string $contents, ImagePipeline $pipeline): string;
    /** @return array{0: int, 1: int} */
    public function dimensions(string $contents): array;
    public function dominantColor(string $contents): string;
    /** @param class-string<Transformation> $transformation */
    public function transformUsing(string $transformation, callable $callback): static;
}
```

### 4. Image package source, copied/created alphabetically

For every copied file: `cp`, read the complete destination, then namespace/type/adapt it. Preserve upstream member order unless a specified correctness change requires replacement.

- [x] `src/image/src/Drivers/GdDriver.php` — copy upstream; return `ImageManagerInterface` from `createManager()` and use Intervention's GD driver.
- [x] `src/image/src/Drivers/ImagickDriver.php` — same for Imagick.
- [x] `src/image/src/Drivers/InterventionDriver.php` — copy upstream; check requirements before manager creation; type the retained manager as `ImageManagerInterface`; use strict MIME membership; keep upstream's encoding-block `finally` and other decoded-image/sample cleanup; catch no programming errors; type handler lookup to `Transformation`; add the boot-only handler warning. Do not cache encoders/finfo/handler lookups or widen the encoding cleanup across the transformation loop, since local images are released naturally when the frame unwinds.
- [x] `src/image/src/Image.php` — copy upstream; keep the public constructor signature exactly `Closure|string $contents, ?UploadedFile $file = null` and build the internal `ImageSource` inside it. Replace stored closure/string contents and `processed` with the holder, retained pipeline, processed/derived instance caches, `process()`, and `__clone()` as specified. Delete `newClone()` and clone directly in `using()`/`withClone()`. Use `UnitEnum|string|null` on all four storing APIs, strict format membership, native Hypervel `Responsable`, `Exception`-only wrapping/fallback, a direct early-return dimensions cache rather than an immediately invoked closure, and an end-of-class `flushState()` for macros. `(string)` remains a data URI; `ImageSource` never appears in a public signature or another package.
- [x] `src/image/src/ImageException.php` — copy upstream; add strict types/namespace, retain `RuntimeException` inheritance.
- [x] `src/image/src/ImageManager.php` — copy upstream; use explicit false/empty checks for streams/base64; share lazy holders through `Image`; accept `UnitEnum|string|null`; keep local-path reads direct because the concrete filesystem returns a string or throws `FileNotFoundException`; turn null storage reads into a path-specific `ImageException`; reject HTTP client/server errors before reading remote response bodies and preserve the native `RequestException` with its status and response instead of wrapping it; remove `enum_value()`; keep the `createDriver()` override's image-specific `InvalidArgumentException` rewrap and `applyTransformationHandlers()`, remove only its hidden `ensureRequirementsAreMet` hook, and narrow `parent::createDriver()` with an accurate local `@var Driver` rather than a runtime branch. Add the boot-only `transformUsing()` warning and use `$this->config->string('images.default')`: the provider-merged config is the sole `gd` default.
- [x] `src/image/src/ImageOutputOptions.php` — copy upstream; type `public const int DEFAULT_QUALITY = 70`; preserve the documented format/quality shapes and `hasChanges()`.
- [x] `src/image/src/ImagePipeline.php` — copy upstream; add strict types; retain output-only cloning and `hasChanges()`. Do not deep-clone transformations.
- [x] `src/image/src/ImageServiceProvider.php` — copy upstream; remove deferred-provider machinery without a package-local divergence comment because `ServiceProvider` already records that framework-wide omission at its owning boundary; merge package config; bind canonical `image` singleton by closure; publish `images.php` under `image-config` while running in console.
- [x] `src/image/src/ImageSource.php` — create the internal source holder exactly from the lifecycle design; use the existing `Locker`, terminal result/exception publication, resolver release, non-string validation, object-ID lifetime comment, and interruption guard.
- [x] `src/image/src/Transformations/Blur.php` — copy upstream; strict types/namespace; readonly `int $amount`.
- [x] `src/image/src/Transformations/Contain.php` — readonly `int $width`, `int $height`, `?string $background`.
- [x] `src/image/src/Transformations/Cover.php` — readonly positive width/height.
- [x] `src/image/src/Transformations/Crop.php` — readonly width/height/x/y.
- [x] `src/image/src/Transformations/FlipHorizontally.php` — marker transformation.
- [x] `src/image/src/Transformations/FlipVertically.php` — marker transformation.
- [x] `src/image/src/Transformations/Grayscale.php` — marker transformation.
- [x] `src/image/src/Transformations/Orient.php` — marker transformation.
- [x] `src/image/src/Transformations/Resize.php` — readonly nullable width/height.
- [x] `src/image/src/Transformations/Rotate.php` — readonly float angle and nullable background.
- [x] `src/image/src/Transformations/Scale.php` — readonly nullable width/height.
- [x] `src/image/src/Transformations/Sharpen.php` — readonly `int $amount`.

Representative transformation shape:

```php
class Rotate implements Transformation
{
    public function __construct(
        public readonly float $angle,
        public readonly ?string $background = null,
    ) {
    }
}
```

After source is ported, grep all `src/` and `tests/` for `Illuminate\\Image`, `Illuminate\\Contracts\\Image`, and image-facing `Illuminate` facade imports; zero ported references may remain. Run targeted PHPStan for `src/image` only if needed while resolving a concrete type question; the checkpoint full analysis remains `composer fix`.

### 5. Framework integrations, one file at a time

- [x] `src/container/src/Container.php` — model the one inherited global slot as native `?self`; return `self` from `getInstance()` and accept/return `?self` from the tests-only `setInstance()`. Remove the impossible `null|static` property docblock and assignment suppression. Do not add a contract adapter, subclass guard, separate registry, or runtime rejection branch.
- [x] `src/filesystem/composer.json` — add a `hypervel/image` suggestion for image creation across adapters, scoped proxies, and pooled proxies.
- [x] `src/filesystem/src/Concerns/InteractsWithPooledFilesystem.php` — add a lazy `image()` implementation that reads through the proxy's public `get()` only at materialization; never return an image closure capturing a released borrowed adapter.
- [x] `src/filesystem/src/FilesystemAdapter.php` — copy upstream method into the same relative position; return lazy `Image`; convert a null `get()` result into `ImageException` naming the path.
- [x] `src/filesystem/src/ScopedFilesystemProxy.php` — explicitly map `image()` through the fail-closed tenant boundary by capturing one validated prefix and resolved disk, then lazily reading the resulting scoped path. Add the inline WHY comment from the design above, report only the unscoped caller path on failure, and do not route through `call()`/`__call()` or expose scoped internals. Preserve the existing explicit `allowRootPassthrough` opt-out.
- [x] `src/foundation/src/Application.php` — add canonical `'image' => [ImageManager::class]` alias alphabetically.
- [x] `src/http/composer.json` — add `hypervel/image` suggestion for request upload conversion.
- [x] `src/http/src/Concerns/InteractsWithInput.php` — copy upstream `image(string $key): ?Image` after `file()`; preserve uploaded file on the image.
- [x] `src/horizon/src/Events/LongWaitDetected.php` — restore upstream's `make(..., $parameters)` call while retaining Hypervel's renamed constructor keys. `makeWith()` is only an alias, and the old divergence is no longer needed now that `make()` accepts parameters.
- [x] `src/support/src/Facades/App.php` — regenerate only this facade after correcting the inherited container methods; its generated getter/setter signatures must match the concrete shared slot.
- [x] `src/support/src/Facades/Image.php` — copy upstream facade, strip its generated method inventory to the `@see ImageManager` source, use canonical `image` accessor, then generate its docblock with the targeted facade documenter.
- [x] `src/support/src/Facades/Request.php` — regenerate only this facade after adding the request method.
- [x] `src/support/src/Facades/Storage.php` — regenerate only this facade after adding the filesystem method.
- [x] `src/testing/src/PHPUnit/AfterEachTestSubscriber.php` — call a new optional `flushImageState()` between Horizon and Inertia; use `callIfExists(Image::class, 'flushState')` and no container resolution.
- [x] `src/validation/src/Concerns/ValidatesAttributes.php` — port AVIF/HEIC/HEIF support into `validateImage()` and make the touched `allow_svg` membership check strict. This rule remains usable without the Image package or Intervention and needs no new validation-package dependency.

Targeted facade generation must call the documenter directly, not the all-facades helper:

```shell
php -f src/facade-documenter/facade.php -- Hypervel\\Support\\Facades\\App
php -f src/facade-documenter/facade.php -- Hypervel\\Support\\Facades\\Image
php -f src/facade-documenter/facade.php -- Hypervel\\Support\\Facades\\Request
php -f src/facade-documenter/facade.php -- Hypervel\\Support\\Facades\\Storage
```

At review, use the full facade docblock test to detect drift; do not run the helper script that rewrites every facade.

### 6. Tests, ported/created serially with immediate execution

Every test class extends `Hypervel\Tests\TestCase` or `Hypervel\Testbench\TestCase`, declares strict types, gives test/lifecycle methods `: void`, and relies on inherited coroutine execution. When a test uses Mockery, import it as `m`. Use PHPUnit extension/function attributes for unavailable codec capabilities.

- [x] `tests/Container/ContainerTest.php` — add one focused shared-slot regression: install an `Application` through `Container::setInstance()` and assert both base and subclass getters return the identical object. Do not test PHP's native rejection of an unrelated contract.
- [x] `tests/Pool/HeartbeatConnectionTest.php` — change the helper's global mock from `ContainerContract` to concrete `Container`; `Pool` continues receiving it through the contract boundary. Run immediately.
- [x] `tests/Filesystem/ClientPooledFilesystemTest.php` — prove image creation acquires no client lease, first materialization performs one balanced borrow/read, repeated bytes reuse the source, and a missing path uses the shared caller-path error message. Run immediately.
- [x] `tests/Filesystem/FilesystemAdapterTest.php` — merge Laravel's adapter image test plus missing-path lazy failure coverage asserting the shared caller-path error message. Run this file immediately.
- [x] `tests/Filesystem/PackageMetadataTest.php` — create a focused split-manifest regression asserting the non-empty `hypervel/image` suggestion. Run immediately.
- [x] `tests/Filesystem/FilesystemPoolProxyTest.php` — prove the whole-driver proxy also defers its balanced lease/read until materialization and never leaves an adapter borrowed by the returned image. Run immediately.
- [x] `tests/Filesystem/ScopedFilesystemProxyTest.php` — prove `image()` captures one non-empty normalized tenant prefix and one resolved disk at creation, remains content-lazy, reads only the captured scoped path after context changes, and inherits through the cloud proxy. Assert an empty prefix fails at `image()` creation by default; explicit root passthrough reads the unscoped path. For a missing image, assert the shared message contains the caller path and excludes the tenant prefix. Run immediately.
- [x] `tests/Sentry/Features/StorageIntegrationTest.php` — classify `FilesystemAdapter::image()` as intentionally inherited by the Sentry decorator because its lazy closure calls the outer adapter's already-instrumented `get()` method. Do not add a redundant decorator override or a second span. Run immediately.
- [x] `tests/Http/HttpRequestTest.php` — port upstream `testImageMethod` and `testImageMethodReturnsNullForMissingKey` 1:1, preserving the uploaded-image and missing/non-file cases. Run immediately.
- [x] `tests/Http/PackageMetadataTest.php` — extend the existing package metadata owner to assert the non-empty `hypervel/image` suggestion while preserving its existing fake-image `ext-gd` assertion. Run immediately.
- [x] `tests/Image/CoroutineSafetyTest.php` — create focused public regressions:
  - two cloned images racing one yielding lazy resolver call it once and receive identical bytes;
  - both waiters receive the resolver's original terminal exception type/message and the resolver runs once;
  - separate images interleaving through one singleton stateless custom driver retain their own contents/pipelines;
  - do not lock or promise one processing call when the exact same `Image` object is concurrently materialized.
  Run immediately.
- [x] `tests/Image/Drivers/GdDriverTest.php` — copy upstream, use Hypervel base/imports/types, guard WebP and AVIF tests with their GD function capabilities, and cover every transformation, format, dimensions, alpha-free dominant color, custom immutable transformation, unsupported input, quality, and raw-output path. Run immediately.
- [x] `tests/Image/Drivers/ImagickDriverTest.php` — copy upstream, keep capability checks for WebP/AVIF/HEIC delegates, cover the same driver surface and HEIC display dimensions. Run immediately; it must run in CI after the setup action change.
- [x] `tests/Image/Drivers/InterventionDriverTest.php` — create a test-local subclass whose overridden `ensureRequirementsAreMet()` throws and whose `createManager()` records if it ran; assert manager construction never runs. Also prove handler mutation is boot-scoped behavior. Use only the existing protected extension point and add no production seam solely for testing. Run immediately.
- [x] `tests/Image/ImageManagerTest.php` — copy upstream and add/correct:
  - replace upstream's standalone empty-repository `gd` fallback test with a manager test that reads configured `images.default`; the provider test below owns the merged `gd` default so no duplicate manager fallback is reinstated;
  - backed and unit enum disks;
  - stream/base64 false, empty, and string `'0'` strict behavior;
  - exception-safe stream closure;
  - missing storage path message;
  - lazy source resolution only once across sequential variants;
  - custom backend works directly through `Driver`, with no Intervention inheritance;
  - direct public-API processing installs a concrete `Container`, never an unrelated contract in the global slot;
  - remote URL responses reject HTTP client/server errors before their bodies reach image decoding;
  - path, storage, and URL laziness expectations apply to the application mock actually resolved by the manager;
  - driver caches and transformation handler application remain worker-lifetime.
  Run immediately.
- [x] `tests/Image/ImageServiceProviderTest.php` — create Testbench coverage, register `ImageServiceProvider` through `getPackageProviders()`, and verify the merged packaged config independently of ambient `IMAGE_DRIVER`, publishable config, canonical alias, and one worker-lifetime manager/driver instance across resolutions. Pin the alias test to the GD config and skip it when GD is unavailable. Add no deferred-provider note: the framework owner already records the omission, Laravel has no matching provider test, and no upstream test is skipped. Run immediately.
- [x] `tests/Image/ImageTest.php` — copy upstream and preserve its public API breadth, then correct/add:
  - direct `toFormat()` for every supported spelling and HEIF normalization;
  - strict format rejection and every clamp boundary;
  - one resolver call for repeated raw `toBytes()`;
  - a public constructor closure returning a non-string gets the source resolver's precise `ImageException`;
  - one driver call for repeated materialization;
  - retained original source/full pipeline after materialize-then-clone;
  - `dominantColor()` then `toBytes()` causes no second process call;
  - driver switching re-renders the retained recipe;
  - instance metadata caches and clone invalidation;
  - clone hash independence by calling both objects;
  - normal storage methods preserve caller options, public storage methods force public visibility, and failed writes return `false`; assert these at the filesystem `put()` boundary because a local disk reports an ordinary write as public;
  - broken-driver `TypeError` escapes processing and HEIC fallback;
  - `flushState()` removes macros;
  - native Responsable signature/data URI string behavior.
  Replace the reflected `processed`-flag test with the retained-recipe behavioral test. Run immediately.
- [x] `tests/Integration/Image/ImageTest.php` — copy upstream into Hypervel Integration, register `ImageServiceProvider` through Testbench's `getPackageProviders()` hook, correct PNG coverage to call/assert PNG, and guard only WebP-encoding methods when GD lacks WebP rather than skipping the whole suite. Keep Laravel's Integration path because mirroring it avoids collision with the distinct unit `tests/Image/ImageTest.php`; it needs no external-service workflow, and `phpunit.xml.dist` already includes this directory. Preserve real GD end-to-end transformation, storage, request, facade, branching, idempotence, format, quality, hash, public visibility, and no-argument overload tests. Add a real materialize/append test proving the final output is encoded once from the full recipe. Run immediately.
- [x] `tests/Testing/PHPUnit/AfterEachTestSubscriberTest.php` — add `Image` to the framework macro cleanup regression so the optional grouped path is exercised. Run immediately.
- [x] `tests/Validation/ValidationValidatorTest.php` — extend the existing `testValidateImage(): void` matrix with AVIF, HEIC, and HEIF uploaded-file extensions while preserving the SVG opt-in cases. Run immediately.

Then run the cross-cutting metadata and facade owners:

```shell
./vendor/bin/phpunit --no-progress tests/Composer/PackageManifestConsistencyTest.php
./vendor/bin/phpunit --no-progress tests/FacadeDocumenter/FacadeDocblocksTest.php
```

### 7. User documentation

- [x] `docs/octane.md` — move the unadapted, unlisted Laravel Octane page out of the published `src/docs` package into the repository-level reference docs without changing its contents; no documentation navigation or page links target it.
- [x] `src/docs/documentation.md` — add Images between HTTP Client and JSON Schema in Digging Deeper.
- [x] `src/docs/filesystem.md` — merge Laravel's `Storage::disk(...)->image(...)` integration at the natural file-retrieval surface and link to the Images page; make the general scoped-resolver section point out that `image()` resolves and captures the disk/prefix at image creation, then explain that boundary at the image surface together with fail-closed empty prefixes and the existing explicit root-passthrough opt-out.
- [x] `src/docs/images.md` — first run `cp ../../../examples/laravel/docs/images.md src/docs/images.md` from this worktree, then read the complete copied destination before editing it in place. Do not draft a replacement document from scratch. Convert namespaces/links/prose to Hypervel and include:
  - install `hypervel/image`; Intervention plus GD/Imagick only for bundled drivers;
  - config publishing with the exact `php artisan vendor:publish --tag=image-config` command used by the provider;
  - uploaded, storage, bytes, base64, local path, URL, and stream sources; remote client/server errors fail before decoding, while streams remain caller-owned/open until first materialization;
  - immutable retained recipes, ordered transformations, one final encode, inspection/materialization caching, and variant-first concurrent use;
  - WebP/JPEG/PNG/GIF/AVIF/HEIC/BMP helpers, public `toFormat()`, HEIF normalization, and quality/optimize behavior;
  - bytes/base64/data URI, with `(string)` correctly documented as a data URI;
  - all storage overloads and `UnitEnum` disk names;
  - MIME/extension/dimensions/dominant color and direct route returns through `Responsable`;
  - clearly labeled incomplete, backend-neutral custom driver skeleton covering `process`, `dimensions`, `dominantColor`, and `transformUsing` without pretending to implement a specific third-party backend;
  - boot-only registration, worker-cached stateless drivers/handlers, a read-only/non-retained pipeline argument, and no per-image/request/native-handle retention;
  - no image-specific tenancy API: use tenant-scoped filesystem disks/paths, and make tenant-varying custom backends resolve context inside each operation rather than cached-driver construction;
  - immutable custom transformation requirement and readonly example;
  - prominent queue recommendation for large images because retained source plus outputs and codec work consume request memory/CPU.
- [x] `src/docs/requests.md` — merge Laravel's uploaded-file `$request->image(...)` integration at the uploaded-files surface and link to the Images page.
- [x] `src/docs/validation.md` — correct both the `image` rule and fluent `File::image()` prose to list AVIF, HEIC, and HEIF alongside the existing formats; preserve the SVG security/opt-in guidance.

Complete custom driver skeleton:

```php
class VipsDriver implements Driver
{
    public function process(string $contents, ImagePipeline $pipeline): string
    {
        // Decode with vips, apply the ordered immutable transformations and output options, then encode once.
    }

    public function dimensions(string $contents): array
    {
        // Return [$width, $height].
    }

    public function dominantColor(string $contents): string
    {
        // Return a seven-character RGB hex value such as #0080ff.
    }

    public function transformUsing(string $transformation, callable $callback): static
    {
        // Store boot-time handlers only; never store image/request state.
        return $this;
    }
}
```

Do not describe correctness fixes or internal caches as Laravel differences. The only README difference is the boot/worker lifetime developers must code against.

### 8. Framework meta-package integration

The public `hypervel/framework` meta-package must install the new core image component after the split package is available. Perform this companion change in its own clean worktree and a feature branch based on `0.4`, then validate its manifest:

- [x] `contrib/hypervel/framework/composer.json` — add `hypervel/image:^0.4`; expand the existing `ext-gd` suggestion to cover the GD image driver; add `ext-imagick` and `intervention/image:^4.0` suggestions. The split package's conflict enforces the supported Intervention major.

Do not add `config/images.php` to the application skeleton: the discovered package merges its default and publishes an override on demand.

## Verification and review

After all targeted files are green:

1. Run broad namespace searches across all `src/` and `tests/`; inspect every remaining `Illuminate\Image`, Laravel-facing image namespace, raw PHPUnit base class, untyped test method, and non-strict `in_array` hit.
2. Run `composer validate --strict composer.json` and `composer validate --strict src/image/composer.json`.
3. Re-open the installed Intervention 4.x vendor source and reconfirm manager/driver retained fields before accepting worker caching.
4. Inspect the full diff one file at a time for copied upstream bugs, dead `processed`/source-replacement logic, stale comments/docs, accidental generated facade churn, and missing package consumers.
5. Run `composer fix` once. Its full source analysis satisfies the new-package PHPStan checkpoint and it also owns formatting, parallel tests, Testbench, and dogfood; do not duplicate those full checks first. Use targeted PHPStan earlier only to answer a concrete type question, never on tests.
6. If `composer fix` fails, explain the exact root cause before source changes, use targeted checks while correcting it, then run the failed stage plus each remaining `fix` script stage. Repeat the whole checkpoint only when fixes can affect earlier stages.
7. Re-run the affected targeted test files after review corrections. Run a second `composer fix` only if review changes warrant a full checkpoint.
8. Verify `git status`, confirm no tracked `composer.lock`, generated artifacts, temp images, or unrelated changes, and leave all work unstaged/uncommitted.

For the CI image migration, also parse every changed workflow and grep for obsolete `setup-php` and direct Swoole test-container references locally. After publication, confirm through GitHub CLI that the canonical workflow built both targets from fresh base metadata, passed the Dockerfile smoke checks, and exposed the intended expanded job names/matrices. The image build itself is the authoritative extension check; do not add a repeated verification job or step to each consumer.

No edit is needed in PHPStan paths or split/release scripts: PHPStan analyzes all `src`, Composer consistency discovers every `src/*/composer.json`, and split/release scripts enumerate package directories.

## Completion invariants

- Public API remains Laravel-shaped and fully typed; arbitrary drivers do not depend on Intervention.
- One source family performs at most one lazy read, including concurrent variants and terminal failures.
- Every image recipe encodes once at its endpoint; inspection never changes later output semantics.
- Worker-cached manager/driver state contains only boot configuration and concurrency-safe dependencies.
- No request/image/native resource is retained by a worker singleton or static property.
- Tenant isolation remains at the filesystem/path boundary: a per-image scoped source may capture its tenant prefix/disk, no worker-cached image infrastructure retains that state, scoped filesystems fail closed unless root passthrough was explicitly enabled, and pooled sources never retain a released lease.
- All static macros are centrally reset between tests.
- A missing Intervention Image installation fails with an actionable Hypervel `ImageException` before manager construction. Missing GD or Imagick extensions surface Intervention's actionable `checkHealth()` exception, which `Image` wraps during processing; the bundled drivers therefore do not duplicate native extension checks. Request and filesystem `image()` integration methods deliberately match Laravel: when they need to instantiate an image and `hypervel/image` is absent, they fail with PHP's native class-not-found `Error`, and their Composer suggestions provide the install signal.
- CI executes GD WebP/AVIF and Imagick paths rather than silently skipping them, tests both supported PHP minors, and never compiles extensions independently in each consumer job.
- Package, root components, facade, consumers, docs, tests, split metadata, and framework meta-package agree on the same feature surface.
- No compatibility shim, deprecated provider machinery, obsolete processed flag, dead helper, stale comment, duplicated docs, TODO, skipped applicable test, or unjustified cache remains.
