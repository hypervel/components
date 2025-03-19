<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Http;

use Hypervel\Foundation\Testing\Http\TestResponse;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Http\Middleware\Authorize;
use Hypervel\Tests\Telescope\FeatureTestCase;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @internal
 * @coversNothing
 */
class RouteTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(Authorize::class);

        $this->loadServiceProviders();
        $this->registerAssertJsonExactFragmentMacro();
    }

    /**
     * @dataProvider telescopeIndexRoutesProvider
     */
    public function testRoute(string $endpoint)
    {
        $this->post($endpoint)
            ->assertSuccessful()
            ->assertJsonStructure(['entries' => []]);
    }

    /**
     * @dataProvider telescopeIndexRoutesProvider
     */
    public function testSimpleListOfEntries(string $endpoint, string $entryType)
    {
        $entry = $this->createEntry(['type' => $entryType]);

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
        ];
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
}
