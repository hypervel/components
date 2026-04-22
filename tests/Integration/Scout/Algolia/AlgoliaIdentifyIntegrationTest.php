<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Algolia;

use Algolia\AlgoliaSearch\Algolia;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\AlgoliaEngine;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Real-wire regression test for the identify divergence from Laravel.
 *
 * Laravel bakes X-Forwarded-For / X-Algolia-UserToken into default client
 * headers at driver-creation time, which breaks on a persistent worker
 * where the engine is cached across requests. Hypervel computes these
 * headers per-call from the current RequestContext and passes them via
 * the searchSingleIndex $requestOptions['headers'] parameter.
 *
 * This test seeds two different requests and verifies that each search's
 * outgoing HTTP request carries its own X-Forwarded-For — captured via
 * the Algolia SDK's PSR-3 debug logger.
 */
class AlgoliaIdentifyIntegrationTest extends AlgoliaScoutIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('scout.identify', true);
    }

    public function testIdentifyHeadersAreSentPerRequestNotBakedIn(): void
    {
        // Capture outgoing request headers via the SDK's debug logger.
        $logger = new class extends AbstractLogger {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (str_contains((string) $message, 'Request headers:')) {
                    $this->records[] = $context;
                }
            }
        };

        Algolia::setLogger($logger);

        // Re-resolve the engine so it picks up the identify=true config.
        $this->app->make(EngineManager::class)->forgetEngines();
        /** @var AlgoliaEngine $engine */
        $engine = $this->app->make(EngineManager::class)->engine('algolia');

        // Materialise an index to query (contents don't matter — we're
        // asserting on the outgoing HTTP headers, not the response).
        SearchableModel::create(['title' => 'Probe', 'body' => 'x']);
        $indexName = (new SearchableModel)->searchableAs();
        $this->pollIndexExists($indexName);

        $builder = SearchableModel::search('');

        // Phase A: request from 203.0.113.10
        try {
            RequestContext::set(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']));
            $engine->search($builder);
        } finally {
            RequestContext::forget();
        }

        // Phase B: request from 198.51.100.20
        try {
            RequestContext::set(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.20']));
            $engine->search($builder);
        } finally {
            RequestContext::forget();
        }

        $forwardedFor = array_values(array_filter(array_map(
            fn (array $context) => $this->extractHeader($context['headers'] ?? [], 'X-Forwarded-For'),
            $logger->records,
        )));

        $this->assertContains('203.0.113.10', $forwardedFor, 'Phase A header missing from captured requests');
        $this->assertContains('198.51.100.20', $forwardedFor, 'Phase B header missing from captured requests');

        Algolia::setLogger(new \Algolia\AlgoliaSearch\Log\DebugLogger);
    }

    /**
     * Extract a header value case-insensitively from the Algolia log context.
     *
     * Algolia's RequestOptionsFactory lowercases header keys before merging,
     * so the captured context typically uses lowercase keys.
     */
    protected function extractHeader(array $headers, string $name): ?string
    {
        $lower = strtolower($name);

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $lower) {
                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * Poll until the index appears in listIndices or timeout.
     */
    protected function pollIndexExists(string $index, int $timeoutMs = 15000): void
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $response = $this->algolia->listIndices();
            $names = collect($response['items'] ?? [])->pluck('name')->all();

            if (in_array($index, $names, true)) {
                return;
            }

            usleep(200_000);
        }
    }
}
