<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\DeepSeek;

use Hypervel\Ai\Files\LocalImage;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeDeepSeekResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class RequestMappingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.deepseek.key', 'test-key');
    }

    public function testRequestIncludesModelAndMessages(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'deepseek', model: 'deepseek-chat');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'deepseek-chat'
                && count($body['messages']) >= 1
                && collect($body['messages'])->contains(fn (array $message): bool => $message['role'] === 'user' && $message['content'] === 'Hi there');
        });
    }

    public function testSystemInstructionsAreSentAsSystemMessage(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'deepseek');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'helpful assistant');
        });
    }

    public function testTemperatureAndMaxTokensAreIncludedWhenSetViaAttributes(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'deepseek');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_tokens') === 4096
                && ! array_key_exists('max_completion_tokens', $body);
        });
    }

    public function testTemperatureAndMaxTokensAreExcludedWhenNotSet(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

        agent()->prompt('Hello', provider: 'deepseek');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_tokens', $body)
                && ! array_key_exists('max_completion_tokens', $body);
        });
    }

    public function testToolsIncludeToolChoiceAuto(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'deepseek');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto'
                && is_array($body['tools'])
                && $body['tools'] !== [];
        });
    }

    public function testRequestWithoutToolsExcludesToolFields(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

        agent()->prompt('Hello', provider: 'deepseek');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function testRequiredToolChoiceForcesTheModelToCallATool(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('42')]);

        (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'deepseek');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testRequiredToolChoiceCanBeSetViaAttribute(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('42')]);

        (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'deepseek');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testNamedToolChoiceForcesASpecificFunction(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('42')]);

        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'deepseek');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === [
            'type' => 'function',
            'function' => ['name' => 'custom_named_tool'],
        ]);
    }

    public function testNoneToolChoicePreventsToolCalls(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('Sure')]);

        (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'deepseek');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
    }

    public function testStructuredOutputUsesJsonObjectResponseFormat(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'deepseek');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'response_format') === ['type' => 'json_object'];
        });
    }

    public function testStructuredOutputAppendsSchemaInstructionsToSystemMessage(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'deepseek');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'JSON object that strictly adheres')
                && str_contains((string) $systemMsg['content'], '"symbol"');
        });
    }

    public function testRequestWithoutSchemaExcludesResponseFormat(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

        agent()->prompt('Hello', provider: 'deepseek');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('response_format', $body);
        });
    }

    public function testStreamingRequestIncludesStreamOptions(): void
    {
        Http::fake(['*' => Http::response("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

        $stream = agent()->stream('Hello', provider: 'deepseek');

        foreach ($stream as $event);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true
                && data_get($body, 'stream_options.include_usage') === true;
        });
    }

    public function testRequestSendsBearerTokenAuthorization(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

        agent()->prompt('Hello', provider: 'deepseek');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('Hypervel is great')]);

        $response = agent()->prompt('Tell me about Hypervel', provider: 'deepseek');

        $this->assertSame('Hypervel is great', $response->text);
        $this->assertSame('deepseek', $response->meta->provider);
    }

    public function testResponseUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'deepseek-chat',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'deepseek');

        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(5, $response->usage->outputTokens);
    }

    public function testResponseUsageIncludesCacheHitAndReasoningTokens(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-reasoner-1',
            'object' => 'chat.completion',
            'model' => 'deepseek-reasoner',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'The answer is 4.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'prompt_cache_hit_tokens' => 20,
                'prompt_cache_miss_tokens' => 80,
                'total_tokens' => 150,
                'completion_tokens_details' => [
                    'reasoning_tokens' => 15,
                ],
            ],
        ])]);

        $response = agent()->prompt('What is 2+2?', provider: 'deepseek', model: 'deepseek-reasoner');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
        $this->assertSame(20, $response->usage->cacheReadInputTokens);
        $this->assertNull($response->usage->cacheWriteInputTokens);
        $this->assertSame(15, $response->usage->reasoningTokens);
    }

    public function testReasoningContentFromDeepSeekReasonerIsKeptOutOfTheAnswerText(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-reasoner-1',
            'object' => 'chat.completion',
            'model' => 'deepseek-reasoner',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'reasoning_content' => 'Let me think... 2+2 = 4',
                    'content' => 'The answer is 4.',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 5,
                'completion_tokens' => 3,
            ],
        ])]);

        $response = agent()->prompt('What is 2+2?', provider: 'deepseek', model: 'deepseek-reasoner');

        $this->assertSame('The answer is 4.', $response->text);
        $this->assertSame('Let me think... 2+2 = 4', $response->reasoning);
    }

    public function testLocalImageAttachmentWithoutExplicitMimeTypeDetectsMimeFromFile(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse('I see an image')]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
            provider: 'deepseek',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['messages'])->firstWhere('role', 'user');
            $imageBlock = collect($userMsg['content'])->firstWhere('type', 'image_url');

            return $imageBlock !== null
                && str_starts_with((string) $imageBlock['image_url']['url'], 'data:image/png;base64,')
                && ! str_contains((string) $imageBlock['image_url']['url'], 'data:;base64,');
        });
    }
}
