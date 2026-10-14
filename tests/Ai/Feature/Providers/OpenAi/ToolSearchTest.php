<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\OpenAiToolSearchAgent;
use Hypervel\Tests\Ai\TestCase;
use LogicException;

use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolSearchTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testAnAgentWithAToolSearchToolEmitsAToolSearchEntryAndDefersItsNestedTools(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('ok'),
        ]);

        (new OpenAiToolSearchAgent)->prompt('Hi');

        Http::assertSent(function (Request $request): bool {
            $tools = collect(data_get(json_decode($request->body(), true), 'tools'));

            $deferred = $tools->firstWhere('name', 'DeferredTool');
            $plain = $tools->firstWhere('name', 'NonStrictTool');

            return $tools->contains(fn (array $tool): bool => ($tool['type'] ?? null) === 'tool_search')
                && ($deferred['defer_loading'] ?? false) === true
                && ! isset($plain['defer_loading']);
        });
    }

    public function testRejectsAToolSearchToolWhenResponseStorageIsDisabled(): void
    {
        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
            'store' => false,
        ]]);

        Http::fake(['*' => fakeOpenAiResponse('ok')]);

        try {
            (new OpenAiToolSearchAgent)->prompt('Find the secret', provider: 'openai');
            $this->fail('Expected disabled response storage to reject tool search.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('store=false', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function testAnAgentWhoseOnlyToolIsAnEmptyToolSearchOmitsTheToolFields(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('ok'),
        ]);

        $agent = new class implements Agent, HasTools {
            use Promptable;

            /**
             * Get the agent instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the agent tools.
             */
            public function tools(): iterable
            {
                return [new ToolSearch];
            }
        };

        $agent->prompt('Hi', provider: 'openai', model: 'gpt-5.4');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }
}
