<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Response as HttpResponse;
use Hypervel\Http\UploadedFile;
use Hypervel\Inertia\DevTools\DevToolsHeader;
use Hypervel\Inertia\DevTools\EntryStore;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Middleware;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\Support\Header;
use Hypervel\Inertia\Testing\AssertableInertia;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\Fixtures\DevToolsRootViewMiddleware;
use Hypervel\Tests\Inertia\TestCase;
use JsonSerializable;

class MiddlewareDevToolsTest extends TestCase
{
    use InteractsWithDevToolsStorage;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('inertia.devtools.enabled', true);
        $config->set('inertia.devtools.except', ['health', '_inertia/devtools*']);
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
     * Persist the pending entry.
     */
    protected function flushEntryStore(): void
    {
        $this->app->make(EntryStore::class)->flush($this->repo);
    }

    /**
     * Persist the pending entry and return the most recent one.
     *
     * @return null|array<string, mixed>
     */
    protected function lastSavedEntry(): ?array
    {
        $this->flushEntryStore();

        return $this->latestRecordedEntry();
    }

    public function testDevtoolsIdHeaderIsSetOnEveryResponse(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-target', fn (): string => 'ok');

        $response = $this->get('/devtools-target');

        $response->assertOk();
        $id = $response->headers->get(DevToolsHeader::DEVTOOLS_ID);

        $this->assertNotNull($id);
        $this->assertNotSame('', $id);
    }

    public function testDevtoolsParentOutHeaderIsSetOn3xxRedirects(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-redirect', fn (): RedirectResponse => redirect('/elsewhere'));

        $response = $this->get('/devtools-redirect');

        $response->assertRedirect('/elsewhere');
        $this->assertNotNull($response->headers->get(DevToolsHeader::DEVTOOLS_OUTGOING_PARENT));
    }

