<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeGroqResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeGroqToolCallResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ProviderOptionsTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.groq.key', 'test-key');
    }

    public function testProviderOptionsAreIncludedInGroqRequestBody(): void
    {
        Http::fake([
            '*' => fakeGroqResponse('Hello'),
        ]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'frequency_penalty') === 0.5
                && data_get($body, 'presence_penalty') === 0.3;
        });
    }

    public function testRequestBodyDoesNotContainProviderOptionsWhenAgentDoesNotImplementInterface(): void
    {
        Http::fake([
            '*' => fakeGroqResponse('Hello'),
        ]);

        agent()->prompt('Hello', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('reasoning', $body)
                && ! array_key_exists('frequency_penalty', $body)
                && ! array_key_exists('presence_penalty', $body);
        });
    }

    public function testProviderOptionsArePersistedInToolCallFollowUpRequests(): void
    {
        Http::fake([
            '*' => Http::sequence([
                fakeGroqToolCallResponse(),
                fakeGroqResponse('The number is 72019'),
            ]),
        ]);

        (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'groq');

        $requests = Http::recorded(fn (Request $r): true => true);

        $this->assertGreaterThanOrEqual(2, count($requests));

        $followUpBody = json_decode((string) $requests[1][0]->body(), true);

        $this->assertSame(0.5, data_get($followUpBody, 'frequency_penalty'));
    }
}
