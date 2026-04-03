<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Support\Facades\Http;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\ClientRequestWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class ClientRequestWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')
            ->set('telescope.watchers', [
                ClientRequestWatcher::class => [
                    'enabled' => true,
                    'ignore_hosts' => [],
                ],
            ]);

        $this->startTelescope();
    }

    public function testClientRequestWatcherRegistersSuccessfulClientRequestAndResponse()
    {
        Http::fake([
            '*' => Http::response(['foo' => 'bar'], 201, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-cache,private']),
        ]);

        Http::withHeaders(['Accept-Language' => 'nl_BE'])->get('https://hypervel.org/foo/bar');

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
        Http::fake([
            '*' => Http::response(null, 301, ['Location' => 'https://foo.bar']),
        ]);

        Http::withoutRedirecting()->get('https://hypervel.org');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertEquals('Redirected to https://foo.bar', $entry->content['response']);
    }

    public function testClientRequestWatcherPlainTextResponse()
    {
        Http::fake([
            '*' => Http::response('plain telescope response', 200, ['Content-Type' => 'text/plain']),
        ]);

        Http::get('https://hypervel.org/fake-plain-text');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame(200, $entry->content['response_status']);
        $this->assertSame('plain telescope response', $entry->content['response']);
    }

    public function testClientRequestWatcherRegistersServerErrorResponse()
    {
        Http::fake([
            '*' => Http::response(['error' => 'Something went wrong!'], 500),
        ]);

        Http::get('https://hypervel.org');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertEquals(['error' => 'Something went wrong!'], $entry->content['response']);
    }

    public function testClientRequestWatcherHidesPassword()
    {
        Http::fake([
            '*' => Http::response(null, 204),
        ]);

        Http::post('https://hypervel.org/auth', [
            'email' => 'telescope@hypervel.org',
            'password' => 'secret',
            'password_confirmation' => 'secret',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('telescope@hypervel.org', $entry->content['payload']['email']);
        $this->assertSame('********', $entry->content['payload']['password']);
        $this->assertSame('********', $entry->content['payload']['password_confirmation']);
    }

    public function testClientRequestWatcherHidesAuthorization()
    {
        Http::fake([
            '*' => Http::response(null, 204),
        ]);

        Http::withHeaders([
            'Authorization' => 'Basic YWxhZGRpbjpvcGVuc2VzYW1l',
            'Content-Type' => 'application/json',
        ])->post('https://hypervel.org/dashboard');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('application/json', $entry->content['headers']['content-type']);
        $this->assertSame('********', $entry->content['headers']['authorization']);
    }

    public function testClientRequestWatcherHidesPhpAuthPw()
    {
        Http::fake([
            '*' => Http::response(null, 204),
        ]);

        Http::withHeaders([
            'php-auth-pw' => 'secret',
        ])->post('https://hypervel.org/dashboard');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame('********', $entry->content['headers']['php-auth-pw']);
    }

    public function testClientRequestWatcherHandlesFormRequest()
    {
        Http::fake([
            '*' => Http::response(null, 204),
        ]);

        Http::asForm()->post('https://hypervel.org/form-route', ['firstname' => 'Taylor', 'lastname' => 'Otwell']);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame(['firstname' => 'Taylor', 'lastname' => 'Otwell'], $entry->content['payload']);
    }

    public function testClientRequestWatcherHandlesMultipartRequest()
    {
        Http::fake([
            '*' => Http::response(null, 204),
        ]);

        Http::asMultipart()->post('https://hypervel.org/multipart-route', ['firstname' => 'Taylor', 'lastname' => 'Otwell']);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('POST', $entry->content['method']);
        $this->assertSame(['firstname' => 'Taylor', 'lastname' => 'Otwell'], $entry->content['payload']);
    }

    public function testItStoresAndDisplaysArrayOfRequestHeaders()
    {
        Http::fake(['*' => '']);

        Http::withHeaders(['X-Foo' => 'first'])
            ->withHeaders(['X-Foo' => 'second'])
            ->withHeaders(['X-Bar' => 'single'])
            ->get('https://hypervel.org');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('first, second', $entry->content['headers']['x-foo']);
        $this->assertSame('single', $entry->content['headers']['x-bar']);
    }

    public function testClientRequestWatcherRespectsWithoutTelescope()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        Http::withoutTelescope()->get('https://hypervel.org/health');
        Http::get('https://hypervel.org/api/data');

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('https://hypervel.org/api/data', $entries->first()->content['uri']);
    }

    public function testClientRequestWatcherRecordsEmptyResponse()
    {
        Http::fake([
            '*' => Http::response('', 204),
        ]);

        Http::delete('https://hypervel.org/api/resource/1');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('DELETE', $entry->content['method']);
        $this->assertSame(204, $entry->content['response_status']);
        $this->assertSame('Empty Response', $entry->content['response']);
    }

    public function testClientRequestWatcherRecordsConnectionFailed()
    {
        Http::fake([
            '*' => Http::failedConnection(),
        ]);

        try {
            Http::get('https://unreachable.example.com/api');
        } catch (\Hypervel\Http\Client\ConnectionException) {
            // Expected.
        }

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNotNull($entry);
        $this->assertSame(EntryType::CLIENT_REQUEST, $entry->type);
        $this->assertSame('GET', $entry->content['method']);
        $this->assertSame('https://unreachable.example.com/api', $entry->content['uri']);
        $this->assertArrayNotHasKey('response_status', $entry->content);
    }

    public function testClientRequestWatcherRespectsWithoutTelescopeOnConnectionFailed()
    {
        Http::fake([
            '*' => Http::failedConnection(),
        ]);

        try {
            Http::withoutTelescope()->get('https://unreachable.example.com/api');
        } catch (\Hypervel\Http\Client\ConnectionException) {
            // Expected.
        }

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(0, $entries);
    }
}