    public function testInitialInertiaHtmlResponseIncludesTheDevtoolsIdScriptTag(): void
    {
        Route::middleware(DevToolsRootViewMiddleware::class)->get('/devtools-html', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $response = $this->get('/devtools-html');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertIsString($content);
        $this->assertStringContainsString('data-inertia-devtools-id', $content);
        $this->assertStringContainsString('</body>', $content);
        $this->assertMatchesRegularExpression('/<script data-inertia-devtools-id type="application\/json">"[A-Z0-9]+"<\/script><\/body>/', $content);
    }

    public function testAnInjectedIdTagLeavesThePageObjectAssertable(): void
    {
        Route::middleware(DevToolsRootViewMiddleware::class)->get('/devtools-html', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $response = $this->get('/devtools-html');

        $response->assertOk();
        $this->assertStringContainsString('data-inertia-devtools-id', (string) $response->getContent());

        $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Users/Index')
            ->where('name', 'Alice'));
    }

    public function testTheBasePathIsReportedWhenTheAppIsServedFromASubdirectory(): void
    {
        Route::middleware(DevToolsRootViewMiddleware::class)->get('/devtools-html', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $response = $this->call('GET', '/portal/devtools-html', server: [
            'SCRIPT_FILENAME' => '/var/www/app/public/index.php',
            'SCRIPT_NAME' => '/portal/index.php',
            'PHP_SELF' => '/portal/index.php',
        ]);

        $response->assertOk();
        $this->assertSame('/portal', $response->headers->get(DevToolsHeader::DEVTOOLS_BASE_PATH));
        $this->assertMatchesRegularExpression(
            '/<script data-inertia-devtools-id data-inertia-devtools-base-path="\/portal" type="application\/json">"[A-Z0-9]+"<\/script><\/body>/',
            (string) $response->getContent()
        );
    }

    public function testNoBasePathIsReportedForAnAppServedFromTheRootOfItsOrigin(): void
    {
        Route::middleware(DevToolsRootViewMiddleware::class)->get('/devtools-html', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $response = $this->get('/devtools-html');

        $response->assertOk();
        $this->assertNull($response->headers->get(DevToolsHeader::DEVTOOLS_BASE_PATH));
        $this->assertStringNotContainsString('data-inertia-devtools-base-path', (string) $response->getContent());
    }

    public function testHtmlResponsesThatRenderNoInertiaPageAreLeftUntouched(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-plain-html', function (): HttpResponse {
            return response('<html><body><h1>hi</h1></body></html>')
                ->header('Content-Type', 'text/html; charset=UTF-8');
        });

        $response = $this->get('/devtools-plain-html');

        $response->assertOk();

        // The extension reads the tag as "the server enabled devtools on this page" and warns
        // when no interceptor registry follows, so a non-Inertia page must not carry it.
        $this->assertSame('<html><body><h1>hi</h1></body></html>', $response->getContent());
        $this->assertNotNull($response->headers->get(DevToolsHeader::DEVTOOLS_ID));
    }

    public function testInitialInertiaPageLoadIsRecordedAsInitial(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-initial', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $this->get('/devtools-initial');

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('initial', $entry['__meta']['requestType']);
        $this->assertSame('Users/Index', $entry['__meta']['component']);
    }

    public function testNonInertiaRequestsWithoutARenderedPageAreRecordedAsHttp(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-raw', fn (): string => 'ok');

        $this->get('/devtools-raw');

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('http', $entry['__meta']['requestType']);
        $this->assertNull($entry['__meta']['component']);
    }

    public function testInertiaJsonResponseIsNotHtmlInjected(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-json', fn (): JsonResponse => response()->json(['ok' => true]));

        $response = $this->get('/devtools-json', ['X-Inertia' => 'true']);

        $this->assertStringNotContainsString('data-inertia-devtools-id', (string) $response->getContent());
        $this->assertNotNull($response->headers->get(DevToolsHeader::DEVTOOLS_ID));
    }

    public function testExcludedPathsDoNotSetTheDevtoolsHeaders(): void
    {
        Route::middleware(Middleware::class)->get('/health', fn (): string => 'ok');

        $response = $this->get('/health');

        $this->assertNull($response->headers->get(DevToolsHeader::DEVTOOLS_ID));
    }

    public function testNonInertiaPostBodyIsRecordedAsMetadataOnly(): void
    {
        Route::middleware(Middleware::class)->post('/devtools-form', fn (): string => 'ok');

        $this->post('/devtools-form', ['password' => 'sekret', 'name' => 'alice']);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame(['status' => 'omitted', 'reason' => 'non-inertia-request'], $entry['http']['requestBody']);
        $this->assertSame(['status' => 'present', 'value' => 'ok'], $entry['http']['responseBody']);
    }

    public function testNonInertiaJsonResponseBodyIsCapturedAndRedacted(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-api', fn (): JsonResponse => response()->json([
            'token' => 'sekret',
            'name' => 'alice',
        ]));

        $this->get('/devtools-api');

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame([
            'status' => 'present',
            'value' => ['token' => '[REDACTED]', 'name' => 'alice'],
        ], $entry['http']['responseBody']);
    }

    public function testObjectPropsAreRecordedAsTheJsonTheClientReceived(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-objects', fn (): Response => Inertia::render('Users/Show', [
            'date' => CarbonImmutable::parse('2026-01-01 10:00:00'),
            'resource' => new class implements JsonSerializable {
                /**
                 * Get the JSON serializable representation of the resource.
                 */
                public function jsonSerialize(): array
                {
                    return ['id' => 7, 'name' => 'Jane'];
                }
            },
        ]));

        $response = $this->get('/devtools-objects', ['X-Inertia' => 'true']);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('present', $entry['http']['responseBody']['status']);

        // The recorded body must match the payload the client received, rather than the
        // marker the storage pass writes for values that are still objects.
        $this->assertSame(
            $response->json('props'),
            $entry['http']['responseBody']['value']['props'],
        );

        $this->assertSame([], $entry['http']['responseBody']['value']['props']['errors']);
        $this->assertSame('2026-01-01T10:00:00.000000Z', $entry['http']['responseBody']['value']['props']['date']);
        $this->assertSame(['id' => 7, 'name' => 'Jane'], $entry['http']['responseBody']['value']['props']['resource']);
    }

    public function testNonTextualResponseBodyIsOmitted(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-binary', fn (): HttpResponse => response('DATA', 200, [
            'Content-Type' => 'application/octet-stream',
        ]));

        $this->get('/devtools-binary');

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame(['status' => 'omitted', 'reason' => 'non-textual'], $entry['http']['responseBody']);
    }

    public function testPasswordAndTokenFieldsAreReplacedWithRedactedMarker(): void
    {
        config()->set('inertia.devtools.redact.keys', ['password', 'token']);

        Route::middleware(Middleware::class)->post('/devtools-api', fn (): string => 'ok');

        $this->postJson('/devtools-api', ['password' => 'sekret', 'token' => 't', 'name' => 'alice'], [
            'X-Inertia' => 'true',
        ]);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('present', $entry['http']['requestBody']['status']);
        $this->assertSame('[REDACTED]', $entry['http']['requestBody']['value']['password']);
        $this->assertSame('[REDACTED]', $entry['http']['requestBody']['value']['token']);
        $this->assertSame('alice', $entry['http']['requestBody']['value']['name']);
    }

    public function testUploadedFilesAreSummarizedInsteadOfSerialized(): void
    {
        Route::middleware(Middleware::class)->post('/devtools-upload', fn (): string => 'ok');

        $this->post('/devtools-upload', [
            'avatar' => UploadedFile::fake()->create('avatar.pdf', 100),
            'name' => 'alice',
        ], ['X-Inertia' => 'true']);

        $entry = $this->lastSavedEntry();

        $this->assertSame('present', $entry['http']['requestBody']['status']);
        $this->assertSame('alice', $entry['http']['requestBody']['value']['name']);
        $this->assertSame('avatar.pdf', $entry['http']['requestBody']['value']['avatar']['name']);
        $this->assertSame(100 * 1024, $entry['http']['requestBody']['value']['avatar']['size']);
        $this->assertArrayHasKey('mimeType', $entry['http']['requestBody']['value']['avatar']);
    }

    public function testBinaryRequestBodiesAreOmittedInsteadOfStored(): void
    {
        Route::middleware(Middleware::class)->post('/devtools-binary', fn (): string => 'ok');

        $this->call('POST', '/devtools-binary', [], [], [], [
            'HTTP_X_INERTIA' => 'true',
            'CONTENT_TYPE' => 'application/octet-stream',
        ], "\xff\xfe\x00\x01binary");

        $entry = $this->lastSavedEntry();

        $this->assertSame(['status' => 'omitted', 'reason' => 'binary'], $entry['http']['requestBody']);
    }

    public function testPrecognitionRequestsAreClassifiedBeforePartialRequests(): void
    {
        Route::middleware(Middleware::class)->post('/devtools-precognition', fn (): JsonResponse => response()->json([
            'errors' => ['name' => 'Required'],
        ], 422));

        $this->postJson('/devtools-precognition', ['name' => ''], [
            Header::INERTIA => 'true',
            Header::PRECOGNITION => 'true',
            Header::PARTIAL_COMPONENT => 'Users/Form',
            Header::PARTIAL_ONLY => 'errors',
        ]);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('precognition', $entry['__meta']['requestType']);
        $this->assertSame(422, $entry['__meta']['status']);
    }

    public function testRouteMetadataFallsBackToTheRequestRouteForNonInertiaRedirects(): void
    {
        Route::middleware(Middleware::class)
            ->post('/devtools-redirect-route', fn (): RedirectResponse => redirect('/elsewhere'))
            ->name('devtools.redirect');

        $this->post('/devtools-redirect-route');

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('devtools.redirect', $entry['route']['name']);
        $this->assertSame('/devtools-redirect-route', $entry['route']['uri']);
        $this->assertSame('Closure', $entry['route']['action']);
        $this->assertSame(__FILE__, $entry['route']['actionSource']['file']);
        $this->assertIsInt($entry['route']['actionSource']['line']);
    }

    public function testTabAndParentHeadersAreRecordedInEntryMetadata(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-headers', fn (): string => 'ok');

        $this->get('/devtools-headers', [
            DevToolsHeader::DEVTOOLS_TAB => 'tab-uuid-1',
            DevToolsHeader::DEVTOOLS_INCOMING_PARENT => 'parent-id-1',
            DevToolsHeader::DEVTOOLS_VISIT => 'visit-id-1',
            Header::INERTIA => 'true',
            Header::PARTIAL_COMPONENT => 'TestComponent',
        ]);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('tab-uuid-1', $entry['__meta']['tabUuid']);
        $this->assertSame('parent-id-1', $entry['__meta']['batchId']);
        $this->assertSame('visit-id-1', $entry['__meta']['visitId']);
    }

    public function testPrefetchSetsParentOutToItsOwnId(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-prefetch', fn (): string => 'ok');

        $response = $this->get('/devtools-prefetch', [
            Header::INERTIA => 'true',
            'Purpose' => 'prefetch',
            DevToolsHeader::DEVTOOLS_TAB => 'tab-prefetch',
            DevToolsHeader::DEVTOOLS_INCOMING_PARENT => 'originating-page',
        ]);

        $id = $response->headers->get(DevToolsHeader::DEVTOOLS_ID);
        $parentOut = $response->headers->get(DevToolsHeader::DEVTOOLS_OUTGOING_PARENT);

        $this->assertNotNull($id);
        $this->assertSame($id, $parentOut);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('originating-page', $entry['__meta']['batchId']);
        $this->assertSame('prefetch', $entry['__meta']['requestType']);
    }

    public function testDeferredFollowUpRequestsAreClassifiedAsDeferred(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-deferred', fn (): string => 'ok');

        $this->get('/devtools-deferred', [
            Header::INERTIA => 'true',
            Header::PARTIAL_COMPONENT => 'SomeComponent',
            Header::PARTIAL_ONLY => 'heavyData',
            DevToolsHeader::DEVTOOLS_DEFERRED => '1',
        ]);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('deferred', $entry['__meta']['requestType']);
    }

    public function testPartialRequestsWithoutTheDeferredHeaderStayPartial(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-partial-plain', fn (): string => 'ok');

        $this->get('/devtools-partial-plain', [
            Header::INERTIA => 'true',
            Header::PARTIAL_COMPONENT => 'SomeComponent',
            Header::PARTIAL_ONLY => 'heavyData',
        ]);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('partial', $entry['__meta']['requestType']);
    }

    public function testRedirectResponsesKeepRequestIntentAndRecordRedirectLocation(): void
    {
        Route::middleware(Middleware::class)->post('/devtools-redirect-type', fn (): RedirectResponse => redirect('/elsewhere'));

        $this->post('/devtools-redirect-type', [], [Header::INERTIA => 'true']);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('navigate', $entry['__meta']['requestType']);
        $this->assertSame(302, $entry['__meta']['status']);
        $this->assertSame('http://localhost/elsewhere', $entry['__meta']['redirectLocation']);
    }

    public function testExternalLocationResponsesKeepRequestIntentAndRecordRedirectLocation(): void
    {
        Route::middleware(Middleware::class)->post(
            '/devtools-location-type',
            fn (): HttpResponse => response('', 409)->header(Header::LOCATION, 'https://example.com'),
        );

        $this->post('/devtools-location-type', [], [Header::INERTIA => 'true']);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('navigate', $entry['__meta']['requestType']);
        $this->assertSame(409, $entry['__meta']['status']);
        $this->assertSame('https://example.com', $entry['__meta']['redirectLocation']);
    }

    public function testPartialRequestsEchoTheIncomingParentHeaderAsParentOut(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-partial', fn (): string => 'ok');

        $response = $this->get('/devtools-partial', [
            Header::INERTIA => 'true',
            Header::PARTIAL_COMPONENT => 'SomeComponent',
            DevToolsHeader::DEVTOOLS_TAB => 'tab-partial',
            DevToolsHeader::DEVTOOLS_INCOMING_PARENT => 'parent-batch-id',
        ]);

        $parentOut = $response->headers->get(DevToolsHeader::DEVTOOLS_OUTGOING_PARENT);

        $this->assertSame('parent-batch-id', $parentOut);
    }

    public function testFullNavigationRecordsNoBatchIdFromIncomingParentHeader(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-full-nav', fn (): string => 'ok');

        $this->get('/devtools-full-nav', [
            DevToolsHeader::DEVTOOLS_TAB => 'tab-uuid-2',
            DevToolsHeader::DEVTOOLS_INCOMING_PARENT => 'should-be-ignored',
        ]);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertNull($entry['__meta']['batchId']);
    }

    public function testFullInertiaReloadGroupsUnderTheIncomingParentHeader(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-reload', fn (): string => 'ok');

        $this->get('/devtools-reload', [
            Header::INERTIA => 'true',
            DevToolsHeader::DEVTOOLS_TAB => 'tab-reload',
            DevToolsHeader::DEVTOOLS_INCOMING_PARENT => 'origin-page-id',
        ]);

        $entry = $this->lastSavedEntry();

        $this->assertNotNull($entry);
        $this->assertSame('origin-page-id', $entry['__meta']['batchId']);
    }

    public function testSensitiveRequestHeadersHaveTheirValuesRedacted(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-secret', fn (): string => 'ok');

        $this->get('/devtools-secret', [
            'Authorization' => 'Bearer secret',
            'Cookie' => 'sid=abc',
            'X-XSRF-TOKEN' => 'csrf-value',
            'Accept' => 'application/json',
        ]);

        $headers = $this->lastSavedEntry()['http']['requestHeaders'];

        $this->assertSame('[REDACTED]', $headers['authorization']);
        $this->assertSame('[REDACTED]', $headers['cookie']);
        $this->assertSame('[REDACTED]', $headers['x-xsrf-token']);
        $this->assertSame('application/json', $headers['accept']);
    }
}
