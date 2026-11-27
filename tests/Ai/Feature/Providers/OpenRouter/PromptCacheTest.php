<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Ai\Attributes\CacheInstructions;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\PromptCacheAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class PromptCacheTest extends TestCase
{
    /**
     * Configure the OpenRouter test provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);
    }

    public function testCacheInstructionsAttributeConvertsTheSystemMessageToACachedBlock(): void
    {
        (new #[CacheInstructions] class(withTools: false) extends PromptCacheAgent {})->prompt('Hi', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            return $request->data()['messages'][0] === [
                'role' => 'system',
                'content' => [[
                    'type' => 'text',
                    'text' => 'You are a helpful assistant that generates numbers.',
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
            ];
        });
    }

    public function testARequestedTtlIsAddedToTheCacheControl(): void
    {
        (new #[CacheInstructions('1h')] class(withTools: false) extends PromptCacheAgent {})->prompt('Hi', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            return $request->data()['messages'][0]['content'][0]['cache_control'] === ['type' => 'ephemeral', 'ttl' => '1h'];
        });
    }

    public function testAnAgentWithoutTheAttributeKeepsThePlainSystemMessage(): void
    {
        (new PromptCacheAgent(withTools: false))->prompt('Hi', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            return $request->data()['messages'][0] === [
                'role' => 'system',
                'content' => 'You are a helpful assistant that generates numbers.',
            ];
        });
    }

    public function testTheBreakpointSurvivesProviderOptionsThatOverrideTheMessages(): void
    {
        $messages = [
            ['role' => 'system', 'content' => [
                ['type' => 'text', 'text' => 'Static.'],
                ['type' => 'text', 'text' => 'Overridden.'],
            ]],
            ['role' => 'user', 'content' => 'Hi'],
        ];
        $agent = new #[CacheInstructions] class(withTools: false, options: ['messages' => $messages]) extends PromptCacheAgent {};
        $agent->prompt('Hi', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $system = $request->data()['messages'][0]['content'];

            return ! isset($system[0]['cache_control'])
                && $system[1]['cache_control'] === ['type' => 'ephemeral'];
        });
    }
}
