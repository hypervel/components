<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAop;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\DB;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\ClientRequestWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('telescope.watchers', [
    ClientRequestWatcher::class => true,
])]
class ClientRequestWatcherTest extends FeatureTestCase
{
    use InteractsWithAop;

    public function testClientRequestWatcherRegistersSuccessfulClientRequestAndResponse()
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
    }

    public function testClientRequestWatcherRegistersRedirectResponse()
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

    public function testClientRequestWatcherPlainTextResponse()
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

    public function testClientRequestWatcherRegistersServerErrorResponse()
    {
        $client = $this->makeClient([
            new Response(500, [], json_encode(['error' => 'Something went wrong!'])),
        ], ['http_errors' => false]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org'), ['http_errors' => false]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertEquals(['error' => 'Something went wrong!'], $entry->content['response']);
    }

    public function testClientRequestWatcherHidesPassword()
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

    public function testClientRequestWatcherHidesAuthorization()
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

    public function testClientRequestWatcherHidesPhpAuthPw()
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

    public function testClientRequestWatcherHandlesFormRequest()
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

    public function testClientRequestWatcherHandlesMultipartRequest()
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

    public function testClientRequestWatcherHandlesFileContentsUpload()
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

    public function testClientRequestWatcherHandlesFileContentsUploadWithoutExplicitFilenameOrHeaders()
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

    public function testClientRequestWatcherHandlesResourceFileUpload()
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

    public function testClientRequestWatcherHandlesResourceFileUploadWithFilenameAndHeaders()
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

    public function testItStoresAndDisplaysArrayOfRequestHeaders()
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

    public function testClientRequestWatcherRespectsWithoutTelescope()
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

    public function testClientRequestWatcherRecordsEmptyResponse()
    {
        $client = $this->makeClient([new Response(204, [], '')]);

        $this->executeTransfer($client, new Request('DELETE', 'https://hypervel.org/api/resource/1'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('DELETE', $entry->content['method']);
        $this->assertSame(204, $entry->content['response_status']);
        $this->assertSame('Empty Response', $entry->content['response']);
    }

    public function testClientRequestWatcherRecordsConnectionFailed()
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

    public function testClientRequestWatcherRespectsWithoutTelescopeOnConnectionFailed()
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
    public function testClientRequestWatcherIgnoresHostsInIgnoreList()
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
    public function testClientRequestWatcherPurgesLargeResponses()
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
    public function testClientRequestWatcherPurgesLargeRequestPayloads()
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
    public function testOversizedRequestPayloadMasksSensitiveFieldsBeforeTruncating()
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
    public function testOversizedRawGuzzleRequestPayloadMasksSensitiveFieldsBeforeTruncating()
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
    public function testOversizedResponseMasksSensitiveFieldsBeforeTruncating()
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
    public function testOversizedRequestPayloadIsPurgedByDefault()
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
    public function testOversizedResponseIsPurgedByDefault()
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
    public function testOversizedRedirectResponseIsNotPurged()
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
    public function testOversizedHtmlResponseIsNotPurged()
    {
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'text/html'], str_repeat('<p>content</p>', 200)),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('HTML Response', $entry->content['response']);
    }

    public function testDirectGuzzleClientRequestIsCaptured()
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
    public function testDirectGuzzleLargeRequestPayloadIsTruncated()
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

    public function testTelescopeEnabledFalsePerRequestOptOut()
    {
        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'), ['telescope_enabled' => false]);

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(0, $entries);
    }

    public function testTelescopeEnabledFalsePerClientOptOut()
    {
        $client = $this->makeClient([new Response(200, [], 'OK')], ['telescope_enabled' => false]);

        $this->executeTransfer($client, new Request('GET', 'https://example.com'));

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(0, $entries);
    }

    public function testTelescopeTagsViaGuzzleOption()
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

    public function testWithTelescopeTagsViaHttpClient()
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

    public function testExistingOnStatsCallbackIsPreserved()
    {
        $callbackFired = false;

        $client = $this->makeClient([new Response(200, [], 'OK')]);

        $this->executeTransfer(
            $client,
            new Request('GET', 'https://example.com'),
            ['on_stats' => function (TransferStats $stats) use (&$callbackFired) {
                $callbackFired = true;
            }]
        );

        $this->assertTrue($callbackFired, 'Existing on_stats callback should be preserved');

        $entry = $this->loadTelescopeEntries()->first();
        $this->assertNotNull($entry);
        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
    }

    public function testDirectGuzzleFailedConnectionIsCaptured()
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
