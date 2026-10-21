<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Promptable;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Agents\RememberingToolAgent;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class AgentReasoningTest extends TestCase
{
    use RefreshDatabase;

    #[DataProviderExternal(Providers::class, 'toolReplay')]
    public function testModelsCanReplayConversationHistoryWithToolCalls(string $provider, string $apiKey, string $model, bool $isReasoning): void
    {
        requiresApiKey($apiKey);

        $tool = new FixedNumberGenerator;
        $instructions = 'For every request, call the FixedNumberGenerator tool before answering. Answer with one short sentence.';

        $makeAgent = fn (iterable $messages = []): Agent => new class($instructions, $messages, [$tool], $isReasoning) implements Agent, Conversational, HasProviderOptions, HasTools {
            use Promptable;

            /**
             * Create the agent with replay history and reasoning options.
             */
            public function __construct(
                public string $instructions,
                public iterable $messages,
                public iterable $tools,
                public bool $isReasoning,
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
             * Get the reasoning options for the provider.
             */
            public function providerOptions(Lab|string $provider): array
            {
                $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

                if (! $this->isReasoning) {
                    return [];
                }

                return match ($provider) {
                    Lab::OpenAI, Lab::Azure => [
                        'reasoning' => ['effort' => 'high'],
                    ],
                    default => [],
                };
            }
        };

        // First turn: model calls the tool (with or without reasoning blocks)...
        $firstPrompt = 'What fixed number is available?';
        $firstResponse = $makeAgent()->prompt(
            $firstPrompt,
            provider: $provider,
            model: $model,
        );

        $this->assertCount(1, $firstResponse->toolCalls);
        $this->assertNotEmpty($firstResponse->text);

        // Second turn: replay full history including any reasoning + tool calls...
        $secondResponse = $makeAgent([
            new UserMessage($firstPrompt),
            ...$firstResponse->messages->all(),
        ])->prompt(
            'Thanks. Confirm the fixed number again.',
            provider: $provider,
            model: $model,
        );

        $this->assertStringContainsString('72019', $secondResponse->text);
    }

    public function testReasoningConversationRoundtripsThroughDatabaseStorage(): void
    {
        requiresApiKey('OPENAI_API_KEY');

        $user = (object) ['id' => 1];
        $firstPrompt = 'What fixed number is available?';

        $agent = (new RememberingToolAgent(['reasoning' => ['effort' => 'high']]))
            ->forUser($user);

        $firstResponse = $agent->prompt(
            $firstPrompt,
            provider: 'openai',
            model: 'gpt-6-luna',
        );

        $this->assertCount(1, $firstResponse->toolCalls);
        $this->assertNotEmpty($firstResponse->text);

        $conversationId = $agent->currentConversation();

        $this->assertNotNull($conversationId);

        $record = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->first();

        $this->assertNotNull($record);

        $step = json_decode((string) $record->steps, true)[0];

        $this->assertCount(1, $step['tool_calls']);
        $this->assertArrayNotHasKey('reasoning_id', $step['tool_calls'][0]);
        $this->assertNotNull(collect($step['replay_blocks'])->firstWhere('type', 'reasoning'));

        $secondResponse = (new RememberingToolAgent)
            ->continue($conversationId, $user)
            ->prompt(
                'Confirm the fixed number again.',
                provider: 'openai',
                model: 'gpt-4.1',
            );

        $this->assertStringContainsString('72019', $secondResponse->text);
    }
}
