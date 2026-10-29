<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Response;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Concerns\RemembersConversations;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Events\AgentFailed;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\Classifying;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\PromptingAgent;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Exceptions\ProviderConnectionException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Prompts\ClassificationPrompt;
use Hypervel\Ai\Providers\TypeSafeProvider;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Tools\Request as ToolRequest;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Http\Client\RequestException;
use Hypervel\Sentry\Features\Ai\AiTracingScope;
use Hypervel\Sentry\Integration;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Sentry\TestCase;
use RuntimeException;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use stdClass;
use Throwable;

class ClassificationIntegrationTest extends TestCase
{
    private const string TYPESAFE_URL = 'https://api.typesafe.ai/v1/systemone';

    private const string AGENT_INVOCATION_ID = 'agent-invocation';

    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.tracing.http_client_requests' => false,
    ];

    /**
     * Supply fixture credentials after package configuration is loaded.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.typesafe.key', 'test-key');
    }

    /**
     * Load the optional AI package for classification tests.
     */
    protected function getPackageProviders(Application $app): array
    {
        return array_merge(parent::getPackageProviders($app), [AiServiceProvider::class]);
    }

    /**
     * Reject requests not handled by a test fixture.
     */
    protected function setUp(): void
    {
        $this->afterApplicationRefreshed(function (): void {
            Http::preventStrayRequests();
        });

        parent::setUp();
    }

    public function testEvaluateSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe('jev-latest');

        $span = $this->findEvaluateSpan($transaction);

        $this->assertNotNull($span);
        $this->assertSame('evaluate jev-latest', $span->getDescription());
        $this->assertSame('auto.ai.hypervel', $span->getOrigin());
        $this->assertSame($transaction->getSpanId(), $span->getParentSpanId());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());

        $data = $span->getData();
        $this->assertSame('evaluate', $data['gen_ai.operation.name']);
        $this->assertSame('typesafe', $data['gen_ai.provider.name']);
        $this->assertSame('jev-latest', $data['gen_ai.request.model']);
        $this->assertSame('jev-1.13.0', $data['gen_ai.response.model']);
        $this->assertSame(312, $data['gen_ai.usage.input_tokens']);
        $this->assertSame(48, $data['gen_ai.usage.output_tokens']);
        $this->assertSame(360, $data['gen_ai.usage.total_tokens']);
        $this->assertArrayNotHasKey('gen_ai.conversation.id', $data);
    }

    public function testRequestModelFallsBackToTheProviderDefault(): void
    {
        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $span = $this->findEvaluateSpan($transaction);

        $this->assertSame('evaluate jev-latest', $span->getDescription());
        $this->assertSame('jev-latest', $span->getData()['gen_ai.request.model']);
    }

    public function testParentSpanIsRestoredAfterClassification(): void
    {
        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testEvaluateSpanIsNestedUnderTheCurrentSpan(): void
    {
        $transaction = $this->startTransaction();
        $toolSpan = $transaction->startChild(SpanContext::make()->setOp('gen_ai.execute_tool'));
        $this->getSentryHubFromContainer()->setSpan($toolSpan);

        $this->classifyWithTypeSafe();

        $this->assertSame($toolSpan->getSpanId(), $this->findEvaluateSpan($transaction)->getParentSpanId());
        $this->assertSame($toolSpan, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testHttpClientSpanIsNestedUnderEvaluateSpan(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.http_client_requests' => true,
        ]);

        $transaction = $this->startTransaction();

        Http::globalMiddleware(fn (callable $handler): MockHandler => new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->typeSafeResponse())),
        ]));
        $this->classify();

        $evaluateSpan = $this->findEvaluateSpan($transaction);
        $httpSpan = $this->findSpanByOp($transaction, 'http.client');

        $this->assertNotNull($httpSpan);
        $this->assertSame('POST ' . self::TYPESAFE_URL, $httpSpan->getDescription());
        $this->assertSame($evaluateSpan->getSpanId(), $httpSpan->getParentSpanId());
    }

    public function testFakedClassificationIsRecorded(): void
    {
        Classification::fake();

        $transaction = $this->startTransaction();

        $this->classify('jev-latest');

        $span = $this->findEvaluateSpan($transaction);

        $this->assertNotNull($span);
        $this->assertSame('jev-latest', $span->getData()['gen_ai.response.model']);
        // Faked responses report no token usage
        $this->assertArrayNotHasKey('gen_ai.usage.input_tokens', $span->getData());
    }

    public function testNoSpanIsRecordedWithoutSampledParent(): void
    {
        $this->fakeTypeSafe();

        $this->classify();

        $this->assertNull($this->getSentryHubFromContainer()->getSpan());
        $this->assertNull(AiTracingScope::current());
    }

    public function testEvaluateSpanCanBeDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.gen_ai_evaluate' => false,
        ]);

        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $this->assertNull($this->findEvaluateSpan($transaction));
        $this->assertNull(AiTracingScope::current());
    }

    public function testEvaluateSpanIsDisabledWithGenAiTracing(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.gen_ai' => false,
        ]);

        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $this->assertNull($this->findEvaluateSpan($transaction));
    }

    public function testErrorResponseFinishesSpanWithHttpStatus(): void
    {
        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'invalid API key'], 401)]);

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(RequestException::class, $this->classifyExpectingFailure());

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::unauthenticated(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testServerErrorFinishesSpanAfterHttpClientSpan(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.http_client_requests' => true,
        ]);

        Http::globalMiddleware(fn (callable $handler): MockHandler => new MockHandler([
            new Response(500, ['Content-Type' => 'application/json'], '{"error":"internal error"}'),
        ]));

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(RequestException::class, $this->classifyExpectingFailure());

        $evaluateSpan = $this->findEvaluateSpan($transaction);
        $httpSpan = $this->findSpanByOp($transaction, 'http.client');

        $this->assertEquals(SpanStatus::internalError(), $evaluateSpan->getStatus());
        $this->assertNotNull($evaluateSpan->getEndTimestamp());
        $this->assertSame($evaluateSpan->getSpanId(), $httpSpan->getParentSpanId());
        $this->assertNotNull($httpSpan->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testConnectionFailureFinishesSpan(): void
    {
        Http::fake([self::TYPESAFE_URL => Http::failedConnection()]);

        $transaction = $this->startTransaction();

        // Call the provider directly, which dispatches no `ProviderFailedOver` event for the failure
        try {
            $this->app->make(AiManager::class)
                ->classificationProvider('typesafe')
                ->classify('Stripe connect keeps failing', $this->questions());

            $this->fail('Expected the classification to fail.');
        } catch (ProviderConnectionException $exception) {
            // Expected
        }

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testConnectionFailureForMisconfiguredProviderUrlFinishesSpan(): void
    {
        // An invalid port fails URI construction before any transport can send the request.
        config(['ai.providers.typesafe.url' => 'https://classifier.test:invalid/v1']);

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(MalformedUriException::class, $this->classifyExpectingFailure());

        $span = $this->findEvaluateSpan($transaction);
        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testFailoverRecordsASpanPerProvider(): void
    {
        config(['ai.providers.typesafe_backup' => [
            'driver' => 'typesafe',
            'key' => 'test-key',
            'url' => 'https://backup.typesafe.test/v1',
        ]]);

        Http::fake([
            self::TYPESAFE_URL => Http::response(['error' => 'rate limited'], 429),
            'https://backup.typesafe.test/v1/systemone' => Http::response($this->typeSafeResponse()),
        ]);

        $transaction = $this->startTransaction();

        $this->classify(null, ['typesafe' => 'jev-latest', 'typesafe_backup' => 'jev-latest']);

        $spans = $this->findEvaluateSpans($transaction);

        $this->assertCount(2, $spans);
        $this->assertSame('typesafe', $spans[0]->getData()['gen_ai.provider.name']);
        $this->assertEquals(SpanStatus::resourceExhausted(), $spans[0]->getStatus());
        $this->assertNotNull($spans[0]->getEndTimestamp());
        $this->assertSame('typesafe_backup', $spans[1]->getData()['gen_ai.provider.name']);
        $this->assertEquals(SpanStatus::ok(), $spans[1]->getStatus());
        $this->assertSame($transaction->getSpanId(), $spans[0]->getParentSpanId());
        $this->assertSame($transaction->getSpanId(), $spans[1]->getParentSpanId());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testFailoverWithoutHttpResponseFinishesSpan(): void
    {
        $attempts = 0;
        Classification::fake(function () use (&$attempts): array {
            if ($attempts++ === 0) {
                throw RateLimitedException::forProvider('typesafe');
            }

            return [];
        });

        $transaction = $this->startTransaction();

        $this->classify(null, ['typesafe' => 'jev-latest', 'openrouter' => 'jev-latest']);

        $spans = $this->findEvaluateSpans($transaction);

        $this->assertCount(2, $spans);
        $this->assertEquals(SpanStatus::internalError(), $spans[0]->getStatus());
        $this->assertNotNull($spans[0]->getEndTimestamp());
        $this->assertEquals(SpanStatus::ok(), $spans[1]->getStatus());
        $this->assertSame($transaction->getSpanId(), $spans[1]->getParentSpanId());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testOpenRouterErrorResponseIsMatched(): void
    {
        config(['ai.providers.openrouter.key' => 'test-key']);

        Http::fake(['https://openrouter.ai/api/alpha/decisions' => Http::response(['error' => 'invalid request'], 422)]);

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(RequestException::class, $this->classifyExpectingFailure('openrouter'));

        $span = $this->findEvaluateSpan($transaction);

        $this->assertSame('openrouter', $span->getData()['gen_ai.provider.name']);
        $this->assertEquals(SpanStatus::invalidArgument(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testConfiguredProviderUrlIsMatched(): void
    {
        // OpenRouter classifications go to `/alpha/decisions` below the configured URL
        config(['ai.providers.openrouter' => [
            'driver' => 'openrouter',
            'key' => 'test-key',
            'url' => 'https://proxy.test',
        ]]);

        Http::fake(['https://proxy.test/alpha/decisions' => Http::response(['error' => 'invalid request'], 422)]);

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(RequestException::class, $this->classifyExpectingFailure('openrouter'));

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::invalidArgument(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testCustomProviderErrorResponseIsMatched(): void
    {
        // A custom driver retains the same failure lifecycle as built-in providers.
        $this->app->make(AiManager::class)->extend('custom-classifier', function (Application $app, array $config): TypeSafeProvider {
            return new TypeSafeProvider($config, $app->make('events'));
        });
        config(['ai.providers.custom' => ['driver' => 'custom-classifier', 'key' => 'test-key']]);

        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'invalid request'], 422)]);

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(RequestException::class, $this->classifyExpectingFailure('custom'));

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::invalidArgument(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testRequestToAnotherHostIsNotClaimed(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response('unavailable', 500),
            self::TYPESAFE_URL => Http::response($this->typeSafeResponse()),
        ]);

        // Runs after our listener, so its request is sent while the evaluate span is current
        $this->app->make('events')->listen(Classifying::class, function (): void {
            Http::post('https://hooks.example.test/classifying');
        });

        $transaction = $this->startTransaction();

        $this->classify();

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::ok(), $span->getStatus());
        $this->assertSame('jev-1.13.0', $span->getData()['gen_ai.response.model']);
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testErrorResponseIsOnlyClaimedWhileTheEvaluateSpanIsCurrent(): void
    {
        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'internal error'], 500)]);

        $transaction = $this->startTransaction();
        $provider = $this->app->make(AiManager::class)->classificationProvider('typesafe');
        $prompt = new ClassificationPrompt('Stripe connect keeps failing', $this->questions(), $provider, 'jev-latest');
        $this->dispatchHypervelEvent(new Classifying('invocation', $provider, 'jev-latest', $prompt));

        // An unrelated request must not finish the open classification, even on the same endpoint.
        $this->getSentryHubFromContainer()->setSpan($transaction);
        Http::post(self::TYPESAFE_URL);

        $this->assertNull($this->findEvaluateSpan($transaction)->getEndTimestamp());
    }

    public function testClassificationInsideAnAgentIsNotRecordedAsChat(): void
    {
        // The agent's chat requests and the classification share the proxy host
        config(['ai.providers.openrouter' => [
            'driver' => 'openrouter',
            'key' => 'test-key',
            'url' => 'https://proxy.test',
        ]]);

        Http::fake(['https://proxy.test/alpha/decisions' => Http::response($this->typeSafeResponse())]);

        $transaction = $this->startTransaction();

        $agent = new class implements Agent {
            use Promptable;

            /**
             * Return the instructions.
             */
            public function instructions(): string
            {
                return '';
            }
        };
        $provider = $this->app->make(AiManager::class)->textProvider('openrouter');
        $this->dispatchHypervelEvent(new PromptingAgent('agent-invocation', new AgentPrompt($agent, 'Triage this ticket', [], $provider, 'gpt-4o')));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $toolSpan = $agentSpan->startChild(SpanContext::make()->setOp('gen_ai.execute_tool'));
        $this->getSentryHubFromContainer()->setSpan($toolSpan);

        $this->classify(null, 'openrouter');

        $this->assertNull($this->findSpanByOp($transaction, 'gen_ai.chat'));
        $this->assertSame($toolSpan->getSpanId(), $this->findEvaluateSpan($transaction)->getParentSpanId());
        $this->assertSame($toolSpan, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testEvaluateSpanInsideAnAgentGetsTheNewConversationId(): void
    {
        $transaction = $this->startTransaction();

        $prompt = $this->classifyInsideAgentTool($this->conversationalAgent()->forUser(new stdClass));

        $this->dispatchHypervelEvent(new AgentPrompted(self::AGENT_INVOCATION_ID, $prompt, $this->agentResponse('conv-new')));

        $this->assertSame('conv-new', $this->findEvaluateSpan($transaction)->getData()['gen_ai.conversation.id']);
    }

    public function testEvaluateSpanInsideAFailedAgentGetsTheConversationId(): void
    {
        $transaction = $this->startTransaction();

        $user = new stdClass;
        $agent = $this->conversationalAgent()->forUser($user);

        $prompt = $this->classifyInsideAgentTool($agent);

        $agent->continue('conv-failed', $user);
        $this->dispatchHypervelEvent(new AgentFailed(self::AGENT_INVOCATION_ID, $prompt, new RuntimeException('The provider failed.')));

        $this->assertSame('conv-failed', $this->findEvaluateSpan($transaction)->getData()['gen_ai.conversation.id']);
    }

    public function testEvaluateSpanAfterAnAgentGetsTheLatestConversationId(): void
    {
        $transaction = $this->startTransaction();

        $this->promptAgent($this->conversationalAgent()->continue('conv-first', new stdClass));
        $this->classifyWithTypeSafe();

        $this->promptAgent($this->conversationalAgent()->continue('conv-second', new stdClass));
        $this->classifyWithTypeSafe();

        $evaluateSpans = $this->findEvaluateSpans($transaction);

        $this->assertSame('conv-first', $evaluateSpans[0]->getData()['gen_ai.conversation.id']);
        $this->assertSame('conv-second', $evaluateSpans[1]->getData()['gen_ai.conversation.id']);
    }

    public function testEvaluateSpanGetsAManuallySetConversationId(): void
    {
        $transaction = $this->startTransaction();

        Integration::setConversationId('conv-manual');
        $this->classifyWithTypeSafe();

        Integration::setConversationId(null);
        $this->classifyWithTypeSafe();

        $evaluateSpans = $this->findEvaluateSpans($transaction);

        $this->assertSame('conv-manual', $evaluateSpans[0]->getData()['gen_ai.conversation.id']);
        $this->assertArrayNotHasKey('gen_ai.conversation.id', $evaluateSpans[1]->getData());
    }

    public function testEvaluateSpanInAnotherTraceHasNoConversationId(): void
    {
        $this->startTransaction();
        $this->promptAgent($this->conversationalAgent()->continue('conv-first', new stdClass));

        $transaction = $this->startTransaction();
        $this->classifyWithTypeSafe();

        $this->assertArrayNotHasKey('gen_ai.conversation.id', $this->findEvaluateSpan($transaction)->getData());
    }

    public function testMessagesAreRecordedInTheTypeSafeFormat(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);

        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $data = $this->findEvaluateSpan($transaction)->getData();

        $this->assertSame([[
            'type' => 'evaluation',
            'state' => 'Stripe connect keeps failing, losing sales, help ASAP',
            'questions' => [
                'is_urgent' => ['type' => 'noul', 'instructions' => 'Does this message convey urgency?'],
                'department' => [
                    'type' => 'choice',
                    'instructions' => 'Which team should handle this?',
                    'criteria' => ['billing' => 'Payments, invoicing, refunds', 'technical' => 'Bugs, outages, integrations'],
                ],
                'frustration' => [
                    'type' => 'score',
                    'instructions' => 'How frustrated is the customer?',
                    'criteria' => ['Calm', 'Frustrated but civil', 'Very angry'],
                ],
            ],
        ]], json_decode($data['gen_ai.input.messages'], true));

        $this->assertEquals([[
            'type' => 'evaluation',
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.92],
                'department' => [
                    'type' => 'choice',
                    'choice' => 'technical',
                    'probabilities' => ['billing' => 0.15, 'technical' => 0.85],
                    'confidence' => 0.82,
                ],
                'frustration' => [
                    'type' => 'score',
                    'score' => 1.6,
                    'probabilities' => [0 => 0.05, 1 => 0.3, 2 => 0.65],
                    'legend' => [0 => 'Calm', 1 => 'Frustrated but civil', 2 => 'Very angry'],
                    'confidence' => 0.78,
                ],
            ],
        ]], json_decode($data['gen_ai.output.messages'], true));

        // Score levels are keyed by number but must still be JSON objects, like in the JavaScript and Python SDKs
        $this->assertStringContainsString('"probabilities":{"0":0.05,"1":0.3,"2":0.65}', $data['gen_ai.output.messages']);
        $this->assertStringContainsString('"legend":{"0":"Calm","1":"Frustrated but civil","2":"Very angry"}', $data['gen_ai.output.messages']);
    }

    public function testZeroValuesAreKeptAndMissingConfidenceIsLeftOut(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);

        Http::fake([self::TYPESAFE_URL => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.0],
                // No confidence in the response
                'department' => ['type' => 'choice', 'choice' => 'billing', 'probabilities' => ['billing' => 1.0, 'technical' => 0.0]],
                'frustration' => [
                    'type' => 'score',
                    'score' => 0.0,
                    'legend' => ['0' => 'Calm', '1' => 'Frustrated but civil', '2' => 'Very angry'],
                    'probabilities' => ['0' => 1.0, '1' => 0.0, '2' => 0.0],
                    'confidence' => 0.0,
                ],
            ],
            'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
        ])]);

        $transaction = $this->startTransaction();

        $this->classify();

        $answers = json_decode($this->findEvaluateSpan($transaction)->getData()['gen_ai.output.messages'], true)[0]['answers'];

        $this->assertEquals(0, $answers['is_urgent']['noul']);
        $this->assertArrayNotHasKey('confidence', $answers['department']);
        $this->assertEquals(['billing' => 1, 'technical' => 0], $answers['department']['probabilities']);
        $this->assertEquals(0, $answers['frustration']['score']);
        $this->assertArrayHasKey('confidence', $answers['frustration']);
        $this->assertEquals(0, $answers['frustration']['confidence']);
        $this->assertEquals([0 => 1, 1 => 0, 2 => 0], $answers['frustration']['probabilities']);
    }

    public function testFailedClassificationOnlyRecordsInputMessages(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);

        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'invalid API key'], 401)]);

        $transaction = $this->startTransaction();

        $this->classifyExpectingFailure();

        $data = $this->findEvaluateSpan($transaction)->getData();

        $this->assertArrayHasKey('gen_ai.input.messages', $data);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $data);
    }

    public function testNoMessagesAreRecordedWithoutPii(): void
    {
        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $this->assertSpanDataContainsNoClassificationContent($this->findEvaluateSpan($transaction)->getData());
    }

    public function testNoMessagesAreRecordedOnFailedSpansWithoutPii(): void
    {
        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'invalid API key'], 401)]);

        $transaction = $this->startTransaction();

        $this->classifyExpectingFailure();

        $this->assertSpanDataContainsNoClassificationContent($this->findEvaluateSpan($transaction)->getData());
    }

    public function testDataCollectionRecordsMessagesByDefault(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => false, 'sentry.data_collection' => []]);

        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $data = $this->findEvaluateSpan($transaction)->getData();

        $this->assertArrayHasKey('gen_ai.input.messages', $data);
        $this->assertArrayHasKey('gen_ai.output.messages', $data);
    }

    public function testDataCollectionGenAiInputsCanBeDisabled(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'sentry.data_collection' => ['gen_ai' => ['inputs' => false]]]);

        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $data = $this->findEvaluateSpan($transaction)->getData();

        $this->assertArrayNotHasKey('gen_ai.input.messages', $data);
        $this->assertArrayHasKey('gen_ai.output.messages', $data);
    }

    public function testDataCollectionGenAiOutputsCanBeDisabled(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'sentry.data_collection' => ['gen_ai' => ['outputs' => false]]]);

        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $data = $this->findEvaluateSpan($transaction)->getData();

        $this->assertArrayHasKey('gen_ai.input.messages', $data);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $data);
    }

    public function testLongStateIsTruncated(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);

        $this->fakeTypeSafe();

        $transaction = $this->startTransaction();

        // Text with spaces, as a long run of letters would be redacted as a base64 blob instead
        Classification::of(str_repeat('word ', 3000))
            ->questions($this->questions())
            ->classify('typesafe');

        $input = json_decode($this->findEvaluateSpan($transaction)->getData()['gen_ai.input.messages'], true);

        $this->assertSame(substr(str_repeat('word ', 3000), 0, 10000) . '...', $input[0]['state']);
    }

    public function testCustomQuestionIsRecordedAsItsArray(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);

        $this->fakeTypeSafe();

        $transaction = $this->startTransaction();

        Classification::of('Stripe connect keeps failing')
            ->questions(['custom' => new class implements Question {
                /**
                 * Return the custom question representation.
                 */
                public function toArray(): array
                {
                    return ['type' => 'noul', 'instructions' => 'Is this a custom question?', 'criteria' => ['true' => 'Yes']];
                }
            }])
            ->classify('typesafe');

        $input = json_decode($this->findEvaluateSpan($transaction)->getData()['gen_ai.input.messages'], true);

        $this->assertSame(
            ['type' => 'noul', 'instructions' => 'Is this a custom question?', 'criteria' => ['true' => 'Yes']],
            $input[0]['questions']['custom']
        );
    }

    /**
     * Assert private classification content is absent from every attribute.
     */
    private function assertSpanDataContainsNoClassificationContent(array $data): void
    {
        $this->assertArrayNotHasKey('gen_ai.input.messages', $data);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $data);

        // The state, questions and answers must not reach the span through any other attribute either
        $encoded = json_encode($data);
        foreach (['Stripe connect', 'convey urgency', 'Payments, invoicing', 'Frustrated but civil', 'probabilities', 'technical'] as $content) {
            $this->assertStringNotContainsString($content, $encoded);
        }
    }

    /**
     * Run a classification from a tool of the given agent, the way a tool would classify the conversation.
     */
    private function classifyInsideAgentTool(Agent $agent): AgentPrompt
    {
        $prompt = $this->agentPrompt($agent);
        $tool = $this->triageTool();

        $this->dispatchHypervelEvent(new PromptingAgent(self::AGENT_INVOCATION_ID, $prompt));
        $this->dispatchHypervelEvent(new InvokingTool(self::AGENT_INVOCATION_ID, 'tool-invocation', $agent, $tool, []));

        $this->classifyWithTypeSafe();

        $this->dispatchHypervelEvent(new ToolInvoked(self::AGENT_INVOCATION_ID, 'tool-invocation', $agent, $tool, [], 'technical', 0.1));

        return $prompt;
    }

    /**
     * Dispatch a remembered agent turn.
     *
     * @param Agent&RemembersConversationsContract $agent
     */
    private function promptAgent(Agent $agent): void
    {
        $prompt = $this->agentPrompt($agent);

        $this->dispatchHypervelEvent(new PromptingAgent(self::AGENT_INVOCATION_ID, $prompt));
        $this->dispatchHypervelEvent(new AgentPrompted(self::AGENT_INVOCATION_ID, $prompt, $this->agentResponse($agent->currentConversation())));
    }

    /**
     * Create an agent prompt.
     */
    private function agentPrompt(Agent $agent): AgentPrompt
    {
        config(['ai.providers.openai.key' => 'test-key']);

        return new AgentPrompt($agent, 'Triage this ticket', [], $this->app->make(AiManager::class)->textProvider('openai'), 'gpt-4o');
    }

    /**
     * Create a response associated with its stored conversation.
     */
    private function agentResponse(string $conversationId): AgentResponse
    {
        return (new AgentResponse(self::AGENT_INVOCATION_ID, 'Routed to the technical team', new TextUsage, new Meta('openai', 'gpt-4o')))
            ->withinConversation($conversationId);
    }

    /**
     * Create a remembering agent.
     *
     * @return Agent&RemembersConversationsContract
     */
    private function conversationalAgent(): Agent
    {
        return new class implements Agent, RemembersConversationsContract {
            use Promptable;
            use RemembersConversations;

            /**
             * Return the instructions.
             */
            public function instructions(): string
            {
                return '';
            }
        };
    }

    /**
     * Create a classification tool fixture.
     */
    private function triageTool(): Tool
    {
        return new class implements Tool {
            /**
             * Describe the tool.
             */
            public function description(): string
            {
                return 'Routes the ticket to a team.';
            }

            /**
             * Return the chosen team.
             */
            public function handle(ToolRequest $request): string
            {
                return 'technical';
            }

            /**
             * Describe the accepted arguments.
             */
            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };
    }

    /**
     * Classify a fixture using a fake TypeSafe response.
     */
    private function classifyWithTypeSafe(?string $model = null): ClassificationResponse
    {
        $this->fakeTypeSafe();

        return $this->classify($model);
    }

    /**
     * Classify the sample ticket.
     *
     * @param array<string, string>|string $provider
     */
    private function classify(?string $model = null, string|array $provider = 'typesafe'): ClassificationResponse
    {
        return Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
            ->questions($this->questions())
            ->classify($provider, $model);
    }

    /**
     * Return representative classification questions.
     */
    private function questions(): array
    {
        return [
            'is_urgent' => new Boolean('Does this message convey urgency?'),
            'department' => new Choice('Which team should handle this?', [
                'billing' => 'Payments, invoicing, refunds',
                'technical' => 'Bugs, outages, integrations',
            ]),
            'frustration' => new Score('How frustrated is the customer?', [
                'Calm', 'Frustrated but civil', 'Very angry',
            ]),
        ];
    }

    /**
     * Return the classification failure.
     */
    private function classifyExpectingFailure(string $provider = 'typesafe'): Throwable
    {
        try {
            $this->classify(null, $provider);
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Expected the classification to fail.');
    }

    /**
     * Fake the TypeSafe endpoint.
     */
    private function fakeTypeSafe(): void
    {
        Http::fake([self::TYPESAFE_URL => Http::response($this->typeSafeResponse())]);
    }

    /**
     * Return a TypeSafe response with all question types.
     */
    private function typeSafeResponse(): array
    {
        return [
            'model' => 'jev-1.13.0',
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.92],
                'department' => [
                    'type' => 'choice',
                    'choice' => 'technical',
                    'probabilities' => ['billing' => 0.15, 'technical' => 0.85],
                    'confidence' => 0.82,
                ],
                'frustration' => [
                    'type' => 'score',
                    'score' => 1.6,
                    'legend' => ['0' => 'Calm', '1' => 'Frustrated but civil', '2' => 'Very angry'],
                    'probabilities' => ['0' => 0.05, '1' => 0.3, '2' => 0.65],
                    'confidence' => 0.78,
                ],
            ],
            'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
        ];
    }

    /**
     * Find the first evaluation span.
     */
    private function findEvaluateSpan(Transaction $transaction): ?Span
    {
        return $this->findSpanByOp($transaction, 'gen_ai.evaluate');
    }

    /**
     * Return all evaluation spans.
     *
     * @return Span[]
     */
    private function findEvaluateSpans(Transaction $transaction): array
    {
        return array_values(array_filter($transaction->getSpanRecorder()->getSpans(), function (Span $span): bool {
            return $span->getOp() === 'gen_ai.evaluate';
        }));
    }

    /**
     * Find a recorded operation.
     */
    private function findSpanByOp(Transaction $transaction, string $op): ?Span
    {
        foreach ($transaction->getSpanRecorder()->getSpans() as $span) {
            if ($span->getOp() === $op) {
                return $span;
            }
        }

        return null;
    }
}
