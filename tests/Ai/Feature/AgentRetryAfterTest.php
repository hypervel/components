<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Events\AgentFailedOver;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Sleep;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\fakeGroqResponse;

require_once __DIR__ . '/../Fixtures/helpers.php';

class AgentRetryAfterTest extends TestCase
{
    /**
     * Configure two Groq providers for failover.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);
    }

    public function testARateLimitedProviderIsRetriedAfterItsRetryAfterDelayBeforeFailingOver(): void
    {
        Event::fake();
        Sleep::fake();
        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429, headers: ['Retry-After' => '3'])
            ->pushResponse(fakeGroqResponse('Hello after waiting.'));

        $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

        $this->assertSame('Hello after waiting.', $response->text);
        $this->assertSame('primary', $response->meta->provider);

        Sleep::assertSequence([Sleep::for(3)->seconds()]);
        Event::assertNotDispatched(AgentFailedOver::class);
        Http::assertSentCount(2);
    }
}
