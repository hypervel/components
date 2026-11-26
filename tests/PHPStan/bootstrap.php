<?php

declare(strict_types=1);

use App\MacroableWithOwnCall;
use Hypervel\Auth\RequestGuard;
use Hypervel\Auth\SessionGuard;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Factories\Factory as EloquentFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Http\Client\Factory as HttpClientFactory;
use Hypervel\Http\Client\Response as HttpClientResponse;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Mail\Mailable;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Redis\RedisConnection;
use Hypervel\Routing\ResponseFactory;
use Hypervel\Routing\Router;
use Hypervel\Routing\RouteRegistrar;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Storage;
use Hypervel\Support\Fluent;
use Hypervel\Support\Optional;
use Hypervel\Support\Str;
use Hypervel\Testing\TestComponent;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\PHPStan\Fixtures\Macros\InvokableMacro;
use Hypervel\Tests\PHPStan\Fixtures\Macros\MacroHost;
use Hypervel\Tests\PHPStan\Fixtures\Managers\BoundExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ChildExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\CsvDriver;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ExtendedExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\JsonDriver;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ResolvedChildDriverManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ResolvedExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\TabbedCsvDriver;
use Hypervel\Tests\PHPStan\Fixtures\Managers\WrappedCreatorManager;
use Hypervel\Tests\PHPStan\Fixtures\Rules\ScopedAtBootModel;
use Hypervel\Tests\PHPStan\Rules\Queue\Data\RoutedJob;
use Hypervel\View\View;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalFlysystemAdapter;
use NoModelMakeHypervel\BootListenedModel;
use NoUnnecessaryCollectionCallHypervel\RetrievedListenedMember;

Builder::macro('globalCustomMacro', static function (string $arg = 'foobar', int $b = 5): string {
    return $arg;
});

Builder::macro('macroOnRelation', function (): string {
    return 'aa';
});

Relation::macro('macroOnRelation', function (): int {
    return 5;
});

// https://github.com/larastan/larastan/issues/2657
Builder::macro('latestFirst', function (string $column): Builder {
    return $this->orderBy($column, 'desc');
});

Relation::macro('latestFirst', function (string $column): Relation {
    return $this->orderBy($column, 'desc');
});

Builder::macro('getColumns', static function (): string {
    return 'macro';
});

Builder::macro('whereGlobalMacro', static function (): string {
    return 'macro';
});

QueryBuilder::macro('globalCustomDatabaseQueryMacro', static function (string $arg = 'foobar', int $b = 5): string {
    return $arg;
});

QueryBuilder::macro('whereQueryMacro', static function (): string {
    return 'macro';
});

Route::macro('facadeMacro', function (): ?string {
    return $this->currentRouteName();
});

SessionGuard::macro('sessionGuardMacro', function () {
    return $this->getName();
});

RequestGuard::macro('requestGuardMacro', function (): bool {
    return $this->check();
});

// Hypervel: a guard macro reaches AuthManager's calls only when every configured guard registers it.
SessionGuard::macro('guardMacro', static fn (): int => 5);
RequestGuard::macro('guardMacro', static fn (): int => 5);

Str::macro('trimMacro', 'trim');
Str::macro('probeLen', 'strlen');
Str::macro('probeCount', 'str_word_count');
Str::macro('asciiAliasMacro', Str::class . '::ascii');

Cache::macro('rememberIf', static fn ($cond, $key, $ttl, $callback): mixed => $cond ? Cache::remember($key, $ttl, $callback) : $callback());

