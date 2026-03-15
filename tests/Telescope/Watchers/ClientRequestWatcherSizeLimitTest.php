<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Support\Facades\Http;
use Hypervel\Telescope\Watchers\ClientRequestWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class ClientRequestWatcherSizeLimitTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')
            ->set('telescope.watchers', [
                ClientRequestWatcher::class => [
                    'enabled' => true,
                    'request_size_limit' => 1,
                    'response_size_limit' => 1,
                ],
            ]);

        $this->startTelescope();
    }

    public function testClientRequestWatcherPurgesLargeResponses()
    {
        $largeBody = json_encode(['data' => str_repeat('x', 2000)]);

        Http::fake([
            '*' => Http::response($largeBody, 200, ['Content-Type' => 'application/json']),
        ]);

        Http::get('https://hypervel.org/large-response');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('Purged By Telescope', $entry->content['response']);
    }

    public function testClientRequestWatcherPurgesLargeRequestPayloads()
    {
        Http::fake([
            '*' => Http::response(null, 204),
        ]);

        Http::post('https://hypervel.org/large-payload', [
            'data' => str_repeat('x', 2000),
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame('Purged By Telescope', $entry->content['payload']);
    }
}
