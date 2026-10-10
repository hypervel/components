<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;

class NativeStreamResponseTest extends TestCase
{
    public function testAStreamedResponseIsSentWithHeadersThatStopAProxyBufferingIt(): void
    {
        AssistantAgent::fake(['Hello world']);

        $response = (new AssistantAgent)->stream('Hi')->toResponse(request());

        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-transform', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }
}