// Macro types inferred from closure bodies.
Collection::macro('probeUntyped', function () {
    return 'literal';
});
Collection::macro('probeNativeNarrowed', function (): Collection {
    return collect([1, 2, 3])->map(fn (int $i) => (string) $i);
});
Collection::macro('probeThisGeneric', function () {
    return $this->take(2);
});
Collection::macro('probeThisFirst', fn () => $this->first());
Collection::macro('probeThisKeys', fn () => $this->keys());
Collection::macro('probeStatic', fn () => new static);
Collection::macro('probeParam', fn (int $n, $x) => [$n, $x]);
Collection::macro('probeRecursive', fn (int $n) => $n > 0 ? $this->probeRecursive($n - 1) : 'done');
Collection::macro('probeCallsOther', fn () => $this->probeThisGeneric()->probeThisFirst());
$probeCaptured = ['a' => 1];
Collection::macro('probeUse', function () use ($probeCaptured) {
    return $probeCaptured;
});
// Hypervel: the variables an arrow function captures are unknown values, not undefined ones.
$probeArrowCaptured = 'prefix';
Collection::macro('probeArrowCapture', fn (string $suffix) => [$probeArrowCaptured, isset($probeArrowCaptured), $suffix]);
// Two closures on one line cannot be told apart, so each uses its declared signature.
foreach (['probeSameLineA' => fn () => 1, 'probeSameLineB' => fn () => 'b'] as $name => $macro) {
    Collection::macro($name, $macro);
}
Collection::macro('probeFirstClass', strlen(...));
// Hypervel: the outer closure shares the registered closure's lines, so its declared signature is used.
Collection::macro('probeNested', (fn () => fn () => 123)());
require_once __DIR__ . '/Fixtures/Macros/namespaced-macros.php';
Builder::macro('probeBuilderThis', function () {
    return $this->where('id', 1);
});
Builder::macro('probeBuilderFirst', fn () => $this->first());
Str::macro('probeStr', fn (string $s) => Str::of($s)->upper());

Collection::macro('probeTypedThis', function (): Collection {
    return $this->take(2);
});
Collection::macro('probeTypedArray', function (): array {
    return $this->all();
});
Collection::macro('probeTypedMixed', fn (): mixed => $this->first());
Builder::macro('probeTypedBuilder', function (): Builder {
    return $this->where('id', 1);
});
Builder::macro('probeTypedModel', fn (): ?Model => $this->first());

MacroableWithOwnCall::macro('ownCallMacro', function (): int {
    return 5;
});

// Classes that use Macroable but define their own __call.
CacheRepository::macro('cacheRepositoryMacro', function () {
    return $this->getStore();
});

Schedule::macro('scheduleMacro', function (): array {
    return $this->events();
});

DatabaseManager::macro('databaseManagerMacro', function (): string {
    return $this->getDefaultConnection();
});

EloquentFactory::macro('eloquentFactoryMacro', function () {
    return $this->count(3);
});

Relation::macro('relationMacro', function () {
    return $this->getRelated();
});

QueryBuilder::macro('queryBuilderMacro', function () {
    return $this->where('active', true);
});

FilesystemAdapter::macro('filesystemAdapterMacro', function () {
    return $this->path('exports');
});

HttpClientFactory::macro('httpClientFactoryMacro', function () {
    return $this->withHeaders(['Accept' => 'application/json']);
});

HttpClientResponse::macro('httpClientResponseMacro', function () {
    return $this->collect('data');
});

RedirectResponse::macro('redirectResponseMacro', function () {
    return $this->with('status', 'saved');
});

JsonResource::macro('jsonResourceMacro', function () {
    return $this->additional(['meta' => ['version' => 1]]);
});

Mailable::macro('mailableMacro', function () {
    return $this->subject('Welcome');
});

ProcessFactory::macro('processFactoryMacro', function () {
    return $this->newPendingProcess()->timeout(30);
});

RedisConnection::macro('redisConnectionMacro', function (): ?string {
    return $this->getName();
});

Router::macro('routerMacro', function () {
    return $this->getRoutes();
});

Fluent::macro('fluentMacro', function (): array {
    return $this->toArray();
});

TestComponent::macro('testComponentMacro', function () {
    return $this->assertSee('Welcome');
});

TestResponse::macro('testResponseMacro', function () {
    return $this->assertStatus(422);
});

View::macro('viewMacro', function () {
    return $this->with('title', 'Dashboard');
});

