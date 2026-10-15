<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features\AiStubs;

use Hypervel\Ai\Attributes\MaxTokens;
use Hypervel\Ai\Attributes\Temperature;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class TestAgent implements Agent, HasTools
{
    use Promptable;

    public int $instructionsCalls = 0;

    public int $toolsCalls = 0;

    /**
     * Return the agent instructions.
     */
    public function instructions(): string
    {
        ++$this->instructionsCalls;
        return 'You are a helpful assistant.';
    }

    /**
     * Return the available tools.
     */
    public function tools(): array
    {
        ++$this->toolsCalls;
        return [new WeatherLookup];
    }
}

#[Temperature(0.7)]
#[MaxTokens(4096)]
class TestAgentWithConfig extends TestAgent
{
    /**
     * Return the configured instructions.
     */
    public function instructions(): string
    {
        return 'You are a configured assistant.';
    }

    /**
     * Return the available tools.
     */
    public function tools(): array
    {
        return [];
    }
}

class WeatherLookup implements Tool
{
    public int $schemaCalls = 0;

    /**
     * Return the tool name.
     */
    public function name(): string
    {
        return 'WeatherLookup';
    }

    /**
     * Describe the tool.
     */
    public function description(): string
    {
        return 'Looks up the current weather for a given location.';
    }

    /**
     * Describe the accepted arguments.
     */
    public function schema(JsonSchema $schema): array
    {
        ++$this->schemaCalls;
        return ['location' => $schema->string()->description('The city and state, e.g. San Francisco, CA')->required(), 'unit' => $schema->string()->enum(['celsius', 'fahrenheit'])];
    }

    /**
     * Return the weather fixture.
     */
    public function handle(Request $request): string
    {
        return 'Sunny, 22°C';
    }
}

namespace Hypervel\Tests\Sentry\Features;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Concerns\RemembersConversations;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\AgentStreamed;
use Hypervel\Ai\Events\EmbeddingsGenerated;
use Hypervel\Ai\Events\GeneratingEmbeddings;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\PromptingAgent;
use Hypervel\Ai\Events\StartingStep;
use Hypervel\Ai\Events\StepCompleted;
use Hypervel\Ai\Events\StepFailed;
use Hypervel\Ai\Events\StreamingAgent;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\RemoteVideo;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Ai\Providers\OpenAiProvider;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Sentry\Features\Ai\AiTracingScope;
use Hypervel\Sentry\Features\AiIntegration;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Sentry\Features\AiStubs\TestAgent;
use Hypervel\Tests\Sentry\Features\AiStubs\TestAgentWithConfig;
use Hypervel\Tests\Sentry\Features\AiStubs\WeatherLookup;
use Hypervel\Tests\Sentry\TestCase;
use JsonSerializable;
use Mockery as m;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use stdClass;

class AiIntegrationTest extends TestCase
{
    private const string PROVIDER_URL = 'https://api.openai.com/v1';

    protected array $defaultSetupConfig = [
        'sentry.tracing.http_client_requests' => false,
        'sentry.traces_sample_rate' => 1.0,
    ];

