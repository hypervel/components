<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Streaming;

use Hypervel\Ai\Streaming\Events\ProviderToolEvent;
use Hypervel\Tests\TestCase;

class ProviderToolEventTest extends TestCase
{
    public function testSerializedProviderToolEventsKeepTheirInvocationId(): void
    {
        $event = (new ProviderToolEvent('event-1', 'search-1', 'web_search_call', ['query' => 'hypervel'], 'completed', 1, 'openai'))
            ->withInvocationId('invocation-1');

        $this->assertSame('invocation-1', json_decode((string) $event, true, flags: JSON_THROW_ON_ERROR)['invocation_id']);
    }
}
