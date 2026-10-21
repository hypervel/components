<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\Attributes\MaxTokens;
use Hypervel\Ai\Attributes\Temperature;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Gateway\Groq\GroqGateway;
use Hypervel\Ai\Gateway\OpenAi\OpenAiGateway;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Providers\GroqProvider;
use Hypervel\Ai\Providers\OpenAiProvider;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

#[Temperature(0.2)]
#[MaxTokens(1024)]
class TextGenOptionsToolAgent implements Agent, HasTools
{
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
        return [new FixedNumberGenerator];
    }
}

function textGenFakeOpenAiToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'resp_tool_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_123',
            'call_id' => 'call_123',
            'name' => 'FixedNumberGenerator',
            'arguments' => '{}',
            'status' => 'completed',
        ]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function textGenFakeOpenAiTextResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ]);
}

function textGenFakeGroqToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ]);
}

function textGenFakeGroqTextResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $text],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ]);
}

class TextGenerationOptionsInToolLoopTest extends TestCase
{
    public function testOpenaiTemperatureAndMaxTokensArePreservedInToolCallFollowUp(): void
    {
        Http::fake([
            '*' => Http::sequence([
                textGenFakeOpenAiToolCallResponse(),
                textGenFakeOpenAiTextResponse('The number is 72019'),
            ]),
        ]);

        config(['ai.providers.openai.key' => 'test-key']);

        $gateway = new OpenAiGateway(app(Dispatcher::class));
        $manager = app(AiManager::class);
        $manager->purge('openai');
        $manager->extend('openai', fn (Application $app, array $config): OpenAiProvider => new OpenAiProvider(
            $gateway,
            $config,
            app(Dispatcher::class),
        ));

        (new TextGenOptionsToolAgent)->prompt('Give me a number', provider: 'openai');

        Http::assertSentInOrder([
            fn (Request $request): bool => $request['temperature'] === 0.2
                && $request['max_output_tokens'] === 1024,
            fn (Request $request): bool => $request['temperature'] === 0.2
                && $request['max_output_tokens'] === 1024
                && isset($request['previous_response_id']),
        ]);
    }

    public function testGroqTemperatureAndMaxTokensArePreservedInToolCallFollowUp(): void
    {
        Http::fake([
            '*' => Http::sequence([
                textGenFakeGroqToolCallResponse(),
                textGenFakeGroqTextResponse('The number is 72019'),
            ]),
        ]);

        config(['ai.providers.groq.key' => 'test-key']);

        $gateway = new GroqGateway(app(Dispatcher::class));
        $manager = app(AiManager::class);
        $manager->purge('groq');
        $manager->extend('groq', function (Application $app, array $config) use ($gateway): GroqProvider {
            $provider = new GroqProvider($config, app(Dispatcher::class));
            $provider->useTextGateway($gateway);

            return $provider;
        });

        (new TextGenOptionsToolAgent)->prompt('Give me a number', provider: 'groq');

        Http::assertSentInOrder([
            fn (Request $request): bool => $request['temperature'] === 0.2
                && $request['max_completion_tokens'] === 1024,
            fn (Request $request): bool => $request['temperature'] === 0.2
                && $request['max_completion_tokens'] === 1024,
        ]);
    }
}
