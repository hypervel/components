<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\HttpServer;

class LifecycleTest extends HttpServerIntegrationTestCase
{
    public function testLifecycleEventsObserveTheResponseAtTheCorrectPhase(): void
    {
        $token = bin2hex(random_bytes(8));
        $response = $this->request('GET', '/lifecycle-target?token=' . $token);
        $state = $this->decode($this->request('GET', '/lifecycle-state?token=' . $token));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('lifecycle', (string) $response->getBody());
        $this->assertSame([
            'path' => '/lifecycle-state',
            'response_is_null' => true,
        ], $state['received']);
        $this->assertSame([
            'path' => '/lifecycle-target',
            'status' => 200,
            'exception' => null,
            'response_exception' => null,
        ], $state['handled']);
        $this->assertSame([
            'path' => '/lifecycle-target',
            'status' => 200,
            'exception' => null,
            'response_exception' => null,
        ], $state['terminated']);
    }

    public function testRequestFailureIsObservedAndTheWorkerRemainsAvailable(): void
    {
        $token = bin2hex(random_bytes(8));
        $failure = $this->request('GET', '/failure?token=' . $token);
        $state = $this->decode($this->request('GET', '/lifecycle-state?token=' . $token));
        $health = $this->request('GET', '/up');

        $this->assertSame(500, $failure->getStatusCode());
        $this->assertSame([
            'path' => '/failure',
            'status' => 500,
            'exception' => null,
            'response_exception' => 'integration failure',
        ], $state['handled']);
        $this->assertSame([
            'path' => '/failure',
            'status' => 500,
            'exception' => null,
            'response_exception' => 'integration failure',
        ], $state['terminated']);
        $this->assertSame(200, $health->getStatusCode());
        $this->assertSame('up', (string) $health->getBody());
    }
}
