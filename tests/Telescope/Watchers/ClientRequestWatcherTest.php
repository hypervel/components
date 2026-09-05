<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAop;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\ClientRequestWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

enum ClientRequestWatcherTestIntTag: int
{
    case Zero = 0;
}

#[WithConfig('telescope.watchers', [
    ClientRequestWatcher::class => true,
])]
class ClientRequestWatcherTest extends FeatureTestCase
{
    use InteractsWithAop;

    public function testClientRequestWatcherRegistersSuccessfulClientRequestAndResponse(): void
    {
        $client = $this->makeClient([
            new Response(201, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-cache,private'], json_encode(['foo' => 'bar'])),
        ], ['http_errors' => false]);

        $this->executeTransfer(
            $client,
            new Request('GET', 'https://hypervel.org/foo/bar', ['Accept-Language' => 'nl_BE']),
            ['http_errors' => false]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame('https://hypervel.org/foo/bar', $entry->content['uri']);
        $this->assertNotNull($entry->content['headers']);
        $this->assertSame('nl_BE', $entry->content['headers']['accept-language']);
        $this->assertSame(201, $entry->content['response_status']);
        $this->assertSame(['content-type' => 'application/json', 'cache-control' => 'no-cache,private'], $entry->content['response_headers']);
        $this->assertSame(['foo' => 'bar'], $entry->content['response']);
        $this->assertArrayNotHasKey('transport', $entry->content);
    }

    public function testFakedClientRequestOmitsUnavailableTransferEvidence(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true]),
        ]);

        Http::get('https://hypervel.org/fake');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertArrayNotHasKey('duration', $entry->content);
        $this->assertArrayNotHasKey('transport', $entry->content);
    }

    #[DataProvider('transportStatsProvider')]
    public function testClientRequestWatcherRecordsTruthfulTransportStats(string $transport, bool $async): void
    {
        $handler = static function (RequestInterface $request, array $options) use ($transport): PromiseInterface {
            $response = new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true]));
            ($options['on_stats'])(new TransferStats($request, $response, 0.01, null, [
                'transport' => $transport,
            ]));

            return Create::promiseFor($response);
        };
        $client = new Client(['handler' => $handler]);
        $request = new Request('GET', 'https://hypervel.org/transport');

        if ($async) {
            $this->executeTransferAsync($client, $request)->wait();
        } else {
            $this->executeTransfer($client, $request);
        }

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame($transport, $entries->first()->content['transport']);
    }

    public function testAsyncRejectionWithoutStatsIsRecordedOnce(): void
    {
        $request = new Request('GET', 'https://unreachable.example.com/async');
        $exception = new ConnectException('Connection refused', $request);
        $client = new Client([
            'handler' => static fn (): PromiseInterface => new RejectedPromise($exception),
        ]);
        $promise = $this->executeTransferAsync($client, $request);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $promise->wait();
                $this->fail('The rejected promise did not throw.');
            } catch (ConnectException $thrownException) {
                $this->assertSame($exception, $thrownException);
            }
        }

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertArrayNotHasKey('response_status', $entries->first()->content);
        $this->assertArrayNotHasKey('transport', $entries->first()->content);
    }

    public static function transportStatsProvider(): array
    {
        return [
            'Swoole synchronous' => ['swoole', false],
            'Swoole asynchronous observation' => ['swoole', true],
            'Guzzle synchronous' => ['guzzle', false],
            'Guzzle asynchronous' => ['guzzle', true],
        ];
    }

    public function testClientRequestWatcherRegistersRedirectResponse(): void
    {
        $client = $this->makeClient([
            new Response(301, ['Location' => 'https://foo.bar']),
        ], ['allow_redirects' => false, 'http_errors' => false]);

        $this->executeTransfer(
            $client,
            new Request('GET', 'https://hypervel.org'),
            ['allow_redirects' => false, 'http_errors' => false]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertEquals('Redirected to https://foo.bar', $entry->content['response']);
    }

    public function testClientRequestWatcherPlainTextResponse(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'text/plain'], 'plain telescope response'),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org/fake-plain-text'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(200, $entry->content['response_status']);
        $this->assertSame('plain telescope response', $entry->content['response']);
    }

    public function testClientRequestWatcherRegistersServerErrorResponse(): void
    {
        $client = $this->makeClient([
            new Response(500, [], json_encode(['error' => 'Something went wrong!'])),
        ], ['http_errors' => false]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org'), ['http_errors' => false]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertEquals(['error' => 'Something went wrong!'], $entry->content['response']);
    }

    public function testClientRequestWatcherHidesPassword(): void
    {
        $client = $this->makeClient([new Response(204)]);

        $payload = ['email' => 'telescope@hypervel.org', 'password' => 'secret', 'password_confirmation' => 'secret'];

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/auth', ['Content-Type' => 'application/json'], json_encode($payload)),
            ['hypervel_data' => $payload]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('telescope@hypervel.org', $entry->content['payload']['email']);
        $this->assertSame('********', $entry->content['payload']['password']);
        $this->assertSame('********', $entry->content['payload']['password_confirmation']);
    }

    public function testClientRequestWatcherHidesAuthorization(): void
    {
        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/dashboard', [
                'Authorization' => 'Basic YWxhZGRpbjpvcGVuc2VzYW1l',
                'Content-Type' => 'application/json',
            ])
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('application/json', $entry->content['headers']['content-type']);
        $this->assertSame('********', $entry->content['headers']['authorization']);
    }

    public function testClientRequestWatcherHidesPhpAuthPw(): void
    {
        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/dashboard', ['php-auth-pw' => 'secret'])
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('********', $entry->content['headers']['php-auth-pw']);
    }

    public function testClientRequestWatcherHandlesFormRequest(): void
    {
        $client = $this->makeClient([new Response(204)]);
        $payload = ['firstname' => 'Taylor', 'lastname' => 'Otwell'];

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/form-route', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($payload)),
            ['hypervel_data' => $payload]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame(['firstname' => 'Taylor', 'lastname' => 'Otwell'], $entry->content['payload']);
    }

    public function testClientRequestWatcherHandlesMultipartRequest(): void
    {
        $client = $this->makeClient([new Response(204)]);
        $payload = ['firstname' => 'Taylor', 'lastname' => 'Otwell'];

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/multipart-route'),
            ['hypervel_data' => $payload]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame(['firstname' => 'Taylor', 'lastname' => 'Otwell'], $entry->content['payload']);
    }

    public function testClientRequestWatcherHandlesFileContentsUpload(): void
    {
        $client = $this->makeClient([new Response(204)]);
        $image = UploadedFile::fake()->image('avatar.jpg');
        $contents = file_get_contents($image->getPathname());

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/fake-upload-file-route', ['Content-Type' => 'multipart/form-data']),
            ['hypervel_data' => [
                ['name' => 'image', 'contents' => $contents, 'filename' => 'photo.jpg', 'headers' => ['foo' => 'bar']],
            ]]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('photo.jpg', $entry->content['payload']['image']['name']);
        $this->assertSame(($image->getSize() / 1000) . 'KB', $entry->content['payload']['image']['size']);
        $this->assertSame(['foo' => 'bar'], $entry->content['payload']['image']['headers']);
    }

    public function testClientRequestWatcherHandlesFileContentsUploadWithoutExplicitFilenameOrHeaders(): void
    {
        $client = $this->makeClient([new Response(204)]);
        $image = UploadedFile::fake()->image('avatar.jpg');
        $contents = file_get_contents($image->getPathname());

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/fake-upload-file-route', ['Content-Type' => 'multipart/form-data']),
            ['hypervel_data' => [
                ['name' => 'image', 'contents' => $contents],
            ]]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertNull($entry->content['payload']['image']['name']);
        $this->assertSame(($image->getSize() / 1000) . 'KB', $entry->content['payload']['image']['size']);
        $this->assertSame([], $entry->content['payload']['image']['headers']);
    }

    public function testClientRequestWatcherHandlesResourceFileUpload(): void
    {
        $client = $this->makeClient([new Response(204)]);
        $image = UploadedFile::fake()->image('avatar.jpg');

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/fake-upload-file-route', ['Content-Type' => 'multipart/form-data']),
            ['hypervel_data' => [
                ['name' => 'image', 'contents' => $image->tempFile],
            ]]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertNull($entry->content['payload']['image']['name']);
        $this->assertSame(($image->getSize() / 1000) . 'KB', $entry->content['payload']['image']['size']);
        $this->assertSame([], $entry->content['payload']['image']['headers']);
    }

    public function testClientRequestWatcherHandlesResourceFileUploadWithFilenameAndHeaders(): void
    {
        $client = $this->makeClient([new Response(204)]);
        $image = UploadedFile::fake()->image('avatar.jpg');

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/fake-upload-file-route', ['Content-Type' => 'multipart/form-data']),
            ['hypervel_data' => [
                ['name' => 'image', 'contents' => $image->tempFile, 'filename' => 'photo.jpg', 'headers' => ['foo' => 'bar']],
            ]]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('photo.jpg', $entry->content['payload']['image']['name']);
        $this->assertSame(($image->getSize() / 1000) . 'KB', $entry->content['payload']['image']['size']);
        $this->assertSame(['foo' => 'bar'], $entry->content['payload']['image']['headers']);
    }

    public function testItStoresAndDisplaysArrayOfRequestHeaders(): void
    {
        $client = $this->makeClient([new Response(200)]);

        $this->executeTransfer(
            $client,
            new Request('GET', 'https://hypervel.org', ['X-Foo' => ['first', 'second'], 'X-Bar' => 'single'])
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('first, second', $entry->content['headers']['x-foo']);
        $this->assertSame('single', $entry->content['headers']['x-bar']);
    }

    public function testClientRequestWatcherRespectsWithoutTelescope(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['ok' => true])),
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org/health'), ['telescope_enabled' => false]);
        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org/api/data'));

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('https://hypervel.org/api/data', $entries->first()->content['uri']);
    }

    public function testClientRequestWatcherRecordsEmptyResponse(): void
    {
        $client = $this->makeClient([new Response(204, [], '')]);

        $this->executeTransfer($client, new Request('DELETE', 'https://hypervel.org/api/resource/1'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('DELETE', $entry->content['method']);
        $this->assertSame(204, $entry->content['response_status']);
        $this->assertSame('Empty Response', $entry->content['response']);
    }

    public function testClientRequestWatcherRecordsConnectionFailed(): void
    {
        $client = $this->makeClient([
            new ConnectException('Connection refused', new Request('GET', 'https://unreachable.example.com/api')),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://unreachable.example.com/api'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame('https://unreachable.example.com/api', $entry->content['uri']);
        $this->assertArrayNotHasKey('response_status', $entry->content);
    }

    public function testClientRequestWatcherRespectsWithoutTelescopeOnConnectionFailed(): void
    {
        $client = $this->makeClient([
            new ConnectException('Connection refused', new Request('GET', 'https://unreachable.example.com/api')),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://unreachable.example.com/api'), ['telescope_enabled' => false]);

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(0, $entries);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'ignore_hosts' => ['ignored.example.com'],
        ],
    ])]
    public function testClientRequestWatcherIgnoresHostsInIgnoreList(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['ok' => true])),
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://ignored.example.com/api/health'));
        $this->executeTransfer($client, new Request('GET', 'https://recorded.example.com/api/data'));

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('https://recorded.example.com/api/data', $entries->first()->content['uri']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'request_size_limit' => 1,
            'response_size_limit' => 1,
            'truncate_oversized' => true,
        ],
    ])]
    public function testClientRequestWatcherPurgesLargeResponses(): void
    {
        $largeBody = json_encode(['data' => str_repeat('x', 2000)]);

        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], $largeBody),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org/large-response'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertStringEndsWith('(truncated...)', $entry->content['response']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'request_size_limit' => 1,
            'response_size_limit' => 1,
            'truncate_oversized' => true,
        ],
    ])]
    public function testClientRequestWatcherPurgesLargeRequestPayloads(): void
    {
        $client = $this->makeClient([new Response(204)]);
        $payload = ['data' => str_repeat('x', 2000)];

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/large-payload', ['Content-Type' => 'application/json'], json_encode($payload)),
            ['hypervel_data' => $payload]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertStringEndsWith('(truncated...)', $entry->content['payload']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'request_size_limit' => 1,
            'response_size_limit' => 1,
            'truncate_oversized' => true,
        ],
    ])]
    public function testOversizedRequestPayloadMasksSensitiveFieldsBeforeTruncating(): void
    {
        $client = $this->makeClient([new Response(204)]);
        $payload = ['password' => 'secret', 'data' => str_repeat('x', 2000)];

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/api', ['Content-Type' => 'application/json'], json_encode($payload)),
            ['hypervel_data' => $payload]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertStringEndsWith('(truncated...)', $entry->content['payload']);
        $this->assertStringContainsString('********', $entry->content['payload']);
        $this->assertStringNotContainsString('secret', $entry->content['payload']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'request_size_limit' => 1,
            'response_size_limit' => 1,
            'truncate_oversized' => true,
        ],
    ])]
    public function testOversizedRawGuzzleRequestPayloadMasksSensitiveFieldsBeforeTruncating(): void
    {
        $payload = ['password' => 'secret', 'data' => str_repeat('x', 2000)];

        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://example.com/api', ['Content-Type' => 'application/json'], json_encode($payload))
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertStringEndsWith('(truncated...)', $entry->content['payload']);
        $this->assertStringContainsString('********', $entry->content['payload']);
        $this->assertStringNotContainsString('secret', $entry->content['payload']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'request_size_limit' => 1,
            'response_size_limit' => 1,
            'truncate_oversized' => true,
        ],
    ])]
    public function testOversizedResponseMasksSensitiveFieldsBeforeTruncating(): void
    {
        Telescope::hideResponseParameters(['password']);

        $responseBody = json_encode(['password' => 'secret', 'data' => str_repeat('x', 2000)]);

        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], $responseBody),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org/api'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertStringEndsWith('(truncated...)', $entry->content['response']);
        $this->assertStringContainsString('********', $entry->content['response']);
        $this->assertStringNotContainsString('secret', $entry->content['response']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'request_size_limit' => 1,
            'response_size_limit' => 1,
        ],
    ])]
    public function testOversizedRequestPayloadIsPurgedByDefault(): void
    {
        $client = $this->makeClient([new Response(204)]);
        $payload = ['password' => 'secret', 'data' => str_repeat('x', 2000)];

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/api', ['Content-Type' => 'application/json'], json_encode($payload)),
            ['hypervel_data' => $payload]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('Purged By Telescope', $entry->content['payload']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'request_size_limit' => 1,
            'response_size_limit' => 1,
        ],
    ])]
    public function testOversizedResponseIsPurgedByDefault(): void
    {
        $responseBody = json_encode(['password' => 'secret', 'data' => str_repeat('x', 2000)]);

        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], $responseBody),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org/api'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('Purged By Telescope', $entry->content['response']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'response_size_limit' => 1,
        ],
    ])]
    public function testOversizedRedirectResponseIsNotPurged(): void
    {
        $client = $this->makeClient([
            new Response(301, ['Location' => 'https://foo.bar'], str_repeat('x', 2000)),
        ], ['allow_redirects' => false, 'http_errors' => false]);

        $this->executeTransfer(
            $client,
            new Request('GET', 'https://hypervel.org'),
            ['allow_redirects' => false, 'http_errors' => false]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('Redirected to https://foo.bar', $entry->content['response']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'response_size_limit' => 1,
        ],
    ])]
    public function testOversizedHtmlResponseIsNotPurged(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'text/html'], str_repeat('<p>content</p>', 200)),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('HTML Response', $entry->content['response']);
    }

    public function testDirectGuzzleClientRequestIsCaptured(): void
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['captured' => true])),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://third-party.example.com/api'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame('https://third-party.example.com/api', $entry->content['uri']);
        $this->assertSame(['captured' => true], $entry->content['response']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'request_size_limit' => 1,
            'truncate_oversized' => true,
        ],
    ])]
    public function testDirectGuzzleLargeRequestPayloadIsTruncated(): void
    {
        $largeBody = json_encode(['data' => str_repeat('x', 2000)]);

        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://example.com/api', ['Content-Type' => 'application/json'], $largeBody)
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertStringEndsWith('(truncated...)', $entry->content['payload']);
    }

    public function testTelescopeEnabledFalsePerRequestOptOut(): void
    {
        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'), ['telescope_enabled' => false]);

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(0, $entries);
    }

    public function testTelescopeEnabledFalsePerClientOptOut(): void
    {
        $client = $this->makeClient([new Response(200, [], 'OK')], ['telescope_enabled' => false]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(0, $entries);
    }

    public function testTelescopeTagsViaGuzzleOption(): void
    {
        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $this->executeTransfer(
            $client,
            new Request('GET', 'https://example.com/api'),
            ['telescope_tags' => ['stripe', 'charges']]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $tags = DB::table('telescope_entries_tags')
            ->where('entry_uuid', $entry->uuid)
            ->pluck('tag')
            ->all();
        $this->assertContains('stripe', $tags);
        $this->assertContains('charges', $tags);
        $this->assertContains('example.com', $tags);
    }

    public function testWithTelescopeTagsViaHttpClient(): void
    {
        $client = $this->makeClient([new Response(200, [], json_encode(['ok' => true]))]);

        $this->executeTransfer(
            $client,
            new Request('GET', 'https://hypervel.org/api'),
            ['telescope_tags' => ['billing', 'invoice']]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $tags = DB::table('telescope_entries_tags')
            ->where('entry_uuid', $entry->uuid)
            ->pluck('tag')
            ->all();
        $this->assertContains('billing', $tags);
        $this->assertContains('invoice', $tags);
    }

    public function testTelescopeTagsViaGuzzleConstructorConfig(): void
    {
        // Regression guard: the framework relies on Guzzle's prepareDefaults()
        // merging client constructor config into per-request options before
        // transfer() runs, so tags set at construction time reach the aspect.
        // If Guzzle ever changes that merge, this test catches it.
        $client = $this->makeClient(
            [new Response(200, [], 'OK')],
            ['telescope_tags' => ['scout', 'algolia']],
        );

        $this->executeTransfer($client, new Request('GET', 'https://example.com/api'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $tags = DB::table('telescope_entries_tags')
            ->where('entry_uuid', $entry->uuid)
            ->pluck('tag')
            ->all();
        $this->assertContains('scout', $tags);
        $this->assertContains('algolia', $tags);
    }

    public function testBackedEnumTelescopeTagsAreNormalizedToStrings(): void
    {
        // The aspect's array_map normalizes enum cases via enum_value() so
        // tags reach storage as strings. Uses the real TelescopeTag enum —
        // proves the end-to-end path the framework itself relies on.
        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $this->executeTransfer(
            $client,
            new Request('GET', 'https://example.com/api'),
            ['telescope_tags' => [TelescopeTag::Scout, TelescopeTag::Algolia]],
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $tags = DB::table('telescope_entries_tags')
            ->where('entry_uuid', $entry->uuid)
            ->pluck('tag')
            ->all();
        $this->assertContains('scout', $tags);
        $this->assertContains('algolia', $tags);
    }

    public function testIntegerBackedEnumTelescopeTagsAreNormalizedToStrings(): void
    {
        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $this->executeTransfer(
            $client,
            new Request('GET', 'https://example.com/api'),
            ['telescope_tags' => [ClientRequestWatcherTestIntTag::Zero]],
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $tags = DB::table('telescope_entries_tags')
            ->where('entry_uuid', $entry->uuid)
            ->pluck('tag')
            ->all();
        $this->assertContains('0', $tags);
    }

    public function testExistingOnStatsCallbackIsPreserved(): void
    {
        $callbackFired = false;

        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $this->executeTransfer(
            $client,
            new Request('GET', 'https://example.com'),
            ['on_stats' => function (TransferStats $stats) use (&$callbackFired): void {
                $callbackFired = true;
            }]
        );

        $this->assertTrue($callbackFired, 'Existing on_stats callback should be preserved');

        $entry = $this->loadTelescopeEntries()->first();
        $this->assertNotNull($entry);
        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
    }

    public function testDirectGuzzleFailedConnectionIsCaptured(): void
    {
        $client = $this->makeClient([
            new ConnectException('Connection refused', new Request('GET', 'https://unreachable.example.com')),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://unreachable.example.com/api'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame('https://unreachable.example.com/api', $entry->content['uri']);
        $this->assertArrayNotHasKey('response_status', $entry->content);
    }

    private function makeClient(array $responses, array $config = []): Client
    {
        return new Client(array_merge($config, [
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }

    private function executeTransfer(
        Client $client,
        RequestInterface $request,
        array $options = [],
    ): void {
        if ($this->isAopProxied($client)) {
            try {
                $client->send($request, $options);
            } catch (ConnectException) {
                // Expected for failed connection tests.
            }

            return;
        }

        ['request' => $preparedRequest, 'options' => $preparedOptions] = $this->prepareTransferArguments(
            $client,
            $request,
            $options
        );

        try {
            $this->callWithAspects($client, 'transfer', [
                'request' => $preparedRequest,
                'options' => $preparedOptions,
            ])->wait();
        } catch (ConnectException) {
            // Expected for failed connection tests.
        }
    }

    private function executeTransferAsync(
        Client $client,
        RequestInterface $request,
        array $options = [],
    ): PromiseInterface {
        if ($this->isAopProxied($client)) {
            return $client->sendAsync($request, $options);
        }

        ['request' => $preparedRequest, 'options' => $preparedOptions] = $this->prepareTransferArguments(
            $client,
            $request,
            $options
        );

        return $this->callWithAspects($client, 'transfer', [
            'request' => $preparedRequest,
            'options' => $preparedOptions,
        ]);
    }

    /**
     * Mirror Guzzle's sendAsync() setup before manually invoking transfer().
     *
     * @return array{request: RequestInterface, options: array}
     */
    private function prepareTransferArguments(Client $client, RequestInterface $request, array $options): array
    {
        $preparedOptions = (fn (array $options): array => $this->prepareDefaults($options))
            ->call($client, $options);

        $preparedUri = (fn ($uri, array $options) => $this->buildUri($uri, $options))
            ->call($client, $request->getUri(), $preparedOptions);

        return [
            'request' => $request->withUri($preparedUri, $request->hasHeader('Host')),
            'options' => $preparedOptions,
        ];
    }
}
