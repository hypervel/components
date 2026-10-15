<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\FakeConversationStore;
use Hypervel\Tests\Ai\Fixtures\OpenAiHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

        if ($path === 'failover') {
            $sequence->push(status: 429);
        }

        $sequence->push($this->ssePayload([
            $this->responseCreated(),
            $this->outputTextDelta('Hello'),
            $this->outputTextDone('Hello'),
            $this->responseCompleted(10, 5),
        ]), headers: ['Content-Type' => 'text/event-stream']);
        $agent = $path === 'remembering' ? new RememberingAssistantAgent : new AssistantAgent;
        $response = $agent->stream('Hi', provider: $path === 'failover' ? ['primary', 'backup'] : 'primary');
        $reference = WeakReference::create($response);

        if ($path === 'ag-ui') {
            $httpResponse = $response->usingAgentUserInteractionProtocol()->toResponse(request());
            $httpResponse->streamTo(static fn (string $chunk): bool => true);
            unset($httpResponse);
        } else {
            iterator_to_array($response);
        }

        $this->assertSame('Hello', $response->text);
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
            'AG-UI protocol' => ['ag-ui'],
        ];
    }
}
