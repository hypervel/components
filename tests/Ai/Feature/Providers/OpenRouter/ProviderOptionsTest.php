<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterToolCallResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ProviderOptionsTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    public function testProviderOptionsAreIncludedInOpenrouterRequestBody(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'frequency_penalty') === 0.5
                && data_get($body, 'presence_penalty') === 0.3;
        });
    }

    public function testRequestBodyDoesNotContainProviderOptionsWhenAgentDoesNotImplementInterface(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('frequency_penalty', $body)
                && ! array_key_exists('presence_penalty', $body);
        });
    }

    public function testAgentsUsingTheDocsIdiomHaveProviderOptionsReachTheOpenrouterBody(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        $agent = new class implements Agent, HasProviderOptions {
            use Promptable;

            /**
             * Get the agent instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the provider options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return match ($provider) {
                    Lab::OpenRouter => ['reasoning' => ['effort' => 'medium']],
                    default => [],
                };
            }
        };

        $agent->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'reasoning.effort') === 'medium';
        });
    }

    public function testProviderOptionsArePersistedInToolCallFollowUpRequests(): void
    {
        Http::fake([
            '*' => Http::sequence([
                fakeOpenRouterToolCallResponse(),
                fakeOpenRouterResponse('The number is 72019'),
            ]),
        ]);

        (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'openrouter');

        $requests = Http::recorded(fn (Request $r): true => true);

        $this->assertGreaterThanOrEqual(2, count($requests));

        $followUpBody = json_decode((string) $requests[1][0]->body(), true);

        $this->assertSame(0.5, data_get($followUpBody, 'frequency_penalty'));
    }
}
