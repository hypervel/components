<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Http;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Telescope\Database\Factories\EntryModelFactory;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Http\Controllers\RecordingController;
use Hypervel\Telescope\Http\Middleware\Authorize;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\Telescope\FeatureTestCase;
use PHPUnit\Framework\Assert as PHPUnit;
use PHPUnit\Framework\Attributes\DataProvider;

class RouteTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(Authorize::class);

        $this->registerAssertJsonExactFragmentMacro();
    }

    #[DataProvider('telescopeIndexRoutesProvider')]
    public function testRoute(string $endpoint, string $_entryType)
    {
        $this->post($endpoint)
            ->assertSuccessful()
            ->assertJsonStructure(['entries' => []]);
    }

    #[DataProvider('telescopeIndexRoutesProvider')]
    public function testSimpleListOfEntries(string $endpoint, string $entryType)
    {
        $entry = EntryModelFactory::new()->create(['type' => $entryType]);
        $entry->refresh();

        $this->post($endpoint)
            ->assertSuccessful()
            ->assertJsonExactFragment($entry->uuid, 'entries.0.id')
            ->assertJsonExactFragment($entryType, 'entries.0.type')
            ->assertJsonExactFragment($entry->sequence, 'entries.0.sequence')
            ->assertJsonExactFragment($entry->batch_id, 'entries.0.batch_id');
    }

    public static function telescopeIndexRoutesProvider()
    {
        return [
            'Mail' => ['/telescope/telescope-api/mail', EntryType::MAIL],
            'Exceptions' => ['/telescope/telescope-api/exceptions', EntryType::EXCEPTION],
            'Dumps' => ['/telescope/telescope-api/dumps', EntryType::DUMP],
            'Logs' => ['/telescope/telescope-api/logs', EntryType::LOG],
            'Notifications' => ['/telescope/telescope-api/notifications', EntryType::NOTIFICATION],
            'Jobs' => ['/telescope/telescope-api/jobs', EntryType::JOB],
            'Events' => ['/telescope/telescope-api/events', EntryType::EVENT],
            'Cache' => ['/telescope/telescope-api/cache', EntryType::CACHE],
            'Queries' => ['/telescope/telescope-api/queries', EntryType::QUERY],
            'Models' => ['/telescope/telescope-api/models', EntryType::MODEL],
            'Request' => ['/telescope/telescope-api/requests', EntryType::REQUEST],
            'Commands' => ['/telescope/telescope-api/commands', EntryType::COMMAND],
            'Schedule' => ['/telescope/telescope-api/schedule', EntryType::SCHEDULED_TASK],
            'Redis' => ['/telescope/telescope-api/redis', EntryType::REDIS],
            'Client Requests' => ['/telescope/telescope-api/client-requests', EntryType::CLIENT_REQUEST],
            'Reverb' => ['/telescope/telescope-api/reverb', EntryType::REVERB],
        ];
    }

    public function testQueueDetailReturnsTheCompleteRelatedBatch(): void
    {
        $batchId = '84674055-d1ae-449b-9f27-a532ad669a84';
        $entry = EntryModelFactory::new()->create([
            'type' => EntryType::JOB,
            'content' => ['updated_batch_id' => $batchId],
        ]);

        EntryModelFactory::times(51)->create(['batch_id' => $batchId]);

        $response = $this->get("/telescope/telescope-api/jobs/{$entry->uuid}")
            ->assertSuccessful();

        $this->assertCount(51, $response->json('batch'));
    }

    public function testRecordingControllerUsesTheCacheRepository(): void
    {
        $cache = $this->app->make(Repository::class);
        $cache->forget('telescope:pause-recording');
        $controller = $this->app->make(RecordingController::class);

        $this->assertSame(['success' => true], $controller->toggle());
        $this->assertTrue($cache->get('telescope:pause-recording'));

        $this->assertSame(['success' => true], $controller->toggle());
        $this->assertNull($cache->get('telescope:pause-recording'));
    }

    private function registerAssertJsonExactFragmentMacro()
    {
        $assertion = function ($expected, $key) {
            $jsonResponse = $this->json(); // @phpstan-ignore-line

            PHPUnit::assertEquals(
                $expected,
                $actualValue = data_get($jsonResponse, $key),
                "Failed asserting that [{$actualValue}] matches expected [{$expected}]." . PHP_EOL . PHP_EOL
                    . json_encode($jsonResponse)
            );

            return $this;
        };

        TestResponse::macro('assertJsonExactFragment', $assertion);
    }

    public function testNamedRoute()
    {
        $this->assertEquals(
            url(config('telescope.path')),
            route('telescope')
        );
    }

    #[WithConfig('telescope.domain', 'telescope.test')]
    public function testRoutesCanBeLimitedToTheConfiguredDomain(): void
    {
        $this->post('http://telescope.test/telescope/telescope-api/mail')
            ->assertSuccessful();

        $this->post('http://other.test/telescope/telescope-api/mail')
            ->assertNotFound();
    }
}
