<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Ai\Attributes\TopP;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ConstrainedStructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredWithThinkingAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ThinkingToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class RequestMappingTest extends TestCase
{
    use AnthropicHelpers;

    public function testRequestIncludesModelAndMessages(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Hypervel is great'),
        ]);

        (new AssistantAgent)->prompt(
            'What is Hypervel?',
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $body['model'] === 'claude-sonnet-4-6'
                && $body['messages'][0]['role'] === 'user'
                && $body['messages'][0]['content'][0]['text'] === 'What is Hypervel?';
        });
    }

    public function testSystemInstructionsAreSentAsTopLevelSystemField(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return isset($body['system'])
                && is_string($body['system'])
                && str_contains($body['system'], 'helpful');
        });
    }

    public function testMaxTokensDefaultsTo64000(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(fn ($request): bool => $request->data()['max_tokens'] === 64000);
    }

    #[DataProvider('samplingModels')]
    public function testOnlyTemperatureIsSentWhenTemperatureAndTopPAreBothSet(string $model): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AttributeAgent)->prompt(
            'Hi',
            provider: 'anthropic',
            model: $model,
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['temperature'] === 0.7
                && ! array_key_exists('top_p', $body);
        });
    }

    /**
     * Provide models that accept sampling parameters.
     */
    public static function samplingModels(): array
    {
        return [['claude-sonnet-4-6'], ['claude-haiku-4-5-20251001']];
    }

    public function testTopPIsSentWhenTemperatureIsNotSet(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        $agent = new #[TopP(0.8)] class implements Agent {
            use Promptable;

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }
        };

        $agent->prompt('Hi', provider: 'anthropic', model: 'claude-sonnet-4-6');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['top_p'] === 0.8
                && ! array_key_exists('temperature', $body);
        });
    }

    #[DataProvider('nonSamplingModels')]
    public function testTemperatureAndTopPAreDroppedForModelsThatRejectSamplingParameters(string $model): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AttributeAgent)->prompt(
            'Hi',
            provider: 'anthropic',
            model: $model,
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('top_p', $body);
        });
    }

    /**
     * Provide models that reject sampling parameters.
     */
    public static function nonSamplingModels(): array
    {
        return [['claude-haiku-5-5'], ['claude-sonnet-5-5'], ['claude-opus-4-7'], ['claude-fable-5-1']];
    }

    public function testTemperatureAndTopPAreExcludedWhenNotSet(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('top_p', $body);
        });
    }

    public function testToolsWithStructuredOutputUseToolChoiceAnyWhenNativeStructuredOutputIsDisabled(): void
    {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'use_native_structured_output' => false,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return isset($body['tools'])
                && count($body['tools']) > 0
                && $body['tool_choice']['type'] === 'any';
        });
    }

    public function testRequestWithoutToolsExcludesToolFields(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ! isset($body['tools'])
                && ! isset($body['tool_choice']);
        });
    }

    public function testRequestSendsCorrectAuthenticationHeaders(): void
    {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'key' => 'test-key',
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01'));
    }

    public function testRequestOmitsTheApiKeyHeaderWhenNoKeyIsConfigured(): void
    {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'key' => null,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(fn ($request): bool => ! $request->hasHeader('x-api-key')
            && $request->hasHeader('anthropic-version', '2023-06-01'));
    }

    public function testStructuredOutputUsesNativeOutputConfigByDefault(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $hasStructuredTool = false;

            foreach ($body['tools'] ?? [] as $tool) {
                if ($tool['name'] === 'output_structured_data') {
                    $hasStructuredTool = true;
                }
            }

            return $body['output_config']['format']['type'] === 'json_schema'
                && ! $hasStructuredTool;
        });
    }

    public function testNativeStructuredOutputKeepsItsFormatWhenProviderOptionsSetOutputConfig(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        (new StructuredWithThinkingAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['output_config']['format']['type'] === 'json_schema'
                && $body['output_config']['effort'] === 'low'
                && $body['thinking']['type'] === 'enabled';
        });
    }

    public function testNativeStructuredOutputStripsUnsupportedConstraintsAndFoldsThemIntoDescriptions(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['score' => 5, 'tags' => ['a']]),
        ]);

        (new ConstrainedStructuredAgent)->prompt(
            'Score this',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $properties = $request->data()['output_config']['format']['schema']['properties'];

            $this->assertArrayNotHasKey('minimum', $properties['score']);
            $this->assertArrayNotHasKey('maximum', $properties['score']);
            $this->assertSame('Must be at least 1. Must be at most 10.', $properties['score']['description']);
            $this->assertArrayNotHasKey('minLength', $properties['summary']);
            $this->assertArrayNotHasKey('maxLength', $properties['summary']);
            $this->assertSame('Must be at least 1 character. Must be at most 280 characters.', $properties['summary']['description']);
            $this->assertArrayNotHasKey('maxItems', $properties['tags']);
            $this->assertSame(1, $properties['tags']['minItems']);
            $this->assertSame('Must contain at most 5 items.', $properties['tags']['description']);
            $this->assertArrayNotHasKey('maxLength', $properties['tags']['items']);
            $this->assertSame('Must be at most 20 characters.', $properties['tags']['items']['description']);

            return true;
        });
    }

    public function testStructuredOutputFallsBackToTheSyntheticToolWhenNativeStructuredOutputIsDisabled(): void
    {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'use_native_structured_output' => false,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $hasStructuredTool = false;

            foreach ($body['tools'] ?? [] as $tool) {
                if ($tool['name'] === 'output_structured_data') {
                    $hasStructuredTool = true;
                }
            }

            return $hasStructuredTool
                && $body['tool_choice']['type'] === 'tool'
                && $body['tool_choice']['name'] === 'output_structured_data';
        });
    }

    public function testStructuredOutputWithThinkingUsesAutoToolChoiceWhenNativeStructuredOutputIsDisabled(): void
    {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'use_native_structured_output' => false,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new StructuredWithThinkingAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $hasStructuredTool = false;

            foreach ($body['tools'] ?? [] as $tool) {
                if ($tool['name'] === 'output_structured_data') {
                    $hasStructuredTool = true;
                }
            }

            return $hasStructuredTool
                && $body['tool_choice']['type'] === 'auto'
                && $body['thinking']['type'] === 'enabled';
        });
    }

    public function testNativeStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        $response = (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );
        $this->assertSame(['name' => 'Taylor', 'age' => 30], array_intersect_key($response->structured, ['name' => true, 'age' => true]));
    }

    public function testSyntheticToolStructuredResponseIsCorrectlyParsedWhenNativeStructuredOutputIsDisabled(): void
    {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'use_native_structured_output' => false,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeSyntheticStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        $response = (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );
        $this->assertSame(['name' => 'Taylor', 'age' => 30], array_intersect_key($response->structured, ['name' => true, 'age' => true]));
    }

    public function testResponseTextIsCorrectlyParsed(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Hypervel is a PHP framework'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'What is Hypervel?',
            provider: 'anthropic',
        );

        $this->assertSame('Hypervel is a PHP framework', $response->text);
    }

    public function testResponseUsageIsCorrectlyParsed(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Hello']],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 25,
                    'output_tokens' => 15,
                    'cache_creation_input_tokens' => 5,
                    'cache_read_input_tokens' => 3,
                    'output_tokens_details' => ['thinking_tokens' => 9],
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        $this->assertSame(33, $response->usage->inputTokens);
        $this->assertSame(25, $response->usage->uncachedInputTokens());
        $this->assertSame(15, $response->usage->outputTokens);
        $this->assertSame(5, $response->usage->cacheWriteInputTokens);
        $this->assertSame(3, $response->usage->cacheReadInputTokens);
        $this->assertSame(9, $response->usage->reasoningTokens);
    }

    public function testReportsNoReasoningTokensWhenTheResponseOmitsTheBreakdown(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Hello']],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 25,
                    'output_tokens' => 15,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        $this->assertSame(15, $response->usage->outputTokens);
        $this->assertNull($response->usage->reasoningTokens);
    }

    public function testRequiredToolChoiceMapsToAny(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolChoiceAgent('required'))->prompt('Generate a number', provider: 'anthropic');

        Http::assertSent(fn ($request): bool => $request->data()['tool_choice'] === ['type' => 'any']);
    }

    public function testRequiredToolChoiceCanBeSetViaAttribute(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new AttributeToolChoiceAgent)->prompt('Generate a number', provider: 'anthropic');

        Http::assertSent(fn ($request): bool => $request->data()['tool_choice'] === ['type' => 'any']);
    }

    public function testNamedToolChoiceMapsToASpecificTool(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Generate a number', provider: 'anthropic');

        Http::assertSent(fn ($request): bool => $request->data()['tool_choice'] === ['type' => 'tool', 'name' => 'custom_named_tool']);
    }

    public function testNoneToolChoicePreventsToolCalls(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Sure'),
        ]);

        (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'anthropic');

        Http::assertSent(fn ($request): bool => $request->data()['tool_choice'] === ['type' => 'none']);
    }

    public function testForcingAToolWhileThinkingIsEnabledThrows(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Anthropic cannot force tool use while extended thinking is enabled.');

        (new ThinkingToolChoiceAgent)->prompt('Generate a number', provider: 'anthropic');
    }
}
