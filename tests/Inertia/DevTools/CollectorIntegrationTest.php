<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\Inertia\DevTools\EntryStore;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Middleware;
use Hypervel\Inertia\Response;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\Fixtures\ExampleInertiaPropsProvider;
use Hypervel\Tests\Inertia\TestCase;
use ReflectionClass;
use RuntimeException;

class CollectorIntegrationTest extends TestCase
{
    use InteractsWithDevToolsStorage;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('inertia.devtools.enabled', true);
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindEntriesRepository();
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $this->clearDevToolsStorage();

        parent::tearDown();
    }

    /**
     * Assert a resolved source location points at a line containing the given text.
     *
     * This avoids hardcoding line numbers: reformatting the file moves both the code and
     * the resolved line together, so the assertion stays valid.
     *
     * @param array{file: string, line: int} $source
     */
    private function assertSourceLineContains(array $source, string $needle): void
    {
        $lines = file($source['file']);

        $this->assertArrayHasKey($source['line'] - 1, $lines, "No line {$source['line']} in {$source['file']}");
        $this->assertStringContainsString($needle, $lines[$source['line'] - 1]);
    }

    public function testCollectorPayloadReachesRecorderAndDoesNotLeakIntoPageJson(): void
    {
        Route::middleware(Middleware::class)
            ->get('/collector-route', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']))
            ->name('users.index');

        $response = $this->get('/collector-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);

        $response->assertOk();

        $json = $response->json();
        $this->assertArrayNotHasKey('devtools', $json);

        $this->app->make(EntryStore::class)->flush($this->repo);
        $this->assertCount(1, $this->recordedEntries());

        $entry = $this->latestRecordedEntry();

        $this->assertSame('Users/Index', $entry['__meta']['component']);
        $this->assertSame('users.index', $entry['route']['name']);
        $this->assertSame('/collector-route', $entry['route']['uri']);
        $this->assertSame('present', $entry['http']['responseBody']['status']);
        $this->assertSame('Users/Index', $entry['http']['responseBody']['value']['component']);
        $this->assertSame('Alice', $entry['http']['responseBody']['value']['props']['name']);
    }

    public function testPropsArePopulatedWithInertiaMetadata(): void
    {
        Route::middleware(Middleware::class)->get('/props-route', fn (): Response => Inertia::render('Users/Index', [
            'name' => 'Alice',
            'tags' => ['a', 'b'],
            'auth' => ['user' => ['id' => 1, 'name' => 'John']],
            'lazy' => Inertia::optional(fn (): string => 'never resolved'),
            'eager' => Inertia::always(fn (): string => 'always there'),
        ]));

        $this->get('/props-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();
        $props = $entry['props'];

        $this->assertArrayHasKey('name', $props);
        $this->assertArrayNotHasKey('phpType', $props['name']);
        $this->assertFalse($props['name']['shared']);
        $this->assertSame('Alice', $entry['propValues']['name']);

        $this->assertArrayHasKey('tags', $props);
        $this->assertArrayNotHasKey('phpType', $props['tags']);
        $this->assertArrayNotHasKey('count', $props['tags']);
        $this->assertArrayNotHasKey('model', $props['tags']);
        $this->assertSame(['a', 'b'], $entry['propValues']['tags']);
        $this->assertArrayNotHasKey('tags.0', $props);
        $this->assertArrayNotHasKey('tags.1', $props);
        $this->assertArrayNotHasKey('tags.0', $entry['propValues']);
        $this->assertArrayNotHasKey('tags.1', $entry['propValues']);

        $this->assertSame(['user' => ['id' => 1, 'name' => 'John']], $entry['propValues']['auth']);
        $this->assertArrayNotHasKey('auth.user', $entry['propValues']);
        $this->assertArrayNotHasKey('auth.user.id', $entry['propValues']);
        $this->assertArrayNotHasKey('auth.user.name', $entry['propValues']);

        $this->assertArrayHasKey('eager', $props);
        $this->assertSame('always', $props['eager']['inertiaType']);
        $this->assertSame('always there', $entry['propValues']['eager']);

        $this->assertArrayNotHasKey('lazy', $props);
    }

    public function testPropValuesAreRedacted(): void
    {
        Route::middleware(Middleware::class)->get('/redact-route', fn (): Response => Inertia::render('Users/Index', [
            'token' => 'super-secret',
            'auth' => ['user' => ['name' => 'John', 'api_key' => 'xyz']],
        ]));

        $this->get('/redact-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();
        $propValues = $entry['propValues'];

        $this->assertSame('[REDACTED]', $propValues['token']);
        $this->assertSame('John', $propValues['auth']['user']['name']);
        $this->assertSame('[REDACTED]', $propValues['auth']['user']['api_key']);

        // Only the value is secret: the prop's metadata is kept under its name.
        $this->assertFalse($entry['props']['token']['shared']);
        $this->assertSourceLineContains($entry['props']['token']['renderSource'], "'token' => 'super-secret'");
    }

    public function testNestedPropValuesAreRedactedByTheirPath(): void
    {
        Route::middleware(Middleware::class)->get('/nested-redact-route', fn (): Response => Inertia::render('Users/Index', [
            'auth' => ['token' => Inertia::always('nested-secret'), 'name' => Inertia::always('John')],
            'secret' => ['hint' => Inertia::always('ancestor-secret')],
        ]));

        $this->get('/nested-redact-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);

        $this->app->make(EntryStore::class)->flush($this->repo);

        $propValues = $this->latestRecordedEntry()['propValues'];

        $this->assertSame('[REDACTED]', $propValues['auth.token']);
        $this->assertSame('John', $propValues['auth.name']);
        $this->assertSame('[REDACTED]', $propValues['secret.hint']);
        $this->assertSame('[REDACTED]', $propValues['secret']);
    }

    public function testMergeDirectionAndDeepMergeAreRecorded(): void
    {
        Route::middleware(Middleware::class)->get('/merge-route', fn (): Response => Inertia::render('Users/Index', [
            'appended' => Inertia::merge(['a']),
            'prepended' => Inertia::merge(['b'])->prepend(),
            'deepAppended' => Inertia::merge(['c' => 1])->deepMerge(),
            'deepPrepended' => Inertia::merge(['d' => 1])->deepMerge()->prepend(),
            'matched' => Inertia::merge([['id' => 1]])->matchOn('id'),
        ]));

        $this->get('/merge-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);

        $this->app->make(EntryStore::class)->flush($this->repo);

        $props = $this->latestRecordedEntry()['props'];

        $this->assertSame('merge', $props['appended']['inertiaType']);
        $this->assertSame('append', $props['appended']['mergeDirection']);
        $this->assertArrayNotHasKey('deepMerge', $props['appended']);

        $this->assertSame('prepend', $props['prepended']['mergeDirection']);
        $this->assertArrayNotHasKey('deepMerge', $props['prepended']);

        $this->assertSame('append', $props['deepAppended']['mergeDirection']);
        $this->assertTrue($props['deepAppended']['deepMerge']);

        $this->assertSame('prepend', $props['deepPrepended']['mergeDirection']);
        $this->assertTrue($props['deepPrepended']['deepMerge']);

        // matchOn() upserts by key, so it reads as a deep merge in the panel.
        $this->assertSame('append', $props['matched']['mergeDirection']);
        $this->assertTrue($props['matched']['deepMerge']);
    }

    public function testDeferredPropReloadedOutsideADeferredRequestReadsAsRegular(): void
    {
        Route::middleware(Middleware::class)->get('/defer-reload-route', fn (): Response => Inertia::render('Users/Index', [
            'lazy' => Inertia::defer(fn (): string => 'loaded', 'groupA'),
        ]));

        // Manual partial reload (no devtools-deferred header): the DeferProp is delivered like a
        // regular partial prop, so it carries no defer type or group.
        $this->get('/defer-reload-route', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '',
            'X-Inertia-Partial-Component' => 'Users/Index',
            'X-Inertia-Partial-Data' => 'lazy',
        ]);
        $this->app->make(EntryStore::class)->flush($this->repo);

        $lazy = $this->latestRecordedEntry()['props']['lazy'];
        $this->assertNull($lazy['inertiaType']);
        $this->assertArrayNotHasKey('deferGroup', $lazy);

        // Deferred auto-load (devtools-deferred header): the prop reads as deferred with its group.
        $this->get('/defer-reload-route', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '',
            'X-Inertia-Partial-Component' => 'Users/Index',
            'X-Inertia-Partial-Data' => 'lazy',
            'X-Inertia-Devtools-Deferred' => '1',
        ]);
        $this->app->make(EntryStore::class)->flush($this->repo);

        $lazy = $this->latestRecordedEntry()['props']['lazy'];
        $this->assertSame('defer', $lazy['inertiaType']);
        $this->assertSame('groupA', $lazy['deferGroup']);
    }

    public function testRescuedDeferredPropIsFlagged(): void
    {
        Route::middleware(Middleware::class)->get('/rescue-route', fn (): Response => Inertia::render('Users/Index', [
            'flaky' => Inertia::defer(fn (): never => throw new RuntimeException('boom'), rescue: true),
        ]));

        $this->get('/rescue-route', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '',
            'X-Inertia-Partial-Component' => 'Users/Index',
            'X-Inertia-Partial-Data' => 'flaky',
            'X-Inertia-Devtools-Deferred' => '1',
        ]);

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();

        $this->assertSame('defer', $entry['props']['flaky']['inertiaType']);
        $this->assertTrue($entry['props']['flaky']['rescued']);
        $this->assertArrayNotHasKey('flaky', $entry['propValues'] ?? []);
    }

    public function testShareSourcesPopulateWhenShareIsCalled(): void
    {
        Inertia::share('flash', 'hello');

        Route::middleware(Middleware::class)->get('/share-route', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $this->get('/share-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();

        $this->assertTrue($entry['props']['flash']['shared']);
        $this->assertArrayHasKey('file', $entry['props']['flash']['shareSource']);
        $this->assertArrayHasKey('line', $entry['props']['flash']['shareSource']);
    }

    public function testPropsFromSharedPropertyProvidersAreMarkedShared(): void
    {
        // DevTools classifies shared props even when the page object does not expose their keys.
        config()->set('inertia.expose_shared_prop_keys', false);

        Inertia::share(new ExampleInertiaPropsProvider(['locale' => 'en']));

        Route::middleware(Middleware::class)->get('/provider-share-route', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $this->get('/provider-share-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $props = $this->latestRecordedEntry()['props'];

        $this->assertTrue($props['locale']['shared']);
        $this->assertFalse($props['name']['shared']);
    }

    public function testFlushedSharedPropsLeaveNoShareSourceBehind(): void
    {
        Inertia::share('name', 'Shared');
        Inertia::flushShared();

        Route::middleware(Middleware::class)->get('/flushed-share-route', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $this->get('/flushed-share-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $prop = $this->latestRecordedEntry()['props']['name'];

        $this->assertFalse($prop['shared']);
        $this->assertArrayNotHasKey('shareSource', $prop);
    }

    public function testShareSourcesResolveEachArrayKeyLine(): void
    {
        Inertia::share([
            'first_shared' => 'one',
            'second_shared' => 'two',
        ]);

        Route::middleware(Middleware::class)->get('/share-lines-route', fn (): Response => Inertia::render('Users/Index', ['name' => 'Jane']));

        $this->get('/share-lines-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();
        $first = $entry['props']['first_shared']['shareSource'];
        $second = $entry['props']['second_shared']['shareSource'];

        $this->assertSame(__FILE__, $first['file']);
        $this->assertSourceLineContains($first, "'first_shared' => 'one'");
        $this->assertSame(__FILE__, $second['file']);
        $this->assertSourceLineContains($second, "'second_shared' => 'two'");
        $this->assertNotSame($first['line'], $second['line']);
    }

    public function testMiddlewareShareSourcesResolveEachShareMethodKeyLine(): void
    {
        Route::middleware(DevToolsSharedSourceMiddleware::class)->get('/middleware-share-lines-route', fn (): Response => Inertia::render('Users/Index', ['name' => 'Jane']));

        $this->get('/middleware-share-lines-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();
        $first = $entry['props']['middleware_first_shared']['shareSource'];
        $second = $entry['props']['middleware_second_shared']['shareSource'];

        $this->assertSame(__FILE__, $first['file']);
        $this->assertSourceLineContains($first, "'middleware_first_shared' => 'one'");
        $this->assertSame(__FILE__, $second['file']);
        $this->assertSourceLineContains($second, "'middleware_second_shared' => 'two'");
        $this->assertNotSame($first['line'], $second['line']);
    }

    public function testMiddlewareShareSourcesResolveParentShareMethodKeys(): void
    {
        Route::middleware(DevToolsChildSharedSourceMiddleware::class)->get('/middleware-parent-share-lines-route', fn (): Response => Inertia::render('Users/Index', ['name' => 'Jane']));

        $this->get('/middleware-parent-share-lines-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();
        $parent = $entry['props']['parent_shared']['shareSource'];
        $child = $entry['props']['child_shared']['shareSource'];

        $this->assertSame(__FILE__, $parent['file']);
        $this->assertSourceLineContains($parent, "'parent_shared' => 'parent'");
        $this->assertSame(__FILE__, $child['file']);
        $this->assertSourceLineContains($child, "'child_shared' => 'child'");
        $this->assertNotSame($parent['line'], $child['line']);
    }

    public function testRenderSourceResolvesToTheRenderCallSite(): void
    {
        Route::middleware(Middleware::class)
            ->get('/render-source-route', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $this->get('/render-source-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();

        $this->assertSame(__FILE__, $entry['renderSource']['file']);
        $this->assertSourceLineContains($entry['renderSource'], "Inertia::render('Users/Index'");
    }

    public function testRenderSourceResolvesToTheRouteDefinitionForRouteDefinedInertiaRenders(): void
    {
        Route::inertia('/route-inertia', 'Users/Index', ['name' => 'Alice'])->middleware(Middleware::class);

        $this->get('/route-inertia', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();

        $this->assertSame(__FILE__, $entry['renderSource']['file']);
        $this->assertSourceLineContains($entry['renderSource'], "Route::inertia('/route-inertia'");
    }

    public function testActionSourceResolvesForInvokableControllers(): void
    {
        Route::middleware(Middleware::class)->get('/invokable-route', DevToolsInvokableController::class);

        $this->get('/invokable-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();

        $this->assertSame(__FILE__, $entry['route']['actionSource']['file']);
        $this->assertSourceLineContains($entry['route']['actionSource'], 'function __invoke');
    }

    public function testRenderSourceResolvesThroughTheInertiaHelper(): void
    {
        Route::middleware(Middleware::class)
            ->get('/helper-render-route', fn (): Response => inertia('Users/Index', ['name' => 'Alice']));

        $this->get('/helper-render-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $entry = $this->latestRecordedEntry();

        $this->assertSame(__FILE__, $entry['renderSource']['file']);
        $this->assertSourceLineContains($entry['renderSource'], "inertia('Users/Index'");
    }

    public function testOnceSharedMiddlewareKeysDoNotRecordFrameworkSources(): void
    {
        Route::middleware(DevToolsOnceSharedMiddleware::class)
            ->get('/once-shared-route', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $this->get('/once-shared-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $source = $this->latestRecordedEntry()['props']['once_shared']['shareSource'] ?? null;
        $frameworkDirectory = dirname((new ReflectionClass(Facade::class))->getFileName(), 4) . DIRECTORY_SEPARATOR;

        // The share call runs inside Inertia's middleware, so the frames above it belong to the
        // framework's pipeline and middleware, which must not be reported as the share source.
        $this->assertStringStartsNotWith($frameworkDirectory, $source['file'] ?? '');
    }

    public function testNumericPropKeysAreRecorded(): void
    {
        Route::middleware(Middleware::class)
            ->get('/numeric-props-route', fn (): Response => Inertia::render('Users/Index', ['2024' => 'year']));

        $response = $this->get('/numeric-props-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);

        $response->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        // Integer prop keys reach the collector's string-typed paths; recording must still match
        // the props the client received.
        $this->assertSame($response->json('props'), $this->latestRecordedEntry()['propValues']);
    }
}

class DevToolsOnceSharedMiddleware extends Middleware
{
    /**
     * Define the props that are shared once.
     */
    public function shareOnce(Request $request): array
    {
        return [
            'once_shared' => fn (): string => 'once',
        ];
    }
}

class DevToolsInvokableController
{
    /**
     * Render the users page.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Users/Index', ['name' => 'Alice']);
    }
}

class DevToolsSharedSourceMiddleware extends Middleware
{
    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'middleware_first_shared' => 'one',
            'middleware_second_shared' => 'two',
        ]);
    }
}

class DevToolsParentSharedSourceMiddleware extends Middleware
{
    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        return [
            'parent_shared' => 'parent',
        ];
    }
}

class DevToolsChildSharedSourceMiddleware extends DevToolsParentSharedSourceMiddleware
{
    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'child_shared' => 'child',
        ];
    }
}
