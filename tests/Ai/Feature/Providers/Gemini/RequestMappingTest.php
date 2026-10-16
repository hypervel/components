<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NestedStructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NullableStructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class RequestMappingTest extends TestCase
{
    use GeminiHelpers;

    public function testRequestPostsToTheInteractionsEndpointWithTheModelInTheBody(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('Hypervel is great'),
        ]);

        (new AssistantAgent)->prompt(
            'What is Hypervel?',
            provider: 'gemini',
            model: 'gemini-3.7-flash',
        );

        $this->assertStringEndsWith('/interactions', sentRequest()->url());
        $this->assertSame('gemini-3.7-flash', sentRequest()->data()['model']);
        $this->assertSame([
            'type' => 'user_input',
            'content' => [['type' => 'text', 'text' => 'What is Hypervel?']],
        ], sentRequest()->data()['input'][0]);
    }

    public function testHistoryIsReplayedRatherThanLeftToGeminiToStore(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertFalse(sentRequest()->data()['store']);
        $this->assertArrayNotHasKey('previous_interaction_id', sentRequest()->data());
    }

    public function testSystemInstructionsAreSentAsAPlainString(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        $this->assertIsString(sentRequest()->data()['system_instruction']);
        $this->assertStringContainsString('helpful', sentRequest()->data()['system_instruction']);
    }

    public function testRequestWithoutToolsExcludesToolFields(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        $this->assertArrayNotHasKey('tools', sentRequest()->data());
        $this->assertArrayNotHasKey('tool_choice', sentRequest()->data()['generation_config'] ?? []);
    }

    public function testRequestSendsApiKeyHeader(): void
    {
        config(['ai.providers.gemini' => [
            ...config('ai.providers.gemini'),
            'key' => 'test-key',
        ]]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-goog-api-key', 'test-key'));
    }

    public function testRequestOmitsTheApiKeyHeaderWhenNoKeyIsConfigured(): void
    {
        config(['ai.providers.gemini' => [
            ...config('ai.providers.gemini'),
            'key' => null,
        ]]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('x-goog-api-key'));
    }

    public function testToolChoiceIsOmittedToRelyOnTheGeminiDefault(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'gemini',
        );

        $this->assertArrayHasKey('tools', sentRequest()->data());
        $this->assertArrayNotHasKey('tool_choice', sentRequest()->data()['generation_config'] ?? []);
    }

    public function testFunctionCallIdIsExtractedFromResponse(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_abc123'),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $steps = $response->steps;

        $this->assertNotEmpty($steps);

        $toolCall = $steps->first()->toolCalls[0] ?? null;

        $this->assertNotNull($toolCall);
        $this->assertSame('call_abc123', $toolCall->id);
    }

    public function testStructuredOutputUsesTheResponseFormatSchema(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        (new StructuredAgent)->prompt(
            'What is the symbol for Iron?',
            provider: 'gemini',
        );

        $this->assertSame('text', sentRequest()->data()['response_format']['type']);
        $this->assertSame('application/json', sentRequest()->data()['response_format']['mime_type']);
        $this->assertArrayHasKey('symbol', sentRequest()->data()['response_format']['schema']['properties']);
    }

    public function testStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        $response = (new StructuredAgent)->prompt(
            'What is the symbol for Iron?',
            provider: 'gemini',
        );

        $this->assertSame('Fe', $response->structured['symbol']);
    }

    public function testNestedStructuredOutputKeepsTheItemSchemaClosed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse([
                'elements' => [['atomicNumber' => 1, 'symbol' => 'H']],
            ]),
        ]);

        (new NestedStructuredAgent)->prompt('List noble gases?', provider: 'gemini');

        $this->assertFalse(sentRequest()->data()['response_format']['schema']['properties']['elements']['items']['additionalProperties']);
    }

    public function testNullableSchemaTypesArePreservedInTheResponseSchema(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse([
                'symbol' => 'He',
                'meltingPoint' => null,
                'boilingPoint' => -268.9,
            ]),
        ]);

        (new NullableStructuredAgent)->prompt('Properties of Helium?', provider: 'gemini');

        $this->assertSame(['number', 'null'], sentRequest()->data()['response_format']['schema']['properties']['meltingPoint']['type']);
        $this->assertSame(['number', 'null'], sentRequest()->data()['response_format']['schema']['properties']['boilingPoint']['type']);
    }

    public function testResponseUsageIsCorrectlyParsed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction(
                [$this->modelOutput('Hello')],
                [
                    'total_input_tokens' => 25,
                    'total_output_tokens' => 15,
                    'total_tokens' => 40,
                    'total_cached_tokens' => 5,
                    'total_thought_tokens' => 10,
                ],
            )),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        $this->assertSame(25, $response->usage->inputTokens);
        $this->assertSame(25, $response->usage->outputTokens);
        $this->assertSame(5, $response->usage->cacheReadInputTokens);
        $this->assertSame(10, $response->usage->reasoningTokens);
    }

    public function testUsageWithoutCachedTokensUsesFullPromptCount(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction(
                [$this->modelOutput('Hi')],
                ['total_input_tokens' => 100, 'total_output_tokens' => 50, 'total_tokens' => 150],
            )),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
        $this->assertNull($response->usage->cacheReadInputTokens);
    }

    public function testThoughtStepsAreSeparatedFromTheAnswerText(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction([
                $this->thoughtStep('Internal reasoning...'),
                $this->modelOutput('The answer is 42.'),
            ])),
        ]);

        $response = (new AssistantAgent)->prompt('Question?', provider: 'gemini');

        $this->assertSame('The answer is 42.', $response->text);
        $this->assertStringNotContainsString('Internal reasoning', $response->text);
    }

    #[DataProvider('finishReasons')]
    public function testFinishReasonMapsFromTheInteractionStatus(string $status, FinishReason $expected): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeInteraction([$this->modelOutput('Response')], [], $status),
            ),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame($expected, $response->steps->last()->finishReason);
    }

    /**
     * Provide interaction finish reasons.
     */
    public static function finishReasons(): array
    {
        return [
            'completed maps to Stop' => ['completed', FinishReason::Stop],
            'incomplete maps to Length' => ['incomplete', FinishReason::Length],
            'requires_action maps to ToolCalls' => ['requires_action', FinishReason::ToolCalls],
        ];
    }

    #[DataProvider('blockedResponses')]
    public function testASafetyErrorMapsToTheContentFilterFinishReason(string $code, string $message): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(array_merge(
                $this->fakeInteraction([$this->modelOutput('')], [], 'failed'),
                ['errors' => [['code' => $code, 'message' => $message]]],
            )),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame(FinishReason::ContentFilter, $response->steps->last()->finishReason);
    }

    /**
     * Provide withheld-content error codes.
     */
    public static function blockedResponses(): array
    {
        return [
            'safety' => ['BLOCKED_SAFETY', 'Blocked for safety reasons.'],
            'sensitive information' => ['spii', 'Output withheld.'],
        ];
    }

    public function testAnnotationsOnTheModelOutputBecomeCitations(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction([
                ['type' => 'google_search_call', 'content' => [['type' => 'text', 'text' => 'who won euro 2024']]],
                $this->modelOutput('Spain won Euro 2024.', [
                    ['type' => 'url_citation', 'start_index' => 0, 'end_index' => 20, 'url' => 'https://example.com/euro', 'title' => 'Euro 2024'],
                    ['type' => 'url_citation', 'start_index' => 0, 'end_index' => 20, 'url' => 'https://example.com/spain', 'title' => 'Spain Wins'],
                ]),
            ])),
        ]);

        $response = (new AssistantAgent)->prompt('Who won Euro 2024?', provider: 'gemini');

        $this->assertCount(2, $response->meta->citations);
        $this->assertSame('https://example.com/euro', $response->meta->citations[0]->url);
        $this->assertSame('Euro 2024', $response->meta->citations[0]->title);
        $this->assertSame('https://example.com/spain', $response->meta->citations[1]->url);
    }

    public function testDuplicateCitationsAreDeduplicatedByUrl(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction([
                $this->modelOutput('Content.', [
                    ['type' => 'url_citation', 'url' => 'https://example.com/same', 'title' => 'Title A'],
                    ['type' => 'url_citation', 'url' => 'https://example.com/same', 'title' => 'Title B'],
                ]),
            ])),
        ]);

        $response = (new AssistantAgent)->prompt('Query', provider: 'gemini');

        $this->assertCount(1, $response->meta->citations);
    }

    public function testAResponseWithoutAnnotationsReportsNoCitations(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('No sources here.'),
        ]);

        $response = (new AssistantAgent)->prompt('Query', provider: 'gemini');

        $this->assertEmpty($response->meta->citations);
    }

    public function testRequiredToolChoiceSendsAnyMode(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolChoiceAgent('required'))->prompt('Generate a number', provider: 'gemini');

        $this->assertSame('any', sentRequest()->data()['generation_config']['tool_choice']);
    }

    public function testRequiredToolChoiceCanBeSetViaAttribute(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new AttributeToolChoiceAgent)->prompt('Generate a number', provider: 'gemini');

        $this->assertSame('any', sentRequest()->data()['generation_config']['tool_choice']);
    }

    public function testNamedToolChoiceRestrictsTheAllowedTools(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Generate a number', provider: 'gemini');

        $this->assertSame([
            'allowed_tools' => [
                'mode' => 'any',
                'tools' => ['custom_named_tool'],
            ],
        ], sentRequest()->data()['generation_config']['tool_choice']);
    }
}
