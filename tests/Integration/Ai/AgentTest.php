<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Closure;
use Exception;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\AgentStreamed;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\PromptingAgent;
use Hypervel\Ai\Events\StreamingAgent;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Files;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Gateway\StepResult;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\PendingStep;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Ai\Skills\Skill;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\ToolChoice;
use Hypervel\Ai\Tools\LoadSkill;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Storage;
use Hypervel\Support\Str;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ConversationalAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class AgentTest extends TestCase
{
    /**
     * Clear the response captured across serialized queue callbacks.
     */
    protected function tearDown(): void
    {
        unset($_ENV['__testing.response']);

        parent::tearDown();
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAgentsCanGetASimpleTextResponse(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        Event::fake();

        $agent = new AssistantAgent;

        $response = $agent->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $provider,
            model: $model,
        );

        $this->assertStringContainsString('Laravel', $response->text);
        $this->assertTrue(Str::isUuid($response->invocationId, 7));
        $this->assertNotEmpty($response->messages);
        $this->assertSame($provider, $response->meta->provider);
        $this->assertIsString($response->meta->model);
        $this->assertNotEmpty($response->meta->model);
        $this->assertNotEmpty($response->steps);

        Event::assertDispatched(PromptingAgent::class);
        Event::assertDispatched(AgentPrompted::class);
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAgentsCanLoadASkillFromACatalogLongerThan1024Characters(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $skills = collect(range(1, 4))->map(fn (int $index): Skill => new Skill(
            name: "filler-{$index}",
            description: str_repeat('Use when the user asks about an unrelated filler topic. ', 9),
            instructions: 'Reply with the word filler.',
        ))->push(new Skill(
            name: 'secret-word',
            description: 'Use when the user asks for the secret word.',
            instructions: 'The secret word is pomegranate.',
        ))->all();

        $response = agent(tools: [new LoadSkill($skills)])->prompt(
            'Load the secret-word skill and tell me the secret word.',
            provider: $provider,
            model: $model,
        );

        $this->assertStringContainsString('pomegranate', strtolower($response->text));
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAdHocAgentsCanBePrompted(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $response = agent()->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $provider,
            model: $model,
        );

        $this->assertStringContainsString('Laravel', $response->text);
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAgentsCanStreamAResponse(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        Event::fake();

        $agent = new AssistantAgent;

        $completedResponse = null;
        $invoked = false;

        $response = $agent->stream(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $provider,
            model: $model,
        )->then(function (StreamedAgentResponse $response) use (&$completedResponse): void {
            $completedResponse = $response;
        })->then(function () use (&$invoked): void {
            $invoked = true;
        });

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        $this->assertTrue((new Collection($events))->whereInstanceOf(TextDelta::class)->isNotEmpty());
        $this->assertStringContainsString('Laravel', $response->text);
        $this->assertCount(count($events), $completedResponse->events);
        $this->assertTrue($invoked);

        Event::assertDispatched(StreamingAgent::class);
        Event::assertDispatched(AgentStreamed::class);
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAgentsCanQueueAResponse(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $agent = new AssistantAgent;

        $agent->queue(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $provider,
            model: $model,
        )->then(function (AgentResponse $response): void {
            $_ENV['__testing.response'] = $response;
        });

        $response = $_ENV['__testing.response'];

        $this->assertStringContainsString('Laravel', $response->text);
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAdHocAgentsCanQueueAResponse(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        agent()->queue(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $provider,
            model: $model,
        )->then(function (AgentResponse $response): void {
            $_ENV['__testing.response'] = $response;
        });

        $response = $_ENV['__testing.response'];

        $this->assertStringContainsString('Laravel', $response->text);
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAdHocStructuredAgentsCanQueueAResponse(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        agent(
            schema: fn (JsonSchema $schema): array => [
                'symbol' => $schema->string()->required(),
            ]
        )->queue(
            'What is the chemical symbol for silver?',
            provider: $provider,
            model: $model,
        )->then(function (AgentResponse $response): void {
            $_ENV['__testing.response'] = $response;
        });

        $response = $_ENV['__testing.response'];

        $this->assertSame('ag', strtolower((string) $response['symbol']));
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAgentsCanHaveConversationState(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $agent = new ConversationalAgent;

        $response = $agent->prompt(
            'What did I say my name was?',
            provider: $provider,
            model: $model,
        );

        $this->assertStringContainsString('Taylor', $response->text);
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAgentsCanHaveStructuredOutput(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $agent = new StructuredAgent;

        $response = $agent->prompt(
            'What is the chemical symbol for silver?',
            provider: $provider,
            model: $model,
        );

        $this->assertSame('ag', strtolower((string) $response['symbol']));
        $this->assertNotEmpty($response->steps);
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAdHocAgentsCanHaveStructuredOutput(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $response = agent(
            schema: fn (JsonSchema $schema): array => [
                'symbol' => $schema->string()->required(),
            ],
        )->prompt(
            'What is the chemical symbol for silver?',
            provider: $provider,
            model: $model,
        );

        $this->assertSame('ag', strtolower((string) $response['symbol']));
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAgentsCanUseTools(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        Event::fake();

        // Verify with a random number...
        $agent = new ToolUsingAgent;

        $response = $agent->prompt(
            'Can I have a random number between 1 and 1000?',
            provider: $provider,
            model: $model,
        );

        $this->assertGreaterThanOrEqual(1, $response['number']);
        $this->assertLessThanOrEqual(1000, $response['number']);
        $this->assertCount(1, $response->toolCalls);
        $this->assertCount(1, $response->toolResults);

        Event::assertDispatched(InvokingTool::class);

        Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->toolInvocationId !== null);

        // Verify with a fixed response...
        $agent = new ToolUsingAgent(fixed: true);

        $response = $agent->prompt(
            'Can I have a random number?',
            provider: $provider,
            model: $model,
        );

        $this->assertSame(72019, $response['number']);
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAgentMiddlewareCanSteerEachGenerationStep(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $steps = [];

        $response = (new AssistantAgent)
            ->withTools([new FixedNumberGenerator])
            ->withMiddleware([function (PendingStep $step, Closure $next) use (&$steps): StepResult {
                $steps[] = $step->number;

                return $next($step->withToolChoice($step->isFirstStep() ? ToolChoice::tool('FixedNumberGenerator') : ToolChoice::none));
            }])
            ->prompt('Fetch a number with the tool, then reply with it.', provider: $provider, model: $model);

        $this->assertSame([0, 1], $steps);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('72019', $response->toolResults->first()->result);
        $this->assertNotSame('', $response->text);
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAgentsCanReplayEmptyToolArguments(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $tool = new FixedNumberGenerator;
        $instructions = 'For every request, call the FixedNumberGenerator tool before answering. Answer with one short sentence.';
        $firstPrompt = 'What fixed number is available?';
        $makeAgent = fn (iterable $messages = []): Agent => new class($instructions, $messages, [$tool]) implements Agent, Conversational, HasProviderOptions, HasTools {
            use Promptable;

            /**
             * Create the agent with replay history and tools.
             */
            public function __construct(
                public string $instructions,
                public iterable $messages,
                public iterable $tools,
            ) {
            }

            /**
             * Get the agent instructions.
             */
            public function instructions(): string
            {
                return $this->instructions;
            }

            /**
             * Get the conversation history.
             */
            public function messages(): iterable
            {
                return $this->messages;
            }

            /**
             * Get the available tools.
             */
            public function tools(): iterable
            {
                return $this->tools;
            }

            /**
             * Get the provider options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

                return $provider === Lab::DeepSeek
                    ? ['thinking' => ['type' => 'disabled']]
                    : [];
            }
        };

        $firstResponse = $makeAgent()->prompt(
            $firstPrompt,
            provider: $provider,
            model: $model,
        );

        $this->assertCount(1, $firstResponse->toolCalls);
        $this->assertSame([], $firstResponse->toolCalls->first()->arguments);

        $secondResponse = $makeAgent([
            new UserMessage($firstPrompt),
            ...$firstResponse->messages->all(),
        ])->prompt(
            'Thanks. Confirm the fixed number again.',
            provider: $provider,
            model: $model,
        );

        $this->assertNotEmpty($secondResponse->text);
        $this->assertCount(1, $secondResponse->toolCalls);
        $this->assertSame([], $secondResponse->toolCalls->first()->arguments);
    }

    #[DataProviderExternal(Providers::class, 'agentDocuments')]
    public function testAgentsCanAnalyzeTextDocumentAttachments(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        Storage::fake('docs');

        Storage::disk('docs')->put('document-one.txt', 'The capital of France is Paris.');
        Storage::disk('docs')->put('document-two.txt', 'The capital of Japan is Tokyo.');
        Storage::disk('docs')->put('document-three.txt', 'The capital of Australia is Canberra.');

        $record = ['name' => 'Taylor', 'role' => 'creator of Laravel'];

        $response = agent('Answer using only the attached documents.')->prompt(
            'List the three capital cities mentioned across the attached documents and name the person in the JSON record.',
            [
                Files\Document::fromStorage('document-one.txt', 'docs'),
                Files\Document::fromStorage('document-two.txt', 'docs'),
                Files\Document::fromStorage('document-three.txt', 'docs'),
                Files\Document::fromString(json_encode($record), 'text/plain'),
            ],
            provider: $provider,
            model: $model,
        );

        $this->assertStringContainsString('Paris', $response->text);
        $this->assertStringContainsString('Tokyo', $response->text);
        $this->assertStringContainsString('Canberra', $response->text);
        $this->assertStringContainsString('Taylor', $response->text);
    }

    #[DataProvider('imageProviders')]
    public function testAgentsCanAnalyzeLocalImageAttachmentsWithADetectedMimeType(string $provider, string $apiKey, string $model, string $file, string $color): void
    {
        requiresApiKey($apiKey);

        $response = agent('Answer briefly.')->prompt(
            'What color is the background of this image? Answer with just one word.',
            [new LocalImage(__DIR__ . '/../../Ai/Fixtures/Images/' . $file)],
            provider: $provider,
            model: $model,
        );

        $this->assertStringContainsString($color, strtolower($response->text));
    }

    /**
     * Provide each image format for every image-capable model.
     */
    public static function imageProviders(): iterable
    {
        foreach (Providers::agentImages() as $name => $provider) {
            yield $name . '-png' => [...$provider, 'red.png', 'red'];
            yield $name . '-jpeg' => [...$provider, 'blue.jpg', 'blue'];
        }
    }

    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAgentToolExceptionHandlingIsNotMagical(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        Event::fake();

        $agent = new ToolUsingAgent(toolThrowsException: true);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/^Forced to throw exception\.$/');

        $agent->prompt(
            'Can I have a random number between 1 and 1000?',
            provider: $provider,
            model: $model,
        );
    }

    public function testOpenAiReplaysEchoedOutputBlocksOnAStoreFalseToolCallContinuation(): void
    {
        requiresApiKey('OPENAI_API_KEY');

        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'store' => false,
        ]]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Give me a random number.',
            provider: 'openai',
            model: 'gpt-6-sol',
        );

        $this->assertSame(72019, $response['number']);
        $this->assertCount(1, $response->toolCalls);
    }

    #[DataProviderExternal(Providers::class, 'reasoning')]
    public function testAgentsSurfaceTheReasoningAPromptedTurnProduced(string $provider, string $apiKey, string $model, array $options): void
    {
        requiresApiKey($apiKey);

        $response = $this->reasoningAgent($options)->prompt(
            $this->reasoningPrompt(),
            provider: $provider,
            model: $model,
        );

        $this->assertNotSame('', $response->reasoning);
        $this->assertStringContainsString('10', $response->text);
        $this->assertNotSame('', $response->steps->last()->reasoning);
    }

    #[DataProviderExternal(Providers::class, 'reasoning')]
    public function testAgentsSurfaceTheSameReasoningWhetherPromptedOrStreamed(string $provider, string $apiKey, string $model, array $options): void
    {
        requiresApiKey($apiKey);

        $streamed = $this->reasoningAgent($options)->stream($this->reasoningPrompt(), provider: $provider, model: $model);

        iterator_to_array($streamed);

        $this->assertNotSame('', $streamed->reasoning);
        $this->assertStringContainsString('10', $streamed->text);
    }

    /**
     * Get a prompt requiring a short calculation.
     */
    protected function reasoningPrompt(): string
    {
        return 'Box A has twice as many balls as box B. Box C has five fewer balls than box A. '
            . 'The three boxes hold 45 balls in total. How many balls are in box B? Answer with just the number.';
    }

    /**
     * Create an agent with reasoning options.
     */
    protected function reasoningAgent(array $options): Agent
    {
        return new class($options) implements Agent, HasProviderOptions {
            use Promptable;

            /**
             * Create the agent with provider options.
             */
            public function __construct(public array $options)
            {
            }

            /**
             * Get the agent instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant that responds extremely concisely to all queries.';
            }

            /**
             * Get the provider options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return $this->options;
            }
        };
    }
}