    /**
     * Load the optional AI package for these integration tests.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    public function testAgentSpanIsRecorded(): void
    {
        [$prompt, $response] = $this->makePromptAndResponse();
        $spans = $this->runAgentFlow([$prompt, $response]);
        $this->assertSame(1, $prompt->agent->instructionsCalls);
        $this->assertSame(1, $prompt->agent->toolsCalls);
        $this->assertCount(3, $spans); // transaction + invoke_agent + chat
        $agentSpan = $spans[1];
        $this->assertEquals('gen_ai.invoke_agent', $agentSpan->getOp());
        $this->assertEquals('invoke_agent gpt-4o', $agentSpan->getDescription());
        $this->assertEquals(SpanStatus::ok(), $agentSpan->getStatus());
        $this->assertEquals('auto.ai.hypervel', $agentSpan->getOrigin());
        $data = $agentSpan->getData();
        $this->assertEquals('invoke_agent', $data['gen_ai.operation.name']);
        $this->assertEquals('TestAgent', $data['gen_ai.agent.name']);
        $this->assertEquals('gpt-4o', $data['gen_ai.request.model']);
        $this->assertEquals('gpt-4o-2024-08-06', $data['gen_ai.response.model']);
        $this->assertEquals('conv-abc-123', $data['gen_ai.conversation.id']);
    }

    public function testAgentSpanCapturesTokenUsageAndRequestParams(): void
    {
        // Token usage
        $spans = $this->runAgentFlow($this->makePromptAndResponse(100, 50, 20, 15));
        $data = $spans[1]->getData();
        $this->assertEquals(100, $data['gen_ai.usage.input_tokens']);
        $this->assertEquals(50, $data['gen_ai.usage.output_tokens']);
        $this->assertEquals(150, $data['gen_ai.usage.total_tokens']);
        $this->assertEquals(20, $data['gen_ai.usage.input_tokens.cached']);
        $this->assertEquals(15, $data['gen_ai.usage.output_tokens.reasoning']);
        // Request parameters from attributes
        $spans = $this->runAgentFlow($this->makePromptAndResponse(60, 130, 0, 0, TestAgentWithConfig::class));
        $data = $spans[1]->getData();
        $this->assertArrayHasKey('gen_ai.request.temperature', $data);
        $this->assertEquals(0.7, $data['gen_ai.request.temperature']);
        $this->assertArrayHasKey('gen_ai.request.max_tokens', $data);
        $this->assertEquals(4096, $data['gen_ai.request.max_tokens']);
    }

    public function testPiiControlsMessageCapture(): void
    {
        // PII enabled: messages captured
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        $spans = $this->runAgentFlow($this->makePromptAndResponse());
        $data = $spans[1]->getData();
        $input = json_decode($data['gen_ai.input.messages'], true);
        $this->assertEquals('user', $input[0]['role']);
        $this->assertStringContainsString('Analyze this', $input[0]['parts'][0]['content']);
        $this->assertEquals('You are a helpful assistant.', $data['gen_ai.system_instructions']);
        $output = json_decode($data['gen_ai.output.messages'], true);
        $this->assertEquals('assistant', $output[0]['role']);
        // PII disabled: messages omitted
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => false]);
        $spans = $this->runAgentFlow($this->makePromptAndResponse());
        $data = $spans[1]->getData();
        $this->assertArrayNotHasKey('gen_ai.input.messages', $data);
        $this->assertArrayNotHasKey('gen_ai.system_instructions', $data);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $data);
    }

    public function testTracingDisabledRecordsNoSpans(): void
    {
        $this->resetApplicationWithConfig(['sentry.tracing.gen_ai' => false]);
        $spans = $this->runAgentFlow($this->makePromptAndResponse());
        $this->assertCount(1, $spans);
    }

    public function testChatSpanIsCreatedWithStepData(): void
    {
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse(100, 50);
        $this->dispatchAgentFlow('inv-c', $prompt, $response);
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);
        $data = $chatSpans[0]->getData();
        $this->assertEquals('chat', $data['gen_ai.operation.name']);
        $this->assertEquals('gpt-4o', $data['gen_ai.request.model']);
        $this->assertEquals('gpt-4o-2024-08-06', $data['gen_ai.response.model']);
        $this->assertEquals('stop', $data['gen_ai.response.finish_reasons']);
        $this->assertEquals(100, $data['gen_ai.usage.input_tokens']);
        $this->assertEquals('conv-abc-123', $data['gen_ai.conversation.id']);
    }

    // REMOVED: AiProviderUrlResolverTest; step events identify model calls without URL matching.

    public function testHttpClientSpanIsNestedWithinChatSpan(): void
    {
        $this->resetApplicationWithConfig(['sentry.tracing.http_client_requests' => true]);

        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $this->dispatchHypervelEvent(new PromptingAgent('inv-hierarchy', $prompt));
        $this->dispatchStartingStep('inv-hierarchy', $prompt, $response);
        Http::setHandler(new MockHandler([new Response(200, [], '{}')]))
            ->post(self::PROVIDER_URL . '/responses');

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $chatSpan = $this->findSpanByOp($transaction, 'gen_ai.chat');
        $httpSpan = $this->findSpanByOp($transaction, 'http.client');

        $this->assertEquals($chatSpan->getSpanId(), $httpSpan->getParentSpanId());

        $this->assertSame($chatSpan, $this->getSentryHubFromContainer()->getSpan());
        $this->dispatchCompletedStep('inv-hierarchy', $prompt, $response);
        $this->assertSame($agentSpan, $this->getSentryHubFromContainer()->getSpan());

        $this->dispatchHypervelEvent(new AgentPrompted('inv-hierarchy', $prompt, $response));
    }

    public function testMultiStepFlowWithToolCalls(): void
    {
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makeMultiStepPromptAndResponse();
        $agent = new TestAgent;
        $tool = new WeatherLookup;
        $this->dispatchHypervelEvent(new PromptingAgent('inv-m', $prompt));
        $this->dispatchStartingStep('inv-m', $prompt, $response);
        $this->dispatchCompletedStep('inv-m', $prompt, $response);
        $this->dispatchHypervelEvent(new InvokingTool('inv-m', 'tool-1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchHypervelEvent(new ToolInvoked('inv-m', 'tool-1', $agent, $tool, ['city' => 'Paris'], 'Sunny, 22C', 0.0));
        $this->dispatchStartingStep('inv-m', $prompt, $response, 1);
        $this->dispatchCompletedStep('inv-m', $prompt, $response, 1);
        $this->dispatchHypervelEvent(new AgentPrompted('inv-m', $prompt, $response));
        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(5, $spans); // transaction + invoke_agent + chat#0 + tool + chat#1
        $this->assertEquals('gen_ai.invoke_agent', $spans[1]->getOp());
        $this->assertEquals('gen_ai.chat', $spans[2]->getOp());
        $this->assertEquals('gen_ai.execute_tool', $spans[3]->getOp());
        $this->assertEquals('gen_ai.chat', $spans[4]->getOp());
        $this->assertArrayHasKey('gen_ai.tool.definitions', $spans[1]->getData());
        $this->assertArrayHasKey('gen_ai.tool.definitions', $spans[2]->getData());
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertEquals('tool_calls', $chatSpans[0]->getData()['gen_ai.response.finish_reasons']);
        $this->assertEquals('stop', $chatSpans[1]->getData()['gen_ai.response.finish_reasons']);
    }

    public function testToolSpanCapturesMetadataAndPiiControl(): void
    {
        // With PII: arguments and result captured
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $agent = new TestAgent;
        $tool = new WeatherLookup;
        $this->dispatchHypervelEvent(new PromptingAgent('inv-t1', $prompt));
        $this->dispatchStartingStep('inv-t1', $prompt, $response);
        $this->dispatchCompletedStep('inv-t1', $prompt, $response);
        $this->dispatchHypervelEvent(new InvokingTool('inv-t1', 'tool-1', $agent, $tool, ['query' => 'weather in Paris']));
        $this->dispatchHypervelEvent(new ToolInvoked('inv-t1', 'tool-1', $agent, $tool, ['query' => 'weather in Paris'], 'Sunny, 22C', 0.0));
        $this->dispatchHypervelEvent(new AgentPrompted('inv-t1', $prompt, $response));
        $this->assertSame(0, $tool->schemaCalls);
        $toolSpan = $this->findSpanByOp($transaction, 'gen_ai.execute_tool');
        $this->assertEquals('execute_tool WeatherLookup', $toolSpan->getDescription());
        $data = $toolSpan->getData();
        $this->assertEquals('WeatherLookup', $data['gen_ai.tool.name']);
        $this->assertEquals('function', $data['gen_ai.tool.type']);
        $this->assertEquals('TestAgent', $data['gen_ai.agent.name']);
        $this->assertEquals('{"query":"weather in Paris"}', $data['gen_ai.tool.call.arguments']);
        $this->assertEquals('Sunny, 22C', $data['gen_ai.tool.call.result']);
        // Without PII: arguments and result omitted
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => false]);
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $this->dispatchHypervelEvent(new PromptingAgent('inv-t2', $prompt));
        $this->dispatchStartingStep('inv-t2', $prompt, $response);
        $this->dispatchCompletedStep('inv-t2', $prompt, $response);
        $this->dispatchHypervelEvent(new InvokingTool('inv-t2', 'tool-2', $agent, $tool, ['q' => 'secret']));
        $this->dispatchHypervelEvent(new ToolInvoked('inv-t2', 'tool-2', $agent, $tool, ['q' => 'secret'], 'secret', 0.0));
        $this->dispatchHypervelEvent(new AgentPrompted('inv-t2', $prompt, $response));
        $data = $this->findSpanByOp($transaction, 'gen_ai.execute_tool')->getData();
        $this->assertArrayNotHasKey('gen_ai.tool.call.arguments', $data);
        $this->assertArrayNotHasKey('gen_ai.tool.call.result', $data);
    }

    public function testStreamingAgentHasStreamingFlag(): void
    {
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makeStreamingPromptAndResponse();
        $this->dispatchHypervelEvent(new StreamingAgent('inv-s', $prompt));
        $this->dispatchStartingStep('inv-s', $prompt, $response);
        $this->dispatchCompletedStep('inv-s', $prompt, $response);
        $this->dispatchHypervelEvent(new AgentStreamed('inv-s', $prompt, $response));
        $this->assertTrue($transaction->getSpanRecorder()->getSpans()[1]->getData()['gen_ai.response.streaming']);
    }

    public function testEmbeddingsSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();
        [$provider, $prompt, $response] = $this->makeEmbeddingsPromptAndResponse();
        $this->dispatchHypervelEvent(new GeneratingEmbeddings('emb-1', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchHypervelEvent(new EmbeddingsGenerated('emb-1', $provider, 'text-embedding-3-small', $prompt, $response));
        $span = $transaction->getSpanRecorder()->getSpans()[1];
        $this->assertEquals('gen_ai.embeddings', $span->getOp());
        $this->assertEquals('embeddings text-embedding-3-small', $span->getDescription());
        $data = $span->getData();
        $this->assertEquals('text-embedding-3-small', $data['gen_ai.request.model']);
        $this->assertEquals('text-embedding-3-small-2024', $data['gen_ai.response.model']);
        $this->assertEquals('openai', $data['gen_ai.provider.name']);
        $this->assertEquals(25, $data['gen_ai.usage.input_tokens']);
    }

    public function testEmbeddingsAfterAnAgentGetTheConversationId(): void
    {
        $transaction = $this->startTransaction();
        $this->dispatchAgentFlow('inv-x', ...$this->makePromptAndResponse());
        [$provider, $prompt, $response] = $this->makeEmbeddingsPromptAndResponse();
        $this->dispatchHypervelEvent(new GeneratingEmbeddings('emb-1', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchHypervelEvent(new EmbeddingsGenerated('emb-1', $provider, 'text-embedding-3-small', $prompt, $response));
        $this->assertEquals('conv-abc-123', $this->findSpanByOp($transaction, 'gen_ai.embeddings')->getData()['gen_ai.conversation.id']);
    }

    public function testEmbeddingsPiiControl(): void
    {
        // With PII: inputs captured
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'sentry.tracing.http_client_requests' => false]);
        $transaction = $this->startTransaction();
        [$provider, $prompt, $response] = $this->makeEmbeddingsPromptAndResponse();
        $this->dispatchHypervelEvent(new GeneratingEmbeddings('emb-2', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchHypervelEvent(new EmbeddingsGenerated('emb-2', $provider, 'text-embedding-3-small', $prompt, $response));
        $inputs = json_decode($transaction->getSpanRecorder()->getSpans()[1]->getData()['gen_ai.embeddings.input'], true);
        $this->assertCount(2, $inputs);
        $this->assertEquals('Napa Valley has great wine.', $inputs[0]);
        // Without PII: inputs omitted
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => false, 'sentry.tracing.http_client_requests' => false]);
        $transaction = $this->startTransaction();
        [$provider, $prompt, $response] = $this->makeEmbeddingsPromptAndResponse();
        $this->dispatchHypervelEvent(new GeneratingEmbeddings('emb-3', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchHypervelEvent(new EmbeddingsGenerated('emb-3', $provider, 'text-embedding-3-small', $prompt, $response));
        $this->assertArrayNotHasKey('gen_ai.embeddings.input', $transaction->getSpanRecorder()->getSpans()[1]->getData());
    }

    public function testBinaryContentIsRedacted(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        $dataUri = 'data:image/png;base64,' . str_repeat('iVBORw0KGgoAAAANSUhEUgAA', 100);
        $spans = $this->runAgentFlow($this->makePromptAndResponse(60, 130, 0, 0, TestAgent::class, $dataUri));
        $input = json_decode($this->findSpanByOpInSpans($spans, 'gen_ai.invoke_agent')->getData()['gen_ai.input.messages'], true);
        $this->assertEquals('[Blob substitute]', $input[0]['parts'][0]['content']);

        $transaction = $this->startTransaction();
        $agent = new TestAgent;
        $tool = new WeatherLookup;
        $this->dispatchHypervelEvent(new InvokingTool('binary', 'image', $agent, $tool, []));
        $this->dispatchHypervelEvent(new ToolInvoked('binary', 'image', $agent, $tool, [], $dataUri, 0.0));
        $this->assertSame('[Blob substitute]', $this->findSpanByOp($transaction, 'gen_ai.execute_tool')->getData()['gen_ai.tool.call.result']);
    }

    public function testAttachmentHandling(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        Http::preventStrayRequests();
        $transaction = $this->startTransaction();
        $agent = new TestAgent;
        $provider = $this->makeProvider();
        $prompt = new AgentPrompt($agent, 'Compare images.', [new LocalImage('/not-present/photo.png'), new RemoteImage('https://example.com/photo.jpg')], $provider, 'gpt-4o');
        [, $response] = $this->makePromptAndResponse(100, 20);
        $this->dispatchAgentFlow('inv-a', $prompt, $response);
        $parts = json_decode($this->findSpanByOp($transaction, 'gen_ai.invoke_agent')->getData()['gen_ai.input.messages'], true)[0]['parts'];
        $this->assertCount(3, $parts); // text + local blob + remote uri
        $this->assertEquals('text', $parts[0]['type']);
        $this->assertEquals('blob', $parts[1]['type']);
        $this->assertEquals('[Blob substitute]', $parts[1]['content']);
        $this->assertEquals('image', $parts[1]['modality']);
        $this->assertEquals('uri', $parts[2]['type']);
        $this->assertEquals('https://example.com/photo.jpg', $parts[2]['content']);
        $this->assertArrayNotHasKey('mime_type', $parts[1]);
        $this->assertArrayNotHasKey('mime_type', $parts[2]);
        Http::assertNothingSent();
    }

    public function testGranularSpanTypeDisabling(): void
    {
        // Disable invoke_agent -> no agent or chat spans
        $this->resetApplicationWithConfig(['sentry.tracing.gen_ai_invoke_agent' => false]);
        $spans = $this->runAgentFlow($this->makePromptAndResponse());
        $this->assertEmpty(array_filter($spans, function (Span $span): bool {
            return $span->getOp() === 'gen_ai.invoke_agent';
        }));
        $this->assertEmpty(array_filter($spans, function (Span $span): bool {
            return $span->getOp() === 'gen_ai.chat';
        }));
        // Disable chat -> agent span still created, no chat
        $this->resetApplicationWithConfig(['sentry.tracing.gen_ai_chat' => false]);
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $this->dispatchAgentFlow('inv-gc', $prompt, $response);
        $this->assertCount(1, $this->findAllSpansByOp($transaction, 'gen_ai.invoke_agent'));
        $this->assertCount(0, $this->findAllSpansByOp($transaction, 'gen_ai.chat'));
    }

    public function testEdgeCases(): void
    {
        // Orphaned events don't crash
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $this->dispatchHypervelEvent(new AgentPrompted('inv-orphan', $prompt, $response));
        $this->dispatchHypervelEvent(new ToolInvoked('inv-orphan', 'tool-orphan', new TestAgent, new WeatherLookup, [], 'result', 0.0));
        $this->assertCount(1, $transaction->getSpanRecorder()->getSpans());
        // Connection failure finishes chat span with error
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $this->dispatchHypervelEvent(new PromptingAgent('inv-f', $prompt));
        $this->dispatchStartingStep('inv-f', $prompt, $response);
        $this->dispatchHypervelEvent(new StepFailed('inv-f', 0, $prompt->agent, $prompt->provider, $prompt->model, true, new ConnectionException('timeout'), 0.0));
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);
        $this->assertEquals(SpanStatus::internalError(), $chatSpans[0]->getStatus());
    }

    public function testConnectionFailureRestoresAgentSpan(): void
    {
        $this->resetApplicationWithConfig(['sentry.tracing.http_client_requests' => true]);

        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $this->dispatchHypervelEvent(new PromptingAgent('inv-fh', $prompt));
        $this->dispatchStartingStep('inv-fh', $prompt, $response);
        $this->dispatchHypervelEvent(new StepFailed('inv-fh', 0, $prompt->agent, $prompt->provider, $prompt->model, true, new ConnectionException('timeout'), 0.0));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');

        $this->assertSame($agentSpan, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testDataCollectionCollectsInputsAndOutputsByDefault(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => false, 'sentry.data_collection' => []]);
        $this->assertGenAiContent($this->runToolFlow('inv-dc1'), true, true);
    }

    public function testDataCollectionGenAiInputsCanBeDisabled(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'sentry.data_collection' => ['gen_ai' => ['inputs' => false]]]);
        $this->assertGenAiContent($this->runToolFlow('inv-dc2'), false, true);
    }

    public function testDataCollectionGenAiOutputsCanBeDisabled(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'sentry.data_collection' => ['gen_ai' => ['outputs' => false]]]);
        $this->assertGenAiContent($this->runToolFlow('inv-dc3'), true, false);
    }

    public function testDataCollectionGenAiInputsControlEmbeddings(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => false, 'sentry.data_collection' => []]);
        $this->assertArrayHasKey('gen_ai.embeddings.input', $this->runEmbeddingsFlow('emb-dc1')->getData());
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'sentry.data_collection' => ['gen_ai' => ['inputs' => false]]]);
        $this->assertArrayNotHasKey('gen_ai.embeddings.input', $this->runEmbeddingsFlow('emb-dc2')->getData());
    }

    public function testEmbeddingFilesContainMetadataWithoutBytesOrLocalPaths(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        Http::preventStrayRequests();
        $transaction = $this->startTransaction();
        [$provider, , $response] = $this->makeEmbeddingsPromptAndResponse();
        $prompt = new EmbeddingsPrompt([
            new Base64Image(str_repeat('QUJD', 10000), 'image/png'),
            new LocalImage('/private/missing-image.png'),
            new RemoteVideo('https://example.com/video.mp4', 'video/mp4'),
        ], 1536, $provider, 'text-embedding-3-small');

        $this->dispatchHypervelEvent(new GeneratingEmbeddings('files', $provider, $prompt->model, $prompt));
        $this->dispatchHypervelEvent(new EmbeddingsGenerated('files', $provider, $prompt->model, $prompt, $response));

        $encoded = $this->findSpanByOp($transaction, 'gen_ai.embeddings')->getData()['gen_ai.embeddings.input'];
        $this->assertSame([
            ['type' => 'blob', 'content' => '[Blob substitute]', 'modality' => 'image', 'mime_type' => 'image/png'],
            ['type' => 'blob', 'content' => '[Blob substitute]', 'modality' => 'image'],
            ['type' => 'uri', 'content' => 'https://example.com/video.mp4', 'modality' => 'video', 'mime_type' => 'video/mp4'],
        ], json_decode($encoded, true, flags: JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function testLongUnicodeMessagesRemainValidJson(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        $spans = $this->runAgentFlow($this->makePromptAndResponse(promptText: str_repeat('€', 10000)));
        $data = $this->findSpanByOpInSpans($spans, 'gen_ai.invoke_agent')->getData();

        $messages = json_decode($data['gen_ai.input.messages'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(str_repeat('€', 3333) . '...', $messages[0]['parts'][0]['content']);
        $this->assertLessThanOrEqual(20000, strlen($data['gen_ai.input.messages']));
    }

    public function testOversizedMultipartMessagesAreOmittedInsteadOfCuttingJson(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        [$prompt, $response] = $this->makePromptAndResponse();
        $calls = [
            new ToolCall('one', 'WeatherLookup', ['value' => str_repeat('a', 9000)]),
            new ToolCall('two', 'WeatherLookup', ['value' => str_repeat('b', 9000)]),
            new ToolCall('three', 'WeatherLookup', ['value' => str_repeat('c', 9000)]),
        ];
        $response->steps[0]->toolCalls = $calls;
        $response->withToolCallsAndResults(collect($calls), collect());

        $spans = $this->runAgentFlow([$prompt, $response]);

        $this->assertArrayNotHasKey('gen_ai.output.messages', $this->findSpanByOpInSpans($spans, 'gen_ai.invoke_agent')->getData());
        $this->assertArrayNotHasKey('gen_ai.output.messages', $this->findSpanByOpInSpans($spans, 'gen_ai.chat')->getData());
    }

    public function testChatHistoryRetainsTheNewestCompleteMessagesInOrder(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $this->dispatchHypervelEvent(new PromptingAgent('history', $prompt));
        $calls = [new ToolCall('one', 'WeatherLookup', []), new ToolCall('two', 'WeatherLookup', [])];
        $oldPayload = new class implements JsonSerializable {
            public int $serializations = 0;

            /**
             * Count serialization of a result from an earlier step.
             */
            public function jsonSerialize(): array
            {
                ++$this->serializations;

                return ['forecast' => 'Cloudy'];
            }
        };
        $this->dispatchHypervelEvent(new StartingStep(
            'history',
            1,
            $prompt->agent,
            $prompt->provider,
            $prompt->model,
            true,
            [
                new ToolResultMessage(collect([new ToolResult('old', 'WeatherLookup', [], ['data' => $oldPayload])])),
                new UserMessage(str_repeat('Old history. ', 1000)),
                new UserMessage(str_repeat('Recent prompt. ', 700)),
                new AssistantMessage('Checking both cities.', collect($calls)),
                new ToolResultMessage(collect([
                    new ToolResult('one', 'WeatherLookup', [], 'Sunny'),
                    new ToolResult('two', 'WeatherLookup', [], 'Rainy'),
                ])),
            ],
            null,
        ));
        $encoded = $this->findSpanByOp($transaction, 'gen_ai.chat')->getData()['gen_ai.input.messages'];
        $messages = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['user', 'assistant', 'tool', 'tool'], array_column($messages, 'role'));
        $this->assertStringStartsWith('Recent prompt. ', $messages[0]['parts'][0]['content']);
        $this->assertSame(['one', 'two'], array_column(array_slice($messages[1]['parts'], 1), 'id'));
        $this->assertSame('Sunny', $messages[2]['parts'][0]['content']);
        $this->assertSame('Rainy', $messages[3]['parts'][0]['content']);
        $this->assertLessThanOrEqual(20000, strlen($encoded));
        $this->assertSame(0, $oldPayload->serializations);
        $this->dispatchHypervelEvent(new AgentPrompted('history', $prompt, $response));
    }

    public function testStructuredToolPayloadsAreBoundedWithoutCuttingTheirJson(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        $transaction = $this->startTransaction();
        $agent = new TestAgent;
        $tool = new WeatherLookup;
        $arguments = ['value' => str_repeat('x', 20000)];
        $this->dispatchHypervelEvent(new InvokingTool('tools', 'large-tool', $agent, $tool, $arguments));
        $this->dispatchHypervelEvent(new ToolInvoked('tools', 'large-tool', $agent, $tool, $arguments, ['result' => str_repeat('y', 20000)], 0.0));

        $data = $this->findSpanByOp($transaction, 'gen_ai.execute_tool')->getData();
        $this->assertArrayNotHasKey('gen_ai.tool.call.arguments', $data);
        $this->assertArrayNotHasKey('gen_ai.tool.call.result', $data);

        [$prompt, $response] = $this->makeMultiStepPromptAndResponse();
        $response->toolCalls[0]->arguments = ['value' => str_repeat('€', 3400)];
        $response->toolResults[0]->result = ['value' => str_repeat('€', 3400)];
        $spans = $this->runAgentFlow([$prompt, $response]);
        $output = json_decode($this->findSpanByOpInSpans($spans, 'gen_ai.invoke_agent')->getData()['gen_ai.output.messages'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('arguments', $output[0]['parts'][1]);
        $this->assertCount(1, $output);
    }

    public function testResolvedStepMetadataIsCollectedOnceAndToolDefinitionsAreBounded(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $tool = new class extends WeatherLookup {
            /**
             * Return an oversized tool description.
             */
            public function description(): string
            {
                return str_repeat('Tool description. ', 2000);
            }
        };
        $this->dispatchHypervelEvent(new PromptingAgent('metadata', $prompt));
        $response->steps->push(clone $response->steps[0]);

        foreach ([0, 1] as $step) {
            $this->dispatchHypervelEvent(new StartingStep(
                'metadata',
                $step,
                $prompt->agent,
                $prompt->provider,
                $prompt->model,
                false,
                [new UserMessage('Resolved input')],
                new TextGenerationOptions(maxTokens: 42, temperature: 0.5),
                'Resolved instructions',
                [$tool]
            ));
            $this->dispatchCompletedStep('metadata', $prompt, $response, $step);
        }

        $this->dispatchHypervelEvent(new AgentPrompted('metadata', $prompt, $response));
        $data = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent')->getData();
        $this->assertSame(0, $prompt->agent->instructionsCalls);
        $this->assertSame(0, $prompt->agent->toolsCalls);
        $this->assertSame(1, $tool->schemaCalls);
        $this->assertSame('Resolved instructions', $data['gen_ai.system_instructions']);
        $this->assertSame(42, $data['gen_ai.request.max_tokens']);
        $this->assertSame(0.5, $data['gen_ai.request.temperature']);
        $this->assertArrayNotHasKey('gen_ai.tool.definitions', $data);
        foreach ($this->findAllSpansByOp($transaction, 'gen_ai.chat') as $span) {
            $this->assertArrayNotHasKey('gen_ai.tool.definitions', $span->getData());
        }
    }

    public function testNestedLateConversationIdsDoNotClaimTheOuterConversation(): void
    {
        $transaction = $this->startTransaction();
        [$outerPrompt, $outerResponse] = $this->makePromptAndResponse();
        $outerAgent = (new class extends TestAgent {
            use RemembersConversations;
        })->forParticipant(new stdClass);
        $outerPrompt = new AgentPrompt($outerAgent, $outerPrompt->prompt, [], $outerPrompt->provider, $outerPrompt->model);
        $outerResponse->withinConversation('outer');
        $this->dispatchHypervelEvent(new PromptingAgent('outer', $outerPrompt));
        $outerSpan = $this->getSentryHubFromContainer()->getSpan();
        $extra = $outerSpan->startChild(SpanContext::make()->setOp('gen_ai.evaluate'));
        $this->app->make(AiIntegration::class)->attachSpanToConversation($extra);
        $extra->finish();

        [$innerPrompt, $innerResponse] = $this->makePromptAndResponse();
        $innerPrompt = new AgentPrompt(
            $innerPrompt->agent,
            $innerPrompt->prompt,
            [],
            $innerPrompt->provider,
            $innerPrompt->model,
            parentInvocationId: 'outer'
        );
        $innerResponse->withinConversation('inner');
        $this->dispatchAgentFlow('inner', $innerPrompt, $innerResponse);
        $this->assertSame($outerSpan, $this->getSentryHubFromContainer()->getSpan());
        $laterExtra = $outerSpan->startChild(SpanContext::make()->setOp('gen_ai.evaluate'));
        $this->app->make(AiIntegration::class)->attachSpanToConversation($laterExtra);
        $laterExtra->finish();

        $this->dispatchStartingStep('outer', $outerPrompt, $outerResponse);
        $this->dispatchCompletedStep('outer', $outerPrompt, $outerResponse);
        $this->dispatchHypervelEvent(new AgentPrompted('outer', $outerPrompt, $outerResponse));

        $agents = $this->findAllSpansByOp($transaction, 'gen_ai.invoke_agent');
        $chats = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertSame('outer', $agents[0]->getData()['gen_ai.conversation.id']);
        $this->assertSame('inner', $agents[1]->getData()['gen_ai.conversation.id']);
        $this->assertSame('inner', $chats[0]->getData()['gen_ai.conversation.id']);
        $this->assertSame('outer', $extra->getData()['gen_ai.conversation.id']);
        $this->assertSame('outer', $laterExtra->getData()['gen_ai.conversation.id']);
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testAChangedResponseConversationDoesNotRenameTheCapturedAssociation(): void
    {
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $agent = (new class extends TestAgent {
            use RemembersConversations;
        })->continue('original');
        $prompt = new AgentPrompt($agent, $prompt->prompt, [], $prompt->provider, $prompt->model);
        $response->withinConversation('returned');
        $this->dispatchHypervelEvent(new PromptingAgent('changed', $prompt));
        $original = AiTracingScope::current()->conversation;
        $this->dispatchStartingStep('changed', $prompt, $response);
        $this->dispatchCompletedStep('changed', $prompt, $response);
        $this->dispatchHypervelEvent(new AgentPrompted('changed', $prompt, $response));

        $this->assertSame('original', $original->conversationId);
        $this->assertSame('returned', AiTracingScope::current()->conversation->conversationId);
        foreach (['gen_ai.invoke_agent', 'gen_ai.chat'] as $operation) {
            $this->assertSame('returned', $this->findSpanByOp($transaction, $operation)->getData()['gen_ai.conversation.id']);
        }
    }

    /**
     * Create an event-driven provider fixture.
     */
    private function makeProvider(): OpenAiProvider
    {
        return new OpenAiProvider(
            m::mock(Gateway::class),
            ['name' => 'openai', 'driver' => 'openai', 'key' => 'test-key'],
            $this->app->make('events')
        );
    }

    /**
     * Create text usage with optional cached and reasoning tokens.
     */
    private function makeUsage(int $inputTokens, int $outputTokens, int $cacheReadInputTokens = 0, int $reasoningTokens = 0): TextUsage
    {
        return new TextUsage($inputTokens, $outputTokens, $cacheReadInputTokens, 0, $reasoningTokens);
    }

    /**
     * Record a two-step invocation with a tool between the steps.
     */
    private function runToolFlow(string $id): Transaction
    {
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makeMultiStepPromptAndResponse();
        $agent = new TestAgent;
        $tool = new WeatherLookup;
        $this->dispatchHypervelEvent(new PromptingAgent($id, $prompt));
        $this->dispatchStartingStep($id, $prompt, $response);
        $this->dispatchCompletedStep($id, $prompt, $response);
        $this->dispatchHypervelEvent(new InvokingTool($id, 'tool-1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchHypervelEvent(new ToolInvoked($id, 'tool-1', $agent, $tool, ['city' => 'Paris'], 'Sunny, 22C', 0.0));
        $this->dispatchStartingStep($id, $prompt, $response, 1);
        $this->dispatchCompletedStep($id, $prompt, $response, 1);
        $this->dispatchHypervelEvent(new AgentPrompted($id, $prompt, $response));
        return $transaction;
    }

    /**
     * Record an embeddings invocation.
     */
    private function runEmbeddingsFlow(string $id): Span
    {
        $transaction = $this->startTransaction();
        [$provider, $prompt, $response] = $this->makeEmbeddingsPromptAndResponse();
        $this->dispatchHypervelEvent(new GeneratingEmbeddings($id, $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchHypervelEvent(new EmbeddingsGenerated($id, $provider, 'text-embedding-3-small', $prompt, $response));
        return $this->findSpanByOp($transaction, 'gen_ai.embeddings');
    }

    /**
     * Assert collection policy across agent, step and tool payloads.
     */
    private function assertGenAiContent(Transaction $transaction, bool $inputs, bool $outputs): void
    {
        $agentData = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent')->getData();
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $firstChatData = $chatSpans[0]->getData();
        $lastChatData = end($chatSpans)->getData();
        $toolData = $this->findSpanByOp($transaction, 'gen_ai.execute_tool')->getData();
        $this->assertSame($inputs, isset($agentData['gen_ai.input.messages']));
        $this->assertSame($inputs, isset($agentData['gen_ai.system_instructions']));
        $this->assertSame($inputs, isset($agentData['gen_ai.tool.definitions']));
        $this->assertSame($inputs, isset($firstChatData['gen_ai.input.messages']));
        $this->assertSame($inputs, isset($firstChatData['gen_ai.tool.definitions']));
        $this->assertSame($inputs, isset($toolData['gen_ai.tool.call.arguments']));
        $this->assertSame($outputs, isset($agentData['gen_ai.output.messages']));
        $this->assertSame($outputs, isset($lastChatData['gen_ai.output.messages']));
        $this->assertSame($outputs, isset($toolData['gen_ai.tool.call.result']));
    }

    /**
     * Record an invocation and return its spans.
     *
     * @param array{AgentPrompt, AgentResponse} $promptAndResponse
     * @return list<Span>
     */
    private function runAgentFlow(array $promptAndResponse, string $id = 'inv-x'): array
    {
        $transaction = $this->startTransaction();
        $this->dispatchAgentFlow($id, $promptAndResponse[0], $promptAndResponse[1]);
        return $transaction->getSpanRecorder()->getSpans();
    }

    /**
     * Dispatch the lifecycle of a single-step invocation.
     */
    private function dispatchAgentFlow(string $id, AgentPrompt $prompt, AgentResponse $response): void
    {
        $this->dispatchHypervelEvent(new PromptingAgent($id, $prompt));
        $this->dispatchStartingStep($id, $prompt, $response);
        $this->dispatchCompletedStep($id, $prompt, $response);
        $this->dispatchHypervelEvent(new AgentPrompted($id, $prompt, $response));
    }

    /**
     * Create a prompt and single-step response.
     *
     * @param class-string<TestAgent> $agentClass
     * @return array{AgentPrompt, AgentResponse}
     */
    private function makePromptAndResponse(int $promptTokens = 60, int $completionTokens = 130, int $cacheReadInputTokens = 0, int $reasoningTokens = 0, string $agentClass = TestAgent::class, string $promptText = 'Analyze this transcript'): array
    {
        $agent = new $agentClass;
        $provider = $this->makeProvider();
        $usage = $this->makeUsage($promptTokens, $completionTokens, $cacheReadInputTokens, $reasoningTokens);
        $meta = new Meta('openai', 'gpt-4o-2024-08-06');
        $prompt = new AgentPrompt($agent, $promptText, [], $provider, 'gpt-4o');
        $step = new Step('The analysis shows positive trends.', [], [], FinishReason::Stop, $usage, $meta, '', []);
        $response = (new AgentResponse('invocation', $step->text, $usage, $meta))
            ->withSteps(collect([$step]))
            ->withinConversation('conv-abc-123');
        return [$prompt, $response];
    }

    /**
     * Create a tool-call step and its answering step.
     *
     * @return array{AgentPrompt, AgentResponse}
     */
    private function makeMultiStepPromptAndResponse(): array
    {
        $agent = new TestAgent;
        $provider = $this->makeProvider();
        $meta = new Meta('openai', 'gpt-4o-2024-08-06');
        $prompt = new AgentPrompt($agent, 'What is the weather in Paris?', [], $provider, 'gpt-4o');
        $toolCall = new ToolCall('call-1', 'WeatherLookup', ['city' => 'Paris']);
        $toolResult = new ToolResult('call-1', 'WeatherLookup', $toolCall->arguments, 'Sunny, 22C');
        $step1 = new Step('', [$toolCall], [$toolResult], FinishReason::ToolCalls, $this->makeUsage(60, 20), $meta, '', []);
        $step2 = new Step('Sunny and 22 degrees.', [], [], FinishReason::Stop, $this->makeUsage(80, 30), $meta, '', []);
        $response = (new AgentResponse('invocation', $step2->text, $this->makeUsage(140, 50), $meta))
            ->withSteps(collect([$step1, $step2]))
            ->withToolCallsAndResults(collect([$toolCall]), collect([$toolResult]))
            ->withinConversation('conv-abc-123');
        return [$prompt, $response];
    }

    /**
     * Create a response carrying an aggregated streaming step.
     *
     * @return array{AgentPrompt, AgentResponse}
     */
    private function makeStreamingPromptAndResponse(): array
    {
        [$prompt, $response] = $this->makePromptAndResponse();
        $response->text = 'Streamed analysis.';
        $response->steps[0]->text = $response->text;
        $response->withinConversation('conv-stream-123');
        return [$prompt, $response];
    }

    /**
     * Create an embeddings prompt and response.
     *
     * @return array{OpenAiProvider, EmbeddingsPrompt, EmbeddingsResponse}
     */
    private function makeEmbeddingsPromptAndResponse(): array
    {
        $provider = $this->makeProvider();
        $prompt = new EmbeddingsPrompt(['Napa Valley has great wine.', 'Hypervel is a PHP framework.'], 1536, $provider, 'text-embedding-3-small');
        $response = new EmbeddingsResponse([[0.1, 0.2], [0.4, 0.5]], new Usage(25), new Meta('openai', 'text-embedding-3-small-2024'));
        return [$provider, $prompt, $response];
    }

    /**
     * Find the first span with the given operation.
     */
    private function findSpanByOp(Transaction $transaction, string $op): ?Span
    {
        return $this->findSpanByOpInSpans($transaction->getSpanRecorder()->getSpans(), $op);
    }

    /**
     * Find the first matching span in a recorded list.
     *
     * @param list<Span> $spans
     */
    private function findSpanByOpInSpans(array $spans, string $op): ?Span
    {
        foreach ($spans as $span) {
            if ($span->getOp() === $op) {
                return $span;
            }
        }
        return null;
    }

    /**
     * Find every span with the given operation.
     *
     * @return list<Span>
     */
    private function findAllSpansByOp(Transaction $transaction, string $op): array
    {
        return array_values(array_filter($transaction->getSpanRecorder()->getSpans(), function (Span $span) use ($op): bool {
            return $span->getOp() === $op;
        }));
    }

    /**
     * Dispatch the resolved input for a model step.
     */
    private function dispatchStartingStep(string $id, AgentPrompt $prompt, AgentResponse $response, int $index = 0): void
    {
        $messages = [new UserMessage($prompt->prompt, $prompt->attachments)];

        if ($index > 0) {
            $previous = $response->steps[$index - 1];
            $messages[] = new AssistantMessage($previous->text, collect($previous->toolCalls));
            $messages[] = new ToolResultMessage(collect($previous->toolResults));
        }

        $this->dispatchHypervelEvent(new StartingStep(
            $id,
            $index,
            $prompt->agent,
            $prompt->provider,
            $prompt->model,
            $index === $response->steps->count() - 1,
            $messages,
            TextGenerationOptions::forAgent($prompt->agent),
            $prompt->agent->instructions(),
            $prompt->agent->tools(),
        ));
    }

    /**
     * Dispatch a completed model step without confusing its HTTP attempts with steps.
     */
    private function dispatchCompletedStep(string $id, AgentPrompt $prompt, AgentResponse $response, int $index = 0): void
    {
        $step = $response->steps[$index];
        $this->dispatchHypervelEvent(new StepCompleted(
            $id,
            $index,
            $prompt->agent,
            $prompt->provider,
            $prompt->model,
            $index === $response->steps->count() - 1,
            new StepResponse($step->text, $step->toolCalls, $step->finishReason, $step->usage, $step->meta),
            0.0,
        ));
    }
}
