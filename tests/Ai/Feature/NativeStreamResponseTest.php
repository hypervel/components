<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\FakeConversationStore;
use Hypervel\Tests\Ai\Fixtures\OpenAiHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use WeakReference;

class NativeStreamResponseTest extends TestCase
{
    use OpenAiHelpers;

    public function testAStreamedResponseIsSentWithHeadersThatStopAProxyBufferingIt(): void
    {
        AssistantAgent::fake(['Hello world']);

        $response = (new AssistantAgent)->stream('Hi')->toResponse(request());

        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-transform', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    #[DataProvider('streamOwnershipPaths')]
    #[WithConfig('ai.conversations.generate_title', false)]
    public function testCompletedStreamsReleaseTheirResponses(string $path): void
    {
        config([
            'ai.providers.primary' => ['driver' => 'openai', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'openai', 'key' => 'test-key'],
        ]);
        app()->instance(ConversationStore::class, new FakeConversationStore);
        $sequence = Http::fakeSequence();
        $failover = in_array($path, ['failover', 'remembering-failover'], true);

        if ($failover) {
            $sequence->push(status: 429);
        }

        $sequence->push($this->ssePayload([
            $this->responseCreated(),
            $this->outputTextDelta('Hello'),
            $this->outputTextDone('Hello'),
            $this->responseCompleted(10, 5),
        ]), headers: ['Content-Type' => 'text/event-stream']);
        $agent = in_array($path, ['remembering', 'remembering-failover'], true) ? new RememberingAssistantAgent : new AssistantAgent;
        $response = $agent->stream('Hi', provider: $failover ? ['primary', 'backup'] : 'primary');
        $reference = WeakReference::create($response);

        if ($path === 'ag-ui') {
            $httpResponse = $response->usingAgentUserInteractionProtocol()->toResponse(request());
            $httpResponse->streamTo(static fn (string $chunk): bool => true);
            unset($httpResponse);
        } else {
            iterator_to_array($response);
        }

        $this->assertSame('Hello', $response->text);
        $this->assertNull($response->conversationId);
        unset($response);

        $this->assertNull($reference->get());
    }

    /**
     * Supply the framework-owned stream lifetimes.
     */
    public static function streamOwnershipPaths(): array
    {
        return [
            'plain provider' => ['plain'],
            'provider failover' => ['failover'],
            'conversation middleware' => ['remembering'],
            'conversation middleware with failover' => ['remembering-failover'],
            'AG-UI protocol' => ['ag-ui'],
        ];
    }

    #[WithConfig('ai.conversations.generate_title', false)]
    public function testFailoverStreamsKeepTheStoredConversationIdAfterARecordedFailure(): void
    {
        config([
            'ai.providers.primary' => ['driver' => 'openai', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'openai', 'key' => 'test-key'],
        ]);
        $store = new class extends FakeConversationStore {
            public ?AgentResponse $recorded = null;

            /**
             * Assign the store's own conversation ID.
             */
            public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
            {
                return 'stored-conversation';
            }

            /**
             * Capture the work recorded before the stream failed.
             */
            public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
            {
                $this->recorded = $response;

                return parent::storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
            }
        };
        app()->instance(ConversationStore::class, $store);
        Http::fakeSequence()->push($this->ssePayload([
            $this->responseCreated(),
            $this->outputItemAdded('item', 'call', 'FixedNumberGenerator'),
            $this->functionCallArgumentsDone('item', '{}'),
            $this->responseCompleted(10, 5, output: [[
                'type' => 'function_call', 'status' => 'completed', 'id' => 'item',
                'call_id' => 'call', 'name' => 'FixedNumberGenerator', 'arguments' => '{}',
            ]]),
        ]))->push($this->ssePayload([$this->responseCreated()]));
        $participant = (object) ['id' => 1];
        $agent = (new RememberingToolUsingAgent)->forUser($participant);
        $response = $agent->stream('Generate a number', provider: ['primary', 'backup']);

        $this->assertThrows(fn (): array => iterator_to_array($response), StreamErrorException::class);

        $this->assertSame('stored-conversation', $agent->currentConversation());
        $this->assertSame('stored-conversation', $response->conversationId);
        $this->assertSame($participant, $response->conversationUser);
        $this->assertSame('72019', $store->recorded->steps->first()->toolResults[0]->result);
        Http::assertSentCount(2);
    }
}
