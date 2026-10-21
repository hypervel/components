<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\ToolChoice;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ProviderOptionsTest extends TestCase
{
    use GeminiHelpers;

    public function testProviderOptionsAreIncludedInTheGenerationConfig(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new ProviderOptionsAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        $this->assertSame('high', sentRequest()->data()['generation_config']['thinking_level']);
    }

    public function testRequestBodyDoesNotContainProviderOptionsWhenAgentDoesNotImplementInterface(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        $this->assertArrayNotHasKey('thinking_level', sentRequest()->data()['generation_config'] ?? []);
    }

    public function testProviderOptionsArePersistedInToolCallFollowUpRequests(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ProviderOptionsWithToolsAgent)->prompt(
            'Generate a random number',
            provider: 'gemini',
        );

        $this->assertSame('The number is 72019', $response->text);

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);
        $this->assertSame('high', $recorded[0][0]->data()['generation_config']['thinking_level']);
        $this->assertSame('high', $recorded[1][0]->data()['generation_config']['thinking_level']);
    }

    /**
     * Create an agent with Gemini provider options.
     */
    protected function geminiOptionsAgent(array $options): Agent
    {
        return new class($options) implements Agent, HasProviderOptions {
            use Promptable;

            /**
             * Create an agent with provider options.
             */
            public function __construct(private array $options)
            {
            }

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the provider's request options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return $provider === Lab::Gemini ? $this->options : [];
            }
        };
    }

    public function testNestedGenerationConfigInsideProviderOptionsIsFlattenedIntoTheTopLevelGenerationConfig(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        $this->geminiOptionsAgent(['generation_config' => ['temperature' => 0.5, 'top_p' => 0.9]])
            ->prompt('Hi', provider: 'gemini');

        $this->assertSame(0.5, sentRequest()->data()['generation_config']['temperature']);
        $this->assertSame(0.9, sentRequest()->data()['generation_config']['top_p']);
        $this->assertArrayNotHasKey('generation_config', sentRequest()->data()['generation_config']);
    }

    public function testSafetySettingsArePlacedAtTheTopLevelOfTheRequestBody(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        $this->geminiOptionsAgent(['safety_settings' => [['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE']]])
            ->prompt('Hi', provider: 'gemini');

        $this->assertSame('BLOCK_NONE', sentRequest()->data()['safety_settings'][0]['threshold']);
        $this->assertArrayNotHasKey('safety_settings', sentRequest()->data()['generation_config'] ?? []);
    }

    public function testSafetySettingsNestedInsideAGenerationConfigOptionAreHoistedToTheRequestRoot(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        $this->geminiOptionsAgent(['generation_config' => [
            'temperature' => 0.2,
            'safety_settings' => [['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH']],
        ]])->prompt('Hi', provider: 'gemini');

        $this->assertSame('BLOCK_ONLY_HIGH', sentRequest()->data()['safety_settings'][0]['threshold']);
        $this->assertSame(0.2, sentRequest()->data()['generation_config']['temperature']);
        $this->assertArrayNotHasKey('safety_settings', sentRequest()->data()['generation_config']);
    }

    public function testEveryTopLevelRequestFieldIsReachableThroughProviderOptions(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        $this->geminiOptionsAgent([
            'service_tier' => 'flex',
            'store' => true,
            'labels' => ['team' => 'search'],
            'generation_config' => ['seed' => 7],
        ])->prompt('Hi', provider: 'gemini');

        $this->assertSame('flex', sentRequest()->data()['service_tier']);
        $this->assertTrue(sentRequest()->data()['store']);
        $this->assertSame(['team' => 'search'], sentRequest()->data()['labels']);
        $this->assertSame(7, sentRequest()->data()['generation_config']['seed']);
        $this->assertArrayNotHasKey('service_tier', sentRequest()->data()['generation_config']);
    }

    public function testTopLevelFieldsSpelledInCamelCaseAreStillHoisted(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        $this->geminiOptionsAgent([
            'safetySettings' => [['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE']],
            'serviceTier' => 'priority',
            'previousInteractionId' => 'int_abc',
        ])->prompt('Hi', provider: 'gemini');

        $this->assertSame('priority', sentRequest()->data()['service_tier']);
        $this->assertSame('int_abc', sentRequest()->data()['previous_interaction_id']);
        $this->assertArrayNotHasKey('generation_config', sentRequest()->data());
        $this->assertSame('BLOCK_NONE', sentRequest()->data()['safety_settings'][0]['threshold']);
    }

    public function testUserMetadataIsPlacedAtTheTopLevelOfTheRequestBody(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        $this->geminiOptionsAgent(['user_metadata' => ['tenant' => 'acme']])->prompt('Hi', provider: 'gemini');

        $this->assertSame(['tenant' => 'acme'], sentRequest()->data()['user_metadata']);
        $this->assertArrayNotHasKey('generation_config', sentRequest()->data());
    }

    public function testAGenerationConfigSpelledInCamelCaseIsFlattenedToo(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        $this->geminiOptionsAgent(['generationConfig' => ['thinking_level' => 'high']])->prompt('Hi', provider: 'gemini');

        $this->assertSame(['thinking_level' => 'high'], sentRequest()->data()['generation_config']);
        $this->assertArrayNotHasKey('generationConfig', sentRequest()->data());
    }

    public function testAStoreOptionOverridesTheStatelessDefault(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        $this->geminiOptionsAgent(['store' => true])->prompt('Hi', provider: 'gemini');

        $this->assertTrue(sentRequest()->data()['store']);
    }

    public function testAToolChoiceOptionReplacesTheValueBuiltFromTheAgentToolChoice(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        $agent = new class implements Agent, HasProviderOptions, HasTools {
            use Promptable;

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the agent's tools.
             */
            public function tools(): iterable
            {
                return [new RandomNumberGenerator];
            }

            /**
             * Get the agent's tool choice.
             */
            public function toolChoice(): ToolChoice|string|array|null
            {
                return 'required';
            }

            /**
             * Get the provider's request options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return ['generation_config' => ['tool_choice' => 'none']];
            }
        };

        $agent->prompt('Hi', provider: 'gemini');

        $this->assertSame('none', sentRequest()->data()['generation_config']['tool_choice']);
    }
}
