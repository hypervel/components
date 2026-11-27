<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Streaming;

use Hypervel\Ai\Streaming\Events\ProviderToolEvent;
use Hypervel\Tests\TestCase;

class ProviderToolEventTest extends TestCase
{
    public function testAProviderToolEventIncludesTheInvocationItBelongsTo(): void
    {
        $event = (new ProviderToolEvent('event-1', 'search-1', 'web_search_call', ['query' => 'Hypervel'], 'in_progress', 100, 'openai'))
            ->withInvocationId('invocation-1');

        $this->assertSame([
            'id' => 'event-1',
            'invocation_id' => 'invocation-1',
            'item_id' => 'search-1',
            'type' => 'web_search_call',
            'data' => ['query' => 'Hypervel'],
            'status' => 'in_progress',
            'timestamp' => 100,
            'provider' => 'openai',
        ], $event->toArray());
        $this->assertSame('invocation-1', json_decode((string) $event, true, flags: JSON_THROW_ON_ERROR)['invocation_id']);
    }
}
