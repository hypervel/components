<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\Attributes\Timeout;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Gateway\Anthropic\AnthropicGateway;
use Hypervel\Ai\Gateway\Gemini\GeminiGateway;
use Hypervel\Ai\Gateway\Groq\GroqGateway;
use Hypervel\Ai\Gateway\OpenAi\OpenAiGateway;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Providers\AnthropicProvider;
use Hypervel\Ai\Providers\GeminiProvider;
use Hypervel\Ai\Providers\GroqProvider;
use Hypervel\Ai\Providers\OpenAiProvider;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use Override;

#[Timeout(300)]
class TimeoutToolAgent implements Agent, HasTools
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

class SpyOpenAiGateway extends OpenAiGateway
{
    public array $capturedTimeouts = [];

    /**
     * Record the timeout supplied to each request.
     */
    #[Override]
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}

class SpyAnthropicGateway extends AnthropicGateway
{
    public array $capturedTimeouts = [];

    /**
     * Record the timeout supplied to each request.
     */
    #[Override]
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}

class SpyGroqGateway extends GroqGateway
{
    public array $capturedTimeouts = [];

    /**
     * Record the timeout supplied to each request.
     */
    #[Override]
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}

class SpyGeminiGateway extends GeminiGateway
{
    public array $capturedTimeouts = [];

    /**
     * Record the timeout supplied to each request.
     */
    #[Override]
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}

function timeoutFakeOpenAiToolCallResponse(): PromiseInterface
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

function timeoutFakeOpenAiTextResponse(string $text): PromiseInterface
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

function timeoutFakeAnthropicToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'msg_tool_123',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [[
            'type' => 'tool_use',
            'id' => 'toolu_123',
            'name' => 'FixedNumberGenerator',
            'input' => (object) [],
        ]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function timeoutFakeAnthropicTextResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'msg_123',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function timeoutFakeGroqToolCallResponse(): PromiseInterface
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

function timeoutFakeGroqTextResponse(string $text): PromiseInterface
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

function timeoutFakeGeminiToolCallResponse(): PromiseInterface
{
    return Http::response(timeoutFakeGeminiInteraction([[
        'type' => 'function_call',
        'id' => 'call_123',
        'name' => 'FixedNumberGenerator',
        'arguments' => (object) [],
    ]]));
}

function timeoutFakeGeminiTextResponse(string $text): PromiseInterface
{
    return Http::response(timeoutFakeGeminiInteraction([[
        'type' => 'model_output',
        'content' => [['type' => 'text', 'text' => $text]],
    ]]));
}

function timeoutFakeGeminiInteraction(array $steps): array
{
    return [
        'id' => 'int_timeout',
        'model' => 'gemini-3.5-flash',
        'status' => 'completed',
        'steps' => $steps,
        'usage' => [
            'total_input_tokens' => 10,
            'total_output_tokens' => 5,
            'total_tokens' => 15,
        ],
    ];
}

class TimeoutInToolLoopTest extends TestCase
{
    public function testOpenaiTimeoutIsPreservedInToolCallFollowUp(): void
    {
        Http::fake([
            '*' => Http::sequence([
                timeoutFakeOpenAiToolCallResponse(),
                timeoutFakeOpenAiTextResponse('The number is 72019'),
            ]),
        ]);

        config(['ai.providers.openai.key' => 'test-key']);

        $spy = new SpyOpenAiGateway(app(Dispatcher::class));
        $manager = app(AiManager::class);
        $manager->purge('openai');
        $manager->extend('openai', fn (Application $app, array $config): OpenAiProvider => new OpenAiProvider(
            $spy,
            $config,
            app(Dispatcher::class),
        ));

        (new TimeoutToolAgent)->prompt('Give me a number', provider: 'openai');

        $this->assertSame([300, 300], $spy->capturedTimeouts);
    }

    public function testAnthropicTimeoutIsPreservedInToolCallFollowUp(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                timeoutFakeAnthropicToolCallResponse(),
                timeoutFakeAnthropicTextResponse('The number is 72019'),
            ]),
        ]);

        config(['ai.providers.anthropic.key' => 'test-key']);

        $spy = new SpyAnthropicGateway(app(Dispatcher::class));
        $manager = app(AiManager::class);
        $manager->purge('anthropic');
        $manager->extend('anthropic', fn (Application $app, array $config): AnthropicProvider => new AnthropicProvider(
            $spy,
            $config,
            app(Dispatcher::class),
        ));

        (new TimeoutToolAgent)->prompt('Give me a number', provider: 'anthropic');

        $this->assertSame([300, 300], $spy->capturedTimeouts);
    }

    public function testGroqTimeoutIsPreservedInToolCallFollowUp(): void
    {
        Http::fake([
            '*' => Http::sequence([
                timeoutFakeGroqToolCallResponse(),
                timeoutFakeGroqTextResponse('The number is 72019'),
            ]),
        ]);

        config(['ai.providers.groq.key' => 'test-key']);

        $spy = new SpyGroqGateway(app(Dispatcher::class));

        $manager = app(AiManager::class);
        $manager->purge('groq');
        $manager->extend('groq', function (Application $app, array $config) use ($spy): GroqProvider {
            $provider = new GroqProvider($config, app(Dispatcher::class));
            $provider->useTextGateway($spy);

            return $provider;
        });

        (new TimeoutToolAgent)->prompt('Give me a number', provider: 'groq');

        $this->assertSame([300, 300], $spy->capturedTimeouts);
    }

    public function testGeminiTimeoutIsPreservedInToolCallFollowUp(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                timeoutFakeGeminiToolCallResponse(),
                timeoutFakeGeminiTextResponse('The number is 72019'),
            ]),
        ]);

        config(['ai.providers.gemini.key' => 'test-key']);

        $spy = new SpyGeminiGateway(app(Dispatcher::class));
        $manager = app(AiManager::class);
        $manager->purge('gemini');
        $manager->extend('gemini', fn (Application $app, array $config): GeminiProvider => new GeminiProvider(
            $spy,
            $config,
            app(Dispatcher::class),
        ));

        (new TimeoutToolAgent)->prompt('Give me a number', provider: 'gemini');

        $this->assertSame([300, 300], $spy->capturedTimeouts);
    }
}
