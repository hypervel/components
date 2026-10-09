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
use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Json;
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
    }

    public function testClientRequestWatcherHidesNestedFalseySecrets(): void
    {
        Telescope::hideRequestHeaders(['x-zero']);
        Telescope::hideRequestParameters(['secret.zero', 'secret.false', 'secret.empty', 'secret.null']);
        Telescope::hideResponseParameters(['secret.zero', 'secret.false', 'secret.empty', 'secret.null']);

        $payload = ['secret' => [
            'zero' => 0,
            'false' => false,
            'empty' => '',
            'null' => null,
        ]];
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($payload)),
        ]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org', ['X-Zero' => '0'], json_encode($payload)),
            ['hypervel_data' => $payload],
        );

        $entry = $this->loadTelescopeEntries()->first();
        $masked = [
            'zero' => '********',
            'false' => '********',
            'empty' => '********',
            'null' => '********',
        ];

        $this->assertSame('********', $entry->content['headers']['x-zero']);
        $this->assertSame($masked, $entry->content['payload']['secret']);
        $this->assertSame($masked, $entry->content['response']['secret']);
    }

    public function testStructuredRequestAndResponseRetainAndMaskAtTheEntryContentChildLimit(): void
    {
        Telescope::hideRequestParameters(['password']);
        Telescope::hideResponseParameters(['password']);

        $payload = [
            'password' => 'secret',
            'nested' => $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH - 2),
        ];
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'], Json::encode($payload)),
        ]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org', ['Content-Type' => 'application/json'], Json::encode($payload)),
            ['hypervel_data' => $payload],
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('********', $entry->content['payload']['password']);
        $this->assertSame($payload['nested'], $entry->content['payload']['nested']);
        $this->assertSame('********', $entry->content['response']['password']);
        $this->assertSame($payload['nested'], $entry->content['response']['nested']);
    }

    public function testStructuredRequestAndMislabeledResponsePurgeOverTheEntryContentChildLimit(): void
    {
        $payload = [
            'password' => 'secret',
            'nested' => $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH - 1),
        ];
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'text/html'], Json::encode($payload)),
        ]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org', ['Content-Type' => 'application/json'], Json::encode($payload)),
            ['hypervel_data' => $payload],
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('Purged By Telescope', $entry->content['payload']);
        $this->assertSame('Purged By Telescope', $entry->content['response']);
    }

    public function testRawBodyUsesThePsrPayloadFallback(): void
    {
        Telescope::hideRequestParameters(['password']);
        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request(
                'POST',
                'https://hypervel.org/raw',
                ['Content-Type' => 'application/json'],
                '{"password":"secret","name":"Taylor"}',
            ),
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame([
            'password' => '********',
            'name' => 'Taylor',
        ], $entry->content['payload']);
    }

    public function testRawJsonRequestRetainsAndMasksAtTheEntryContentChildLimit(): void
    {
        Telescope::hideRequestParameters(['password']);
        $payload = [
            'password' => 'secret',
            'nested' => $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH - 2),
        ];
        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/raw', ['Content-Type' => 'application/json'], Json::encode($payload)),
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('********', $entry->content['payload']['password']);
        $this->assertSame($payload['nested'], $entry->content['payload']['nested']);
    }

    #[DataProvider('jsonContentTypeProvider')]
    public function testMalformedDeclaredJsonRequestIsPurged(string $contentType): void
    {
        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/raw', ['Content-Type' => $contentType], '{"password":"secret"'),
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('Purged By Telescope', $entry->content['payload']);
    }

    public static function jsonContentTypeProvider(): array
    {
        return [
            ['application/json'],
            ['application/problem+json'],
        ];
    }

    #[DataProvider('jsonMediaTypeRequestProvider')]
    public function testValidJsonMediaTypeRequestIsDecoded(string $contentType, string $body, array $payload): void
    {
        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/raw', ['Content-Type' => $contentType], $body),
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame($payload, $entry->content['payload']);
    }

    /**
     * Provide JSON media types and payloads.
     */
    public static function jsonMediaTypeRequestProvider(): array
    {
        return [
            'hal+json' => [
                'application/hal+json',
                '{"_links":{"self":{"href":"/api/users/1"}},"name":"Test"}',
                ['_links' => ['self' => ['href' => '/api/users/1']], 'name' => 'Test'],
            ],
            'hal+json with charset' => ['application/hal+json; charset=utf-8', '{"id":1}', ['id' => 1]],
            'vnd.api+json' => [
                'application/vnd.api+json',
                '{"data":{"type":"users","id":"1"}}',
                ['data' => ['type' => 'users', 'id' => '1']],
            ],
        ];
    }

    public function testHeaderlessJsonRequestIsMaskedAndDeepHeaderlessJsonIsPurged(): void
    {
        Telescope::hideRequestParameters(['password']);
        $client = $this->makeClient([
            new Response(204),
            new Response(204),
        ]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/shallow', body: '{"password":"secret"}'),
        );
        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/deep', body: "\t\n" . Json::encode([
                'password' => 'secret',
                'nested' => $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH - 1),
            ])),
        );

        $entries = $this->loadTelescopeEntries()->keyBy(fn ($entry) => $entry->content['uri']);

        $this->assertSame('********', $entries['https://hypervel.org/shallow']->content['payload']['password']);
        $this->assertSame('Purged By Telescope', $entries['https://hypervel.org/deep']->content['payload']);
    }

    public function testRawUrlEncodedRequestMasksNestedFields(): void
    {
        Telescope::hideRequestParameters(['password', 'account.password']);
        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request(
                'POST',
                'https://hypervel.org/form',
                ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'],
                'password=secret&account[password]=nested-secret&name=Taylor',
            ),
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('********', $entry->content['payload']['password']);
        $this->assertSame('********', $entry->content['payload']['account']['password']);
        $this->assertSame('Taylor', $entry->content['payload']['name']);
    }

    public function testExplicitPlainTextRequestRetainsItsRawBody(): void
    {
        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/text', ['Content-Type' => 'text/plain'], 'password=secret'),
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('password=secret', $entry->content['payload']);
    }

    public function testValidScalarJsonRequestRetainsItsRawRepresentation(): void
    {
        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/scalar', ['Content-Type' => 'application/json'], '42'),
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('42', $entry->content['payload']);
    }

    public function testUnencodableStructuredRequestIsPurged(): void
    {
        $client = $this->makeClient([new Response(204)]);

        $this->executeTransfer(
            $client,
            new Request('POST', 'https://hypervel.org/invalid'),
            ['hypervel_data' => ['invalid' => INF]],
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('Purged By Telescope', $entry->content['payload']);
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

    #[DataProvider('scalarResponseProvider')]
    public function testScalarResponseIsRecordedAsSentUnderAJsonMediaType(string $contentType, string $body, string $recorded): void
    {
        $client = $this->makeClient([new Response(200, ['Content-Type' => $contentType], $body)]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org/scalar'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame($recorded, $entry->content['response']);
    }

    /**
     * Provide scalar response bodies and their recorded values.
     */
    public static function scalarResponseProvider(): array
    {
        return [
            'zero' => ['application/json', '0', '0'],
            'false' => ['application/json', 'false', 'false'],
            'null' => ['application/json', 'null', 'null'],
            'string' => ['application/json', '"ok"', '"ok"'],
            '+json with charset' => ['application/problem+json; charset=utf-8', '42', '42'],
            'malformed json' => ['application/json', '{"id":', 'HTML Response'],
            'html' => ['text/html', '0', 'HTML Response'],
        ];
    }

    public function testHalJsonResponseIsDecodedAndMasked(): void
    {
        Telescope::hideResponseParameters(['access_token']);
        $client = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/hal+json'], json_encode([
                '_links' => ['self' => ['href' => '/api/users/1']],
                'name' => 'Test',
                'access_token' => 'secret-token-value',
                'expires_in' => 3600,
            ])),
        ]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org/hal'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame([
            '_links' => ['self' => ['href' => '/api/users/1']],
            'name' => 'Test',
            'access_token' => '********',
            'expires_in' => 3600,
        ], $entry->content['response']);
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

    public function testClientRequestWatcherHostIgnoreCanBeExtended(): void
    {
        $watcher = new class extends ClientRequestWatcher {
            public function ignores(string $host): bool
            {
                return $this->shouldIgnoreHost($host);
            }

            protected function shouldIgnoreHost(string $host): bool
            {
                return str_ends_with($host, '.internal');
            }
        };

        $this->assertTrue($watcher->ignores('api.internal'));
        $this->assertFalse($watcher->ignores('hypervel.org'));
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
    public function testOversizedScalarJsonResponseIsPurgedByDefault(): void
    {
        $body = '"' . str_repeat('x', 2000) . '"';
        $client = $this->makeClient([new Response(200, ['Content-Type' => 'application/json'], $body)]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org/raw'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('Purged By Telescope', $entry->content['response']);
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => [
            'enabled' => true,
            'response_size_limit' => 1,
            'truncate_oversized' => true,
        ],
    ])]
    public function testOversizedPlainTextResponseIsTruncated(): void
    {
        $body = str_repeat('x', 2000);
        $client = $this->makeClient([new Response(200, ['Content-Type' => 'text/plain'], $body)]);

        $this->executeTransfer($client, new Request('GET', 'https://hypervel.org/raw'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(substr($body, 0, 1024) . ' (truncated...)', $entry->content['response']);
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
            ['on_stats' => function (TransferStats $stats) use (&$callbackFired) {
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

    private function nestedValue(int $depth): array
    {
        $value = 'leaf';

        for ($index = 0; $index < $depth; ++$index) {
            $value = ['value' => $value];
        }

        return $value;
    }

    /**
     * Send the request through the generated proxy, including failed connections.
     */
    private function executeTransfer(
        Client $client,
        RequestInterface $request,
        array $options = [],
    ): void {
        try {
            $client->send($request, $options);
        } catch (ConnectException) {
            // Expected for failed connection tests.
        }
    }
}
