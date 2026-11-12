# Hypervel Attributes (PR 1)

Branch: `feature/hypervel-attributes` (from `0.4`). This PR is complete on its own: it adds the `hypervel/attributes` package, replaces the docblock lifetime tags (`Boot-only.`, `Tests only.`, `Boot or tests only.`) with attributes, adds `#[Unsupported]` markers, and switches the existing PHPStan worker-state analysis to the attributes, so current analysis keeps working. The new call-site rules, the facade lifetime fix and the other PHPStan work are PR 2 (`docs/plans/2026-11-12-hypervel-phpstan-rules.md`), which builds on this PR after it merges.

Owner-approved: the new package, and the `AGENTS.md` wording below (agreed with the peer reviewer). After implementation and signoff, show the owner each `AGENTS.md` change before and after in chat.

## Why

- Docblock tags are prose. The worker-state helper matches them with a regex (`WorkerStateHelper::LIFETIME_TAG_PATTERN`, which also matches conditional prose such as "Boot-only when setting"), and PR 2's call-site rules need exact, machine-readable metadata, including the conditions under which a call is restricted.
- PHPStan's `AttributeReflectionFactory::fromNameAndArgumentExpressions()` drops an attribute that has arguments when the attribute class isn't installed. The attributes must therefore live in a real package that every using package declares (AGENTS "Declare only real dependencies"). That's why they don't go in `hypervel/support`, which several marked packages (`collections`, `container`, `contracts`, `core`, `coroutine`, `macroable`, `prompts`) don't require.
- Laravel precedent: `illuminate/conditionable` and `illuminate/macroable` are separate tiny packages sharing the `Illuminate\Support` namespace. `hypervel/conditionable` and `hypervel/macroable` already follow this with `Hypervel\Support\`.

## 1. The `hypervel/attributes` package

`src/attributes/`:

- `composer.json`: copy `src/conditionable/composer.json` and change the name to `hypervel/attributes`, the description to "The Hypervel Attributes package.", and the keywords to `php`, `attributes`, `hypervel`. Keep `"php": "^8.4"` as the only requirement, and keep PSR-4 `"Hypervel\\Support\\": "src/"`.
- `LICENSE.md`: `cp src/api-client/LICENSE.md` (a Hypervel-original MIT license).
- `README.md`:
  ```
  Attributes for Hypervel
  ===

  [![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/attributes)

  Documentation: https://hypervel.org/docs/static-analysis#method-attributes
  ```
- `src/Attributes/BootOnly.php`, `TestsOnly.php` and `BootOrTestsOnly.php` are identical apart from the class name. They stay open (AGENTS "Don't make classes final by default"; no invariant needs `final`), with no class docblock:
  ```php
  <?php

  declare(strict_types=1);

  namespace Hypervel\Support\Attributes;

  use Attribute;

  #[Attribute(Attribute::TARGET_METHOD)]
  class BootOnly
  {
      /**
       * Create a new attribute instance.
       *
       * @param null|string $whenNotDefault the parameter whose values other than its declared default make the call restricted
       * @param null|string $whenProvided the parameter whose presence in the call makes it restricted
       * @param bool $whenShared whether the restriction applies only to a worker-shared receiver
       */
      public function __construct(
          public ?string $whenNotDefault = null,
          public ?string $whenProvided = null,
          public bool $whenShared = false,
      ) {
      }
  }
  ```
- `src/Attributes/Unsupported.php`: an open `#[Attribute(Attribute::TARGET_METHOD)]` class with no members.

Semantics. PR 2 enforces them at call sites; in this PR the worker-state analysis only uses the markers for the body exemption in section 4.

- **`whenNotDefault: 'name'`:** a call is restricted when it passes that parameter (positionally or by name) a value that may differ from its declared default. An omitted argument, or one that is definitely the default, is a read.
- **`whenProvided: 'name'`:** a call is restricted when it passes that parameter at all, whatever the value. For methods that treat an explicit default differently from an omitted argument (`Passport::rememberConsentFor(null)` clears the duration).
- **`whenNotDefault` and `whenProvided` are mutually exclusive.** Each names a parameter with a default.
- **`whenShared: true`:** the method changes only its own instance's state, so the restriction applies only when the receiver is the worker-shared instance. Instance methods only.
- **A parameter condition and `whenShared` together:** both must hold.

Wiring:

- Root `composer.json`:
  - add `"hypervel/attributes": "self.version"` to `replace`;
  - add `"src/attributes/src/"` to the `Hypervel\\Support\\` autoload list, keeping it alphabetical;
  - run `composer dump-autoload`.
- `src/framework/composer.json`: add `"hypervel/attributes": "^0.4"`, sorted. Direct edit, since sub-package manifests have no lockfile.
- Every package that places one of the attributes in its `src` declares `"hypervel/attributes": "^0.4"`, sorted. That's every package with converted markers (69 packages hold tags today) plus every package receiving `#[Unsupported]`.
- Packages with a `tests/{Package}/PackageMetadataTest.php` that lists direct dependencies get `'hypervel/attributes'` added to the list, so the declaration stays guarded.
- `hypervel/phpstan` is the one exception: it only compares attribute names through `::class` constants, which never load the classes. Its existing precedent is the same: framework classes referenced by name aren't required.
- `bin/split.sh` and the skeleton CI's `.github/scripts/use-local-packages.php` discover `src/*` automatically, so nothing else changes. The owner creates the `hypervel/attributes` split repository and Packagist entry; record that in `docs/todo.md` (section 6).

## 2. Converting the lifetime tags

**Inventory.** There are 1,051 tag lines in 341 files:
- 1,015 unconditional `* Tag.` lines, 602 of them on instance methods;
- 36 conditional lines, listed below.

Write a throwaway php-parser script in the scratchpad (not committed). It produces candidates for source review; it doesn't decide markers. For each `ClassMethod` whose docblock has a tag line, it lists:
- the class;
- whether the method is static;
- the tag and its explanation;
- a body classification, following calls on `$this`/`self`/`static` to methods in the same class:
  - **external:** writes a static property, writes through another object or service, or calls another service's mutator;
  - **instance-only:** writes only `$this` properties, including array dimensions;
  - **none:** no visible mutation.

It also reports tag lines on anything other than a method docblock.

**Conversion rules.** The final marker must match the method's supported contract, checked in the source: delegates, parent and trait methods, nested objects, callbacks and references can change who owns the state a method writes.

- **Attribute.** The tag selects the attribute: `#[BootOnly]`, `#[TestsOnly]` or `#[BootOrTestsOnly]`. Place it on the line directly above the method signature, after any existing attributes, and import it.
- **Docblock.** Remove the tag phrase and keep the explanation sentence(s) naming the runtime harm. A paragraph left empty is removed together with its blank separator line.
- **instance-only:** add `whenShared: true`. Example: `Http\Client\Factory::globalOptions()`/`globalMiddleware()` write only the factory's own properties, and `tests/Http/HttpClientTest.php:4405` uses an independent `(new Factory)->globalOptions(...)`. Adjust prose that claims a worker-lifetime effect for every instance, e.g. "persists on the shared factory for the worker lifetime".
- **external:** stays unconditional, even on instance methods. Examples: `Router::resourceParameters()` writes `ResourceRegistrar`'s statics, and manager purges close shared pools.
- **none:** review it, and either correct the tag (the method isn't a mutator, so drop the attribute and the tag) or document the delegated effect.
- **Safe at runtime:** a tagged method whose runtime use is supported, such as clearing a cache that is rebuilt on demand, gets no attribute. Its docblock keeps the effect sentences without the tag (see the view and pooled-manager rows below).
- **Condition not expressible:** when the restriction depends on something the bounded arguments can't name, such as which name a call passes, the method gets no attribute and keeps accurate prose. Never invent an unconditional restriction.
- **Contracts and their implementations.** Attributes aren't inherited, so check them as a set (for example `Config\Repository::set()`/`prepend()`/`push()`, `Http\Kernel` middleware mutators, `Translator::setFallback()`, `ObjectPool\Factory::purgeAll()`, `Recycler::start()`/`stop()`, `CoroutineInterface::set()`/`getContextFor()`, `Image\Driver::transformUsing()`, `ModelIdentifier::useMorphMap()`):
  - every implementation of a marked contract method carries the same attribute unless its own behavior differs;
  - a marked implementation whose contract method is unmarked is reviewed the same way.
- **Constructors.** Tagged constructors (only the test fixture today) get the attribute the same way.
- **Bulk edits.** Do the mechanical conversion with the script, as text edits from the bottom of each file up, then review the complete diff (CLAUDE.md bulk-edit rule; this plan is the agreed replacement pattern). Hand-edit the conditional methods below.

**Conditional tags (36):**

| Method(s) | Result |
|---|---|
| `Relation::morphMap(?array $map = null, bool $merge = true)` | `#[BootOnly(whenNotDefault: 'map')]` |
| `ResourceRegistrar::verbs(array $verbs = [])`, `Router::resourceVerbs(array $verbs = [])` | `#[BootOnly(whenNotDefault: 'verbs')]` (`Router` writes `ResourceRegistrar`'s statics, so there's no `whenShared`) |
| Fortify `Features::twoFactorAuthentication()`, `passkeys()` (`array $options = []`) | `#[BootOnly(whenNotDefault: 'options')]` |
| `Passport::defaultScopes()` | `#[BootOnly(whenNotDefault: 'scopes')]` |
| `Passport::tokensExpireIn()`, `refreshTokensExpireIn()`, `personalAccessTokensExpireIn()`, `clientCredentialsTokensExpireIn()`, `dpopTokensExpireIn()` | `#[BootOnly(whenNotDefault: 'date')]` |
| `Passport::cookie()` | `#[BootOnly(whenNotDefault: 'cookie')]` |
| `Passport::rememberConsentFor()` | `#[BootOnly(whenProvided: 'duration')]`. It branches on `func_num_args()`, so an explicit `null` clears the duration; no runtime change. |
| `Prompts\Concerns\Themes::theme()` | `#[BootOnly(whenNotDefault: 'name')]` |
| Validation `Email::defaults()`, `Password::defaults()`, `File::defaults()` | `#[BootOnly(whenNotDefault: 'callback')]` |
| `Str::freezeUuids()`, `Str::freezeUlids()` | `#[TestsOnly]`. Replace "Tests only unless a callback is supplied" prose: both forms install a worker-wide factory (the callback form only until it returns) and aren't coroutine-safe. No runtime caller exists. |
| `View\Component::flushCache()`, `CompilerEngine::forgetCompiledOrNotExpired()` | No attribute: clearing is safe at runtime (`view:clear` does it), since the next render or compile check rebuilds the cache. Replace the tag sentence; keep the effect sentence and "The view:clear command owns runtime invalidation." |
| `AiManager::forgetInstance()`, `AiManager::purge()` | No attribute: forgetting an on-demand provider only clears `CoroutineContext`, while forgetting a configured one affects the worker, and the marker can't express which name a call passes. Replace the tag sentence with "Forgetting a configured provider affects every request on the worker; on-demand providers belong to the current operation." The inherited `MultipleInstanceManager` methods keep their attribute. |
| `Ai\Providers\Concerns\Has{Text,Image,Audio,Transcription,Embedding,Reranking,File,Store,Classification}Gateway::use*Gateway()` | `#[BootOrTestsOnly(whenShared: true)]`: configured providers are shared, while providers built per request are not. |
| `BroadcastManager::purge()`, `QueueManager::purge()`, `MailManager::purge()`, `FilesystemManager::purge()` | No attribute. Their documented contract (`queues.md:189`, `mail.md:362`, `filesystem.md:335`) is safe runtime recovery: other users transparently acquire a fresh pool. Replace the tag sentence with "Use this to recover broken pooled resources." and keep the effect sentences. `DatabaseManager`, `RedisManager`, `CacheManager` and `MultipleInstanceManager` purges keep `#[BootOrTestsOnly]`, since their documented effects on concurrent coroutines are unsafe. |

**Check.** After converting, `grep -rnE '\* (Boot-only|Tests only|Boot or tests only)\b' src tests` returns nothing. Historical `docs/plans/*` are immutable and keep their text.

## 3. `#[Unsupported]` markers

**Criteria.** Mark a retained public method that rejects every call and is unsupported for every instance of its declaring class.

Excluded:
- base defaults that supporting implementations override (Query and Schema grammar `compile*` defaults, `Schema\Builder::dropAll*()`, the `Connection` driver hooks, `Lock::refresh()`/`getRemainingLifetime()`);
- extension hooks (`Prunable::prunable()`, `MassPrunable::prunable()`);
- deliberate control-flow throws (`ResponseTrait::throwResponse()`, `SocialiteManager::getDefaultDriver()`);
- conditional rejections;
- test fakes (`FakeTransporter::run()`);
- `offsetSet()`/`offsetUnset()` on read-only `ArrayAccess`, which is reached through `[]=` syntax.

Engine `Channel::hasProducers()`/`hasConsumers()`/`isReadable()`/`isWritable()` are excluded by owner direction (section 6 TODO).

**Mark these 72 methods.** Each keeps its existing throw and message; check each body is still an unconditional throw.

- `Session\SymfonySessionDecorator`: `registerBag()`, `getBag()`, `getMetadataBag()`
- `Cache\AnyModeTaggedCache`: `get()`, `getRaw()`, `getAuthoritativeRaw()`, `many()`, `manyRaw()`, `has()`, `pull()`, `forget()`, `touch()`
- `Database\DatabaseManager`: `build()`, `connectUsing()`. `Database\Schema\ColumnDefinition::whereNotNull()`. `Query\Grammars\MariaDbGrammar::compileJoinLateral()`. `Schema\Grammars\SQLiteGrammar`: `compileSpatialIndex()`, `compileDropSpatialIndex()`
- `Filesystem\Concerns\InteractsWithPooledFilesystem`: `getDriver()`, `getAdapter()`, `getClient()`. `Filesystem\ScopedFilesystemProxy`: `getDriver()`, `getAdapter()`, `getClient()`, `serveUsing()`, `buildTemporaryUrlsUsing()`, `buildTemporaryUploadUrlsUsing()`. Keep one truthful shared `getClient()` message; don't move or duplicate the method.
- Scout `AlgoliaEngine`, `TypesenseEngine`, `TurbopufferEngine`: `createIndex()`
- `Grpc\Health\HealthService::watch()`. Saloon `Pagination\CursorPaginator::pool()`. `Engine\Http\Stream`: `tell()`, `seek()`. `ConnectionPool\KeepaliveConnection::getConnection()`
- `Support\Str::flushCache()`
- Prompts `Stream`, `Progress`, `Spinner`, `Task`: `prompt()`
- `Console\Scheduling\CallbackEvent::runInBackground()`. ApiClient `PendingRequest::setClient()`. The HTTP client's `PendingRequest::setClient()` is supported and stays unmarked.
- `Http\Response`: `send()`, `sendHeaders()`, `sendContent()`. `Http\Request::createFromGlobals()`. `Http\Resources\DelegatesToResource`: `resolveRouteBinding()`, `resolveChildRouteBinding()`. `Http\Resources\JsonApi\JsonApiResource`: `wrap()`, `withoutWrapping()`. `Http\Client\CurlStreamingBody`: `seek()`, `write()`
- Mcp `Server\Content\ResourceLink::toResource()`, `Notification::toTool()`/`toPrompt()`/`toResource()`, `Blob::toTool()`/`toPrompt()`
- Ai `Gateway\Anthropic\AnthropicGateway` and `Gateway\Xai\XaiGateway`: `generateImage()`, `generateAudio()`, `generateTranscription()`, `generateEmbeddings()`. `Files\S3Document::content()`, `Files\ProviderDocument::content()`
- Data `Concerns\PaginatedDataCollectable::withoutWrapping()`. `Attributes\Validation\AnyOf::create()`, `Can::create()`, `Password::create()`

## 4. PHPStan: read attributes instead of tags

`src/phpstan/src/Rules/Coroutine/WorkerStateHelper.php`:
- Remove `LIFETIME_TAG_PATTERN` and `hasLifetimeTag()`.
- Add the attribute list, imported from `Hypervel\Support\Attributes`, and the method:
  ```php
  /**
   * The attributes that restrict a method to boot time or tests.
   */
  private const array LIFETIME_ATTRIBUTES = [BootOnly::class, TestsOnly::class, BootOrTestsOnly::class];

  /**
   * Determine whether the function is marked as running only while the application boots or in tests.
   */
  public function hasLifetimeAttribute(FunctionReflection|ExtendedMethodReflection $function): bool
  {
      return array_any(
          $function->getAttributes(),
          static fn (AttributeReflection $attribute): bool => in_array($attribute->getName(), self::LIFETIME_ATTRIBUTES, true),
      );
  }
  ```
- `getFunctionFacts()` calls `hasLifetimeAttribute($function)` in place of the docblock check, and its docblock says "marked" instead of "documented".
- The exemption deliberately ignores the attribute arguments. Conditional methods' writes are their restricted setter writes, which the inventory verifies, as the tag exemption did before.
- `Scope::getFunction()` returns `?PhpFunctionFromParserNodeReflection`, which implements `FunctionReflection`; its method subclass implements `ExtendedMethodReflection`. Both declare `getAttributes()`.

`src/phpstan/src/Collectors/WorkerStateClassCollector.php:66`: a constructor marked with a lifetime attribute keeps its parameters by design. Read it through `$class->getNativeMethod('__construct')->getAttributes()` when the class declares its own constructor (the AST `getMethod('__construct')` check already establishes that), using `hasLifetimeAttribute()`. Update the comment to "A constructor marked as boot-time or test-only keeps its parameters by design."

Tests, in `tests/PHPStan/Rules/Coroutine/data/worker-state.php` and `WorkerStateRuleTest`:
- Convert the fixture tags to attributes, keeping their expected results:
  - `setRequestForTesting()` (line 105) → `#[TestsOnly]`;
  - `TestingRequestHolder::__construct()` (line 205) → `#[TestsOnly]`;
  - `configure()` (line 727) → `#[BootOnly]`.
- Add a `#[TestsOnly(whenNotDefault: 'request')]` method on the fixture's `SharedService` that stores its `?Request $request = null` parameter in `$this->request`. It isn't reported, while the existing unmarked `remember()` write to the same property is reported as `hypervel.workerSharedState`. This proves attributes with arguments are read. During implementation, confirm that removing the attribute makes the diagnostic appear.
- Run `vendor/bin/phpunit -c phpunit.phpstan.xml.dist --no-progress tests/PHPStan/Rules/Coroutine/WorkerStateRuleTest.php` immediately.

## 5. Guidance and documentation

`AGENTS.md` (agreed wording):

1. Replace the "Document worker-lifetime mutators" bullet, lines 392–397, with:
   > - **Mark restricted worker-state mutators** — when adding or changing a public mutator intended only for startup or tests, use an attribute from `Hypervel\Support\Attributes`:
   >   - `#[BootOnly]` — startup configuration
   >   - `#[TestsOnly]` — test fakes, swaps, and overrides
   >   - `#[BootOrTestsOnly]` — configuration or cleanup allowed in either context
   >
   >   Use `whenNotDefault: 'parameter'` only when the restriction applies to values differing from that parameter's declared default, `whenProvided: 'parameter'` when it applies whenever the argument is passed, and `whenShared: true` when it applies only to a worker-shared receiver. Keep a concise docblock explaining the runtime harm. If an API is intended for runtime use, fix unsafe shared-state behavior through a peer second opinion rather than restricting it to boot or tests.
2. Replace the `flushState()` paragraph at line 577 ("Do not add `Boot-only.`, …") with:
   > Leave `flushState()` unmarked; PHPStan recognizes this cleanup hook by name.
3. Insert after the "Fail fast" Code conventions bullet (line 217):
   > - **Mark intentionally unsupported methods** — when a public method must be retained but intentionally rejects every call, mark it with `#[Unsupported]` from `Hypervel\Support\Attributes`. Exclude base defaults that supporting implementations override, extension hooks, deliberate control-flow throws, and conditional rejections.

`src/docs/static-analysis.md`, in Laravel-docs prose:
- New `### Method Attributes` section (anchor `method-attributes`) at the end of "What the Analysis Knows", after "Custom Builders and Collections":
  - Mark a public method that changes worker-wide state and should only run while the application boots or in tests with `#[BootOnly]`, `#[TestsOnly]` or `#[BootOrTestsOnly]` from `Hypervel\Support\Attributes`, keeping a short docblock explaining the harm. The worker state checks don't report writes in these methods. Example: the current Worker State `setRequestForTesting()` example, with `#[TestsOnly]` above it and the docblock keeping only the title and the harm sentence.
  - When only some calls are restricted, the attribute says which: `whenNotDefault` restricts calls passing the named parameter a value other than its default, so the method can still be read at runtime; `whenProvided` restricts any call passing it; `whenShared` restricts calls only on a worker-shared receiver, the instance the container keeps for the whole worker (not a scoped or fresh one), for methods that change just their own object. Example signatures: `#[BootOnly(whenNotDefault: 'map')] morphMap(?array $map = null, bool $merge = true)` and `#[BootOnly(whenShared: true)] globalOptions(array|Closure $options)`.
  - Mark a public method that must exist but always throws, such as an interface method an implementation can't support, with `#[Unsupported]`.
- Worker State (lines 885–897): replace the docblock-tag paragraph and its example with one sentence: "Writes in methods marked as [boot-time or test-only](#method-attributes), and in `flushState` methods, are not reported."

`docs/todo.md`:
- In "Release preparation", add `hypervel/attributes` to the repositories and Packagist registrations to create, alongside `hypervel/workbench` and `hypervel/sentinel`.
- Add an "Engine" section with one entry:
  > - `Hypervel\Engine\Channel::hasProducers()`, `hasConsumers()`, `isReadable()` and `isWritable()` are inherited Hyperf stubs that always throw. Implement them with Swoole's `Channel::stats()` (`producer_num`, `consumer_num`, `queue_num`), or remove them from `ChannelInterface`, after checking their close, read and write semantics.

## 6. Monorepo consumers (after this PR merges)

`packages/hypervel/src` in the monorepo has 29 files using the tags:
- tenancy 10, workflow 7, webhooks 4, activity-log 2, kafka 2, ssh 2, jmespath 1, oidc 1.

It reaches components through a path repository, so `hypervel/attributes` resolves locally.

After this PR merges to `0.4`:
- convert those files on monorepo `main` with the same rules;
- add `"hypervel/attributes": "*"` to each affected sub-package `composer.json` (`src/{tenancy,workflow,…}/composer.json`; direct edit, no lockfile);
- run `composer require "hypervel/attributes:*"` in `packages/hypervel`, whose root manifest lists the components it uses;
- run that package's `composer analyse` and affected tests;
- get peer review, then commit on `main`. CLAUDE.md's "Consumer and sibling checks" workflow authorizes this.

This follow-up isn't part of the PR's completion.

## 7. Verification

Run sequentially from the components root:
1. Each changed or new test file as it's edited: `WorkerStateRuleTest` and each touched `PackageMetadataTest`.
2. One-off packaging smoke check, not committed:
   - copy `src/attributes` to a scratch directory, `composer dump-autoload` there;
   - run a script that requires its `vendor/autoload.php` and instantiates every attribute with its constructor arguments (`new BootOnly(whenNotDefault: 'map', whenShared: true)`, `new BootOnly(whenProvided: 'duration')`, `new Unsupported`), independent of the root mappings;
   - delete the directory.
3. A one-off script check that every package except `phpstan` whose `src` uses an attribute declares `hypervel/attributes`.
4. `composer analyse`. The conversion changes no runtime behavior, so this, rather than the runtime suites, verifies the converted files.
5. `composer test:phpstan`, since the worker-state helper changed.
6. `COMPOSER_PROCESS_TIMEOUT=0 composer lint:fix` before review.

## Status

Plan signed off by the peer. Next gate: commit it on this branch, then owner review. Implementation starts at section 1 after the owner's go-ahead.
