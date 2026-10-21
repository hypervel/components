<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Gate;
use Hypervel\Support\Str;
use Hypervel\Tests\Inertia\TestCase;

class HttpEndpointsTest extends TestCase
{
    use InteractsWithDevToolsStorage;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        // The entry endpoints run the `web` middleware group, which encrypts cookies.
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $config->set('inertia.devtools.enabled', true);
        $config->set('inertia.devtools.gate', 'viewInertiaDevtools');
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindEntriesRepository();
        Gate::define('viewInertiaDevtools', fn (?Authenticatable $user = null): bool => true);
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
     * Build an entry payload.
     *
     * @return array<string, mixed>
     */
    protected function envelope(string $tab = 'tab-a', ?string $id = null, ?string $batch = null): array
    {
        $id = $id ?? (string) Str::ulid();

        return [
            '__meta' => [
                'id' => $id,
                'tabUuid' => $tab,
                'batchId' => $batch,
                'timestamp' => '2026-05-12T10:00:00.000Z',
                'utime' => microtime(true),
                'method' => 'GET',
                'url' => 'http://app.test/',
                'component' => null,
                'requestType' => 'navigate',
                'status' => 200,
                'serverTimingMs' => 0.0,
            ],
            'http' => ['requestHeaders' => [], 'responseHeaders' => [], 'requestBody' => null, 'responseBody' => null],
            'props' => [],
            'propValues' => [],
            'route' => ['name' => null, 'uri' => '', 'action' => null],
        ];
    }

    public function testShowReturnsFullEntryOr404(): void
    {
        $entry = $this->envelope();
        $id = $entry['__meta']['id'];
        $this->repo->save($id, $entry);

        $this->getJson("/_inertia/devtools/entries/{$id}")
            ->assertOk()
            ->assertJsonPath('__meta.id', $id);

        $this->getJson('/_inertia/devtools/entries/missing')
            ->assertStatus(404);
    }

    public function testShowResolvesUnderTheBasePathAnAppIsServedFrom(): void
    {
        $entry = $this->envelope();
        $id = $entry['__meta']['id'];
        $this->repo->save($id, $entry);

        $this->call('GET', "/portal/_inertia/devtools/entries/{$id}", server: [
            'SCRIPT_FILENAME' => '/var/www/app/public/index.php',
            'SCRIPT_NAME' => '/portal/index.php',
            'PHP_SELF' => '/portal/index.php',
        ])
            ->assertOk()
            ->assertJsonPath('__meta.id', $id);
    }

    public function testShowRejectsNonEntryIdsBeforeLookup(): void
    {
        $this->getJson('/_inertia/devtools/entries/../secret')
            ->assertStatus(404);
    }

    public function testIndexFiltersByComponentTypeExcludeOffsetAndLimit(): void
    {
        $ids = [];

        foreach ([
            ['component' => 'Users/Index', 'requestType' => 'navigate'],
            ['component' => 'Users/Index', 'requestType' => 'partial'],
            ['component' => 'Users/Index', 'requestType' => 'poll'],
            ['component' => 'Posts/Index', 'requestType' => 'navigate'],
        ] as $i => $overrides) {
            $id = (string) Str::ulid();
            $ids[] = $id;
            $meta = array_merge(['id' => $id], $overrides);
            $entry = $this->envelope();
            $entry['__meta'] = array_merge($entry['__meta'], $meta);
            $this->repo->save($id, $entry);
        }

        $this->getJson('/_inertia/devtools/entries?component=Users/Index')
            ->assertJsonCount(3)
            ->assertJsonMissing(['component' => 'Posts/Index']);

        $this->getJson('/_inertia/devtools/entries?type=navigate,partial')
            ->assertJsonCount(3)
            ->assertJsonMissing(['requestType' => 'poll']);

        $this->getJson('/_inertia/devtools/entries?exclude=poll')
            ->assertJsonCount(3)
            ->assertJsonMissing(['requestType' => 'poll']);

        $this->getJson('/_inertia/devtools/entries?limit=2')->assertJsonCount(2);
        $this->getJson('/_inertia/devtools/entries?offset=1&limit=2')->assertJsonCount(2);
    }
}
