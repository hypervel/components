<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Http\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Response;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\View;
use Hypervel\Support\Json;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\RequestWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[WithConfig('telescope.watchers', [
    RequestWatcher::class => true,
])]
class RequestWatchersTest extends FeatureTestCase
{
    public function testRequestWatcherRegistersRequests(): void
    {
        CarbonImmutable::setTestNow('2026-08-06 12:00:00 UTC');

        $result = ['email' => 'albert@hypervel.org'];
        Route::get('/emails', fn () => $result);

        $this->call('GET', '/emails', server: [
            'REQUEST_TIME_FLOAT' => CarbonImmutable::now()->subSeconds(5)->getPreciseTimestamp(6) / 1_000_000,
        ])->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(200, $entry->content['response_status']);
        $this->assertSame('/emails', $entry->content['uri']);
        $this->assertSame($result, $entry->content['response']);
        $this->assertSame(5000, $entry->content['duration']);
    }

    public function testRequestWatcherRecordsResponseAtTheEntryContentChildLimit(): void
    {
        $response = $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH - 1);
        Route::get('/deep-response', fn () => response()->json($response));

        $this->get('/deep-response')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame($response, $entry->content['response']);
    }

    public function testRequestWatcherPurgesResponseOverTheEntryContentChildLimitWithoutMediaTypeGate(): void
    {
        $response = $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH);
        Route::get('/deep-response', fn () => Response::make(
            Json::encode($response),
            headers: ['Content-Type' => 'text/html'],
        ));

        $this->get('/deep-response')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('Purged By Telescope', $entry->content['response']);
    }

    public function testRequestWatcherRegisters404()
    {
        $this->get('/whatever');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(404, $entry->content['response_status']);
        $this->assertSame('/whatever', $entry->content['uri']);
    }

    public function testRequestWatcherHidesPassword()
    {
        Route::post('/auth', fn () => 'success');

        $this->post('/auth', [
            'email' => 'telescope@hypervel.org',
            'password' => 'secret',
            'password_confirmation' => 'secret',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('telescope@hypervel.org', $entry->content['payload']['email']);
        $this->assertSame('********', $entry->content['payload']['password']);
        $this->assertSame('********', $entry->content['payload']['password_confirmation']);
    }

    public function testRequestWatcherHidesAuthorization()
    {
        Route::post('/dashboard', fn () => 'success');

        $this->post('/dashboard', [], [
            'authorization' => 'Basic YWxhZGRpbjpvcGVuc2VzYW1l',
            'content-type' => 'application/json',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('application/json', $entry->content['headers']['content-type']);
        $this->assertSame('********', $entry->content['headers']['authorization']);
    }

    public function testRequestWatcherHidesPhpAuthPw()
    {
        Route::post('/dashboard', fn () => 'success');

        $this->post('/dashboard', [], [
            'php-auth-pw' => 'secret',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('********', $entry->content['headers']['php-auth-pw']);
    }

    public function testRequestWatcherHidesNestedFalseySecrets(): void
    {
        Telescope::hideRequestHeaders(['x-zero']);
        Telescope::hideRequestParameters(['secret.zero', 'secret.false', 'secret.empty', 'secret.null']);
        Telescope::hideResponseParameters(['secret.zero', 'secret.false', 'secret.empty', 'secret.null']);

        Route::post('/falsey-secrets', function (Request $request) {
            $request->session()->put('secret', [
                'zero' => 0,
                'false' => false,
                'empty' => '',
                'null' => null,
            ]);

            return response()->json(['secret' => [
                'zero' => 0,
                'false' => false,
                'empty' => '',
                'null' => null,
            ]]);
        })->middleware(StartSession::class);

        $this->post('/falsey-secrets', [
            'secret' => [
                'zero' => 0,
                'false' => false,
                'empty' => '',
                'null' => null,
            ],
        ], ['x-zero' => '0']);

        $entry = $this->loadTelescopeEntries()->first();
        $masked = [
            'zero' => '********',
            'false' => '********',
            'empty' => '********',
            'null' => '********',
        ];

        $this->assertSame('********', $entry->content['headers']['x-zero']);
        $this->assertSame($masked, $entry->content['payload']['secret']);
        $this->assertSame($masked, $entry->content['session']['secret']);
        $this->assertSame($masked, $entry->content['response']['secret']);
    }

    public function testRequestWatcherPurgesDeepProgrammaticPayloadAndSessionWithoutLosingTheEntry(): void
    {
        $deep = $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH - 1);

        Route::post('/deep-input', function (Request $request) use ($deep) {
            $request->merge(['deep' => $deep]);
            $request->session()->put('deep', $deep);

            return 'ok';
        })->middleware(StartSession::class);

        $this->post('/deep-input')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('Purged By Telescope', $entry->content['payload']);
        $this->assertSame('Purged By Telescope', $entry->content['session']);
        $this->assertSame('HTML Response', $entry->content['response']);
    }

    public function testRequestWatcherAppliesExactByteLimit(): void
    {
        $watcher = new RequestWatcher(['size_limit' => 1]);

        $this->assertTrue($watcher->contentWithinLimits(str_repeat('a', 1024)));
        $this->assertFalse($watcher->contentWithinLimits(str_repeat('a', 1025)));
        $this->assertTrue($watcher->contentWithinLimits(str_repeat('é', 512)));
        $this->assertFalse($watcher->contentWithinLimits(str_repeat('é', 513)));
    }

    public function testItStoresAndDisplaysArrayOfRequestAndResponseHeaders()
    {
        Route::post('/dashboard', function () {
            /* @phpstan-ignore-next-line */
            return Response::make('success')->withHeaders([
                'X-Foo' => ['third', 'fourth'],
            ]);
        });

        $this->post('/dashboard', [], [
            'X-Bar' => ['first', 'second'],
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('first, second', $entry->content['headers']['x-bar']);
        $this->assertSame('third, fourth', $entry->content['response_headers']['x-foo']);
    }

    #[RequiresPhpExtension('gd')]
    public function testRequestWatcherHandlesFileUploads()
    {
        $image = UploadedFile::fake()->image('avatar.jpg');

        $this->post('fake-upload-file-route', [
            'image' => $image,
        ]);

        $uploadedImage = $this->loadTelescopeEntries()->first()->content['payload']['image'];

        $this->assertSame($image->getClientOriginalName(), $uploadedImage['name']);

        $this->assertSame($image->getSize() / 1000 . 'KB', $uploadedImage['size']);
    }

    #[RequiresPhpExtension('gd')]
    public function testRequestWatcherHandlesUnlinkedFileUploads()
    {
        $image = UploadedFile::fake()->image('unlinked-image.jpg');

        unlink($image->getPathName());

        $this->post('fake-upload-file-route', [
            'unlinked-image' => $image,
        ]);

        $uploadedImage = $this->loadTelescopeEntries()->first()->content['payload']['unlinked-image'];

        $this->assertSame($image->getClientOriginalName(), $uploadedImage['name']);

        $this->assertSame('0', $uploadedImage['size']);
    }

    public function testRequestWatcherPlainTextResponse()
    {
        Route::get('/fake-plain-text', function () {
            return Response::make(
                'plain telescope response',
                200,
                ['Content-Type' => 'text/plain']
            );
        });

        $this->get('/fake-plain-text')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();
        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(200, $entry->content['response_status']);
        $this->assertSame('plain telescope response', $entry->content['response']);
    }

    public function testRequestWatcherRecordsPlainTextPayload()
    {
        Route::post('/receive-plain-text', function () {
            return response()->json(['ok' => 'yeah']);
        });

        $this->call(
            'POST',
            '/receive-plain-text',
            server: $this->transformHeadersToServerVars(['Content-type' => 'text/plain']),
            content: 'plain-text-content'
        );

        $entry = $this->loadTelescopeEntries()->first();
        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('plain-text-content', $entry->content['payload']);
    }

    public function testRequestWatcherCallsFormatForTelescopeMethodIfItExists()
    {
        View::addNamespace('tests', __DIR__ . '/../Fixtures/views');

        Route::get('/fake-view', function () {
            return Response::make(
                View::make('tests::fake-view', ['items' => new FormatForTelescopeClass])
            );
        });

        $this->get('/fake-view')->assertSuccessful();

        $entry = $this->loadTelescopeEntries()->first();
        $this->assertSame(EntryType::REQUEST, $entry->type);
        $this->assertEquals(['Telescope', 'Laravel', 'PHP'], $entry->content['response']['data']['items']['properties']);
    }

    public function testRequestWatcherStoresFacadeContextWhenPresent()
    {
        Route::get('/with-context', fn () => 'ok');

        ContextRepository::getInstance()->add('trace_id', 'abc-123');
        ContextRepository::getInstance()->addHidden('api_key', 'secret');

        $this->get('/with-context');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertIsArray($entry->content['context']);
        $this->assertSame(['trace_id' => 'abc-123'], $entry->content['context']['data']);
        $this->assertSame(['api_key' => 'secret'], $entry->content['context']['hidden']);
    }

    public function testRepositoryEncodingFailureDoesNotInterruptTheRequestOrPublishTheDiagnosticBatch(): void
    {
        Route::get('/invalid-context', function () {
            ContextRepository::getInstance()->add('invalid', INF);

            return 'ok';
        });

        $this->get('/invalid-context')->assertSuccessful();

        $this->assertCount(0, $this->loadTelescopeEntries());
    }

    public function testRequestWatcherOmitsFacadeContextWhenAbsent()
    {
        Route::get('/no-context', fn () => 'ok');

        $this->get('/no-context');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNull($entry->content['context']);
    }

    public function testRequestWatcherRecordsCoroutineContext()
    {
        Route::get('/coroutine', fn () => 'ok');

        $this->get('/coroutine');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertArrayHasKey('coroutine_context', $entry->content);
        $this->assertIsArray($entry->content['coroutine_context']);
    }

    private function nestedValue(int $depth): array
    {
        $value = 'leaf';

        for ($index = 0; $index < $depth; ++$index) {
            $value = ['value' => $value];
        }

        return $value;
    }
}

class FormatForTelescopeClass
{
    public function formatForTelescope(): array
    {
        return [
            'Telescope', 'Laravel', 'PHP',
        ];
    }
}