RouteRegistrar::macro('routeRegistrarMacro', function () {
    return $this->prefix('admin');
});

// Hypervel: also shares a held object's method name in the Optional fixture, where the macro must win.
Optional::macro('optionalMacro', function (): bool {
    return $this->offsetExists('id');
});

// Hypervel: Macroable also accepts invokable objects, and passes every macro its argument values.
MacroHost::macro('invokableMacro', new InvokableMacro);
MacroHost::macro('referenceMacro', static function (int &$value): void {
});

// Hypervel: only Request's validation macros throw ValidationException.
MacroHost::macro('validate', static fn (): bool => true);

// Hypervel: the documented response()->caps() macro, registered on the bound ResponseFactory.
ResponseFactory::macro('caps', static fn (string $value): string => strtoupper($value));

// Hypervel: manager creators registered at boot, and a driver created before its creator was replaced.
app()->singleton(ExtendedExportManager::class);
app(ExtendedExportManager::class)->extend('csv', static fn (): JsonDriver => new JsonDriver);

app()->singleton(WrappedCreatorManager::class);
app(WrappedCreatorManager::class)->extend('csv', static fn (): CsvDriver => new CsvDriver);

app()->singleton(ResolvedExportManager::class);
app(ResolvedExportManager::class)->driver();
app(ResolvedExportManager::class)->extend('csv', static fn (): JsonDriver => new JsonDriver);

app()->singleton(ResolvedChildDriverManager::class);
app(ResolvedChildDriverManager::class)->driver();
app(ResolvedChildDriverManager::class)->extend('csv', static fn (): TabbedCsvDriver => new TabbedCsvDriver);

app()->singleton(BoundExportManager::class, ChildExportManager::class);

// Hypervel: Redis connection macros, one under a command's lowercase name, which runs instead of the command.
RedisConnection::macro('zrank', static fn (): string => 'macro');
RedisConnection::macro('customCommand', static fn (): int => 1);

// Hypervel: filesystem creators for the storage disk fixtures, and a configured local
// disk replaced with another filesystem, which disk() then returns instead.
Storage::extend('custom', static fn (): FilesystemAdapter => Storage::createLocalDriver(['root' => sys_get_temp_dir()]));
Storage::extend('pooled-custom', static fn (): FilesystemAdapter => Storage::createLocalDriver(['root' => sys_get_temp_dir()]), poolable: true);

$replacedDiskAdapter = new LocalFlysystemAdapter(sys_get_temp_dir());
Storage::set('replaced', new FilesystemAdapter(new Flysystem($replacedDiskAdapter), $replacedDiskAdapter));

// Hypervel: a booted queue route for the transaction dispatch fixtures.
Queue::route(RoutedJob::class, connection: 'sync');

// Hypervel: a global scope and a model boot listener registered at boot, after which
// the model make() rule cannot show that make() matches the constructor.
ScopedAtBootModel::addGlobalScope('scoped', static function (Builder $builder): void {
});
Event::listen('eloquent.booted: ' . BootListenedModel::class, static function (): void {
});

// Hypervel: a retrieved listener registered at boot, which could change the attributes the
// collection rule would read through a query's pluck() instead.
Event::listen('eloquent.retrieved: ' . RetrievedListenedMember::class, static function (): void {
});

class CustomCollectionMacro
{
    /**
     * Register the collection macros.
     */
    public function registerMacro(): void
    {
        Collection::macro('customCollectionMacro', [$this, 'customMacro']);
        Collection::macro('customCollectionMacroString', [self::class, 'customMacroString']);
    }

    /**
     * Run the object-callable macro.
     */
    public function customMacro(): string
    {
        return 'customMacro';
    }

    /**
     * Run the class-string-callable macro.
     *
     * Static, unlike upstream: Macroable::macro() natively requires a callable, and a
     * class-string callable can only name a static method.
     */
    public static function customMacroString(): string
    {
        return 'customMacroString';
    }
}

(new CustomCollectionMacro)->registerMacro();

require_once __DIR__ . '/enum-definition.php';
