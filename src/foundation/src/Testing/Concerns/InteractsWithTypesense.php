<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Support\Env;
use Typesense\Client as TypesenseClient;

/**
 * Add Typesense support to an integration test.
 *
 * Use this trait on a test case and set TYPESENSE_HOST and TYPESENSE_API_KEY.
 * TYPESENSE_PORT and TYPESENSE_PROTOCOL may also be configured. Test
 * collections are isolated and cleaned up using a TEST_TOKEN-based prefix.
 */
trait InteractsWithTypesense
{
    /**
     * The test prefix for collection isolation.
     */
    protected string $typesenseTestPrefix = '';

    /**
     * The Typesense client instance.
     */
    protected ?TypesenseClient $typesense = null;

    /**
     * Set up Typesense for testing (auto-called by setUpTraits).
     *
     * Typesense integration tests are opt-in via TYPESENSE_HOST. Port, protocol,
     * and API key settings are only read after TYPESENSE_HOST is present.
     */
    protected function setUpInteractsWithTypesense(): void
    {
        // @phpstan-ignore hypervel.noEnvCallsOutsideOfConfig (integration tests read service settings from the test process environment)
        if (Env::get('TYPESENSE_HOST') === null) {
            $this->markTestSkipped(
                'Set TYPESENSE_HOST to run Typesense integration tests for ' . static::class
            );
        }

        if ($this->typesenseTestPrefix === '') {
            $this->computeTypesenseTestPrefix();
        }

        $this->initializeTypesenseClient();
        $this->typesense->health->retrieve();
        $this->cleanupTypesenseCollections();
    }

    /**
     * Tear down Typesense (auto-called via beforeApplicationDestroyed).
     */
    protected function tearDownInteractsWithTypesense(): void
    {
        if ($this->typesense === null) {
            return;
        }

        try {
            $this->cleanupTypesenseCollections();
        } finally {
            $this->typesense = null;
        }
    }

    /**
     * Initialize the Typesense client.
     */
    protected function initializeTypesenseClient(): void
    {
        $this->typesense = new TypesenseClient($this->getTypesenseClientSettings());
    }

    /**
     * Get Typesense client settings.
     *
     * @return array<string, mixed>
     */
    protected function getTypesenseClientSettings(): array
    {
        return [
            // @phpstan-ignore hypervel.noEnvCallsOutsideOfConfig (integration tests read service settings from the test process environment)
            'api_key' => Env::get('TYPESENSE_API_KEY', ''),
            'nodes' => [
                [
                    // @phpstan-ignore hypervel.noEnvCallsOutsideOfConfig (integration tests read service settings from the test process environment)
                    'host' => Env::get('TYPESENSE_HOST', '127.0.0.1'),
                    // @phpstan-ignore hypervel.noEnvCallsOutsideOfConfig (integration tests read service settings from the test process environment)
                    'port' => (string) Env::get('TYPESENSE_PORT', '8108'),
                    // @phpstan-ignore hypervel.noEnvCallsOutsideOfConfig (integration tests read service settings from the test process environment)
                    'protocol' => Env::get('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'connection_timeout_seconds' => 2,
        ];
    }

    /**
     * Compute the test prefix for parallel-safe collection names.
     */
    protected function computeTypesenseTestPrefix(): void
    {
        $base = 'test_';
        // @phpstan-ignore hypervel.noEnvCallsOutsideOfConfig (the parallel test runner sets each process's token in its environment)
        $token = Env::get('TEST_TOKEN', '');

        $this->typesenseTestPrefix = $token !== '' ? "{$base}{$token}_" : $base;
    }

    /**
     * Clean up all test collections matching the test prefix.
     */
    protected function cleanupTypesenseCollections(): void
    {
        if ($this->typesense === null) {
            return;
        }

        $collections = $this->typesense->collections->retrieve();

        foreach ($collections as $collection) {
            if (str_starts_with($collection['name'], $this->typesenseTestPrefix)) {
                $this->typesense->collections[$collection['name']]->delete();
            }
        }
    }
}
