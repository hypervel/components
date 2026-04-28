<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Hypervel\Scout\Engines\MeilisearchRetryPolicy;
use Hypervel\Tests\TestCase;

/**
 * Behavior tests for the Guzzle retry middleware produced by
 * MeilisearchRetryPolicy::middleware().
 *
 * Tests construct a Guzzle client with the same handler stack the production
 * binding uses (MeilisearchRetryPolicy::middleware()), but with MockHandler
 * at the bottom of the stack instead of a real network handler. This
 * verifies actual retry behavior — request counts, response handling,
 * exception bubbling — rather than middleware presence.
 *
 * Attempt counts are derived from MockHandler::count(), which returns the
 * number of remaining queued items. After a request, consumed = initial
 * queue length - count().
 *
 * A small base delay (1 ms) is used so test runtime stays under a few ms
 * even when many retries fire.
 */
class MeilisearchRetryMiddlewareTest extends TestCase
{
    public function testEngineSmokeTestRetriesOn5xxThenSucceeds()
    {
        $mock = new MockHandler([
            new Response(500),
            new Response(200, [], '{"ok":true}'),
        ]);
        $client = $this->buildClient($mock, maxRetries: 3);

        $response = $client->post('/test', ['body' => '{}']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $mock->count(), 'all queued responses consumed');
    }

    public function test500ThenSuccessRetriesOnce()
    {
        $mock = new MockHandler([
            new Response(500),
            new Response(200),
        ]);
        $client = $this->buildClient($mock, maxRetries: 3);

        $response = $client->post('/x');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $mock->count(), '2 attempts consumed');
    }

    public function test429ThenSuccessRetriesOnce()
    {
        $mock = new MockHandler([
            new Response(429),
            new Response(200),
        ]);
        $client = $this->buildClient($mock, maxRetries: 3);

        $response = $client->post('/x');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $mock->count(), '2 attempts consumed');
    }

    public function test400IsNotRetried()
    {
        $mock = new MockHandler([
            new Response(400),
            // Extra response queued so the test can detect any unexpected retry.
            new Response(200),
        ]);
        $client = $this->buildClient($mock, maxRetries: 3);

        $response = $client->post('/x', ['http_errors' => false]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $mock->count(), 'only the first response was consumed (no retry)');
    }

    public function testConnectionExceptionThenSuccessRetriesOnce()
    {
        $request = new Psr7Request('POST', 'http://example.test/x');
        $mock = new MockHandler([
            new ConnectException('Connection refused', $request),
            new Response(200),
        ]);
        $client = $this->buildClient($mock, maxRetries: 3);

        $response = $client->post('/x');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $mock->count(), '2 attempts consumed');
    }

    public function testRepeated5xxStopsAtConfiguredRetries()
    {
        $mock = new MockHandler([
            new Response(500),
            new Response(500),
            new Response(500),
            new Response(500),
            // Extra response queued so the test can detect if retry exceeds the cap.
            new Response(200),
        ]);
        $client = $this->buildClient($mock, maxRetries: 3);

        $thrown = null;
        try {
            $client->post('/x');
        } catch (ServerException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertSame(500, $thrown->getResponse()->getStatusCode());
        $this->assertSame(1, $mock->count(), '4 attempts consumed (1 initial + 3 retries); 5th response untouched');
    }

    public function testRetriesZeroDisablesRetrying()
    {
        $mock = new MockHandler([
            new Response(500),
            // Extra response queued so the test can detect any unexpected retry.
            new Response(200),
        ]);
        $client = $this->buildClient($mock, maxRetries: 0);

        $thrown = null;
        try {
            $client->post('/x');
        } catch (ServerException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertSame(1, $mock->count(), 'only the first response was consumed (retries disabled)');
    }

    public function testHappyPathDoesNotRetryWhenFirstAttemptSucceeds()
    {
        $mock = new MockHandler([
            new Response(200),
            // Extra response queued so the test can detect any unexpected retry.
            new Response(500),
        ]);
        $client = $this->buildClient($mock, maxRetries: 3);

        $response = $client->post('/x');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $mock->count(), 'only the first response was consumed (no retry needed)');
    }

    public function testCombinedRetryableConditionsAcrossAttempts()
    {
        $request = new Psr7Request('POST', 'http://example.test/x');
        $mock = new MockHandler([
            new ConnectException('Connection refused', $request),
            new Response(500),
            new Response(429),
            new Response(200),
        ]);
        $client = $this->buildClient($mock, maxRetries: 3);

        $response = $client->post('/x');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $mock->count(), 'all 4 attempts consumed');
    }

    public function testRequestPreservedAcrossRetries()
    {
        $container = [];
        $mock = new MockHandler([
            new Response(500),
            new Response(500),
            new Response(200),
        ]);

        $stack = HandlerStack::create($mock);

        // Push retry FIRST and history SECOND so history sits inside retry. Per
        // HandlerStack::resolve() (uses array_reverse), the last pushed entry
        // becomes the innermost wrapper. With this order, retry's recursive
        // calls go back through history on every attempt — so each retry is
        // captured rather than only the initial request.
        $stack->push(MeilisearchRetryPolicy::middleware(maxRetries: 3, baseDelayMs: 1));
        $stack->push(GuzzleMiddleware::history($container));

        $client = new GuzzleClient(['handler' => $stack]);
        $client->post('/index/posts/documents', [
            'headers' => [
                'Authorization' => 'Bearer master-key',
                'Content-Type' => 'application/json',
            ],
            'body' => '{"id":42,"title":"hello"}',
        ]);

        $this->assertCount(3, $container);

        // Extract just the request snapshots for comparison.
        $snapshots = array_map(fn ($transaction) => [
            'method' => $transaction['request']->getMethod(),
            'path' => $transaction['request']->getUri()->getPath(),
            'authorization' => $transaction['request']->getHeaderLine('Authorization'),
            'content_type' => $transaction['request']->getHeaderLine('Content-Type'),
            'body' => (string) $transaction['request']->getBody(),
        ], $container);

        // All three attempts must carry identical method, path, headers, and body.
        $this->assertSame($snapshots[0], $snapshots[1]);
        $this->assertSame($snapshots[1], $snapshots[2]);

        // Spot-check the values match what was originally sent.
        $this->assertSame('POST', $snapshots[0]['method']);
        $this->assertSame('/index/posts/documents', $snapshots[0]['path']);
        $this->assertSame('Bearer master-key', $snapshots[0]['authorization']);
        $this->assertSame('application/json', $snapshots[0]['content_type']);
        $this->assertSame('{"id":42,"title":"hello"}', $snapshots[0]['body']);
    }

    /**
     * Build a Guzzle client whose handler stack matches the production binding,
     * but with the given MockHandler at the bottom instead of a real handler.
     */
    private function buildClient(MockHandler $mock, int $maxRetries): GuzzleClient
    {
        $stack = HandlerStack::create($mock);
        if ($maxRetries > 0) {
            $stack->push(MeilisearchRetryPolicy::middleware($maxRetries, baseDelayMs: 1));
        }
        return new GuzzleClient(['handler' => $stack]);
    }
}
