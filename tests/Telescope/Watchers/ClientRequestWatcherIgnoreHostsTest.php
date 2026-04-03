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
class ClientRequestWatcherIgnoreHostsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')
            ->set('telescope.watchers', [
                ClientRequestWatcher::class => [
                    'enabled' => true,
                    'ignore_hosts' => ['ignored.example.com'],
                ],
            ]);

        $this->startTelescope();
    }

    public function testClientRequestWatcherIgnoresHostsInIgnoreList()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        Http::get('https://ignored.example.com/api/health');
        Http::get('https://recorded.example.com/api/data');

        $entries = $this->loadTelescopeEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('https://recorded.example.com/api/data', $entries->first()->content['uri']);
    }
}
