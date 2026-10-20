<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\Ai\Fixtures;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialsInterface;
use Closure;
use Generator;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Attributes\ConcurrentTools;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Gateway\Bedrock\BedrockCredentials;
use Hypervel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Hypervel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Providers\BedrockProvider;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Tools\LoadSkill;
use Hypervel\Ai\Tools\Request as ToolRequest;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\BedrockHelpers;
use Hypervel\Tests\Ai\Fixtures\OpenAiHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

class RuntimeBenchmark extends TestCase
{
    use BedrockHelpers;
    use OpenAiHelpers;

    /**
     * Configure dummy credentials without enabling external requests.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'ai.providers.openai' => ['driver' => 'openai', 'key' => 'benchmark-key'],
            'ai.providers.bedrock' => [
                'driver' => 'bedrock',
                'region' => 'us-east-1',
                'access_key_id' => 'benchmark-access',
                'secret_access_key' => 'benchmark-secret',
                'use_default_credential_provider' => false,
            ],
        ]);
    }

    #[DataProvider('providerModes')]
    public function testProviderLookup(bool $resolved): void
    {
        $manager = app(AiManager::class);
        $account = 0;

        if ($resolved) {
            $manager->resolveProviderConfigUsing(static function (string $name, array $config) use (&$account): array {
                return [...$config, 'key' => 'benchmark-' . ++$account];
            });
        }

        $provider = $this->measure(
            $resolved ? 'provider.resolved_accounts' : 'provider.fixed',
            10000,
            static fn (): TextProvider => $manager->textProvider('openai'),
        );

        $this->assertSame($resolved ? 'benchmark-' . $account : 'benchmark-key', $provider->providerCredentials()['key']);
    }

    /**
     * Supply fixed and operation-resolved provider configurations.
     */
    public static function providerModes(): array
    {
        return ['fixed' => [false], 'resolved accounts' => [true]];
    }

    #[DataProvider('bedrockModes')]
    public function testBedrockClientConstruction(bool $defaultChain): void
    {
        if ($defaultChain) {
            config([
                'ai.providers.bedrock.access_key_id' => null,
                'ai.providers.bedrock.secret_access_key' => null,
                'ai.providers.bedrock.use_default_credential_provider' => true,
            ]);
        }

        $factory = new class {
            use CreatesBedrockClient {
                createBedrockClient as public;
            }
        };
        /** @var BedrockProvider $provider */
        $provider = app(AiManager::class)->textProvider('bedrock');

        if ($defaultChain) {
            // Exercise the production credential wrapper without invoking discovery or metadata services.
            $provider->bedrockCredentials()->get(static fn (): Credentials => new Credentials('benchmark-access', 'benchmark-secret'));
        }

        $client = $this->measure(
            'bedrock.client_construction.' . ($defaultChain ? 'default_chain' : 'static_keys'),
            500,
            static fn (): BedrockRuntimeClient => $factory->createBedrockClient($provider, 60),
        );

        $this->assertSame('us-east-1', $client->getRegion());
        $this->assertSame('benchmark-access', $client->getCredentials()->wait()->getAccessKeyId());
    }

    /**
     * Supply direct credentials and the completed default-chain credential path.
     */
    public static function bedrockModes(): array
    {
        return ['static keys' => [false], 'default chain' => [true]];
    }

    #[DataProvider('bedrockStepModes')]
    public function testBedrockStep(bool $streamed, bool $reused): void
    {
        $delta = str_repeat('x', 32);
        $text = str_repeat($delta, 32);
        $usage = ['inputTokens' => 8, 'outputTokens' => 32, 'totalTokens' => 40];
        $contentType = $streamed ? 'application/vnd.amazon.eventstream' : 'application/json';

        if ($streamed) {
            $body = $this->eventFrame('messageStart', '{"role":"assistant"}');

            for ($index = 0; $index < 32; ++$index) {
                $body .= $this->eventFrame('contentBlockDelta', json_encode([
                    'contentBlockIndex' => 0, 'delta' => ['text' => $delta],
                ], JSON_THROW_ON_ERROR));
            }

            $body .= $this->eventFrame('contentBlockStop', '{"contentBlockIndex":0}')
                . $this->eventFrame('messageStop', '{"stopReason":"end_turn"}')
                . $this->eventFrame('metadata', json_encode(['usage' => $usage, 'metrics' => ['latencyMs' => 1]], JSON_THROW_ON_ERROR));
        } else {
            $body = json_encode([
                'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => $text]]]],
                'stopReason' => 'end_turn',
                'usage' => $usage,
                'metrics' => ['latencyMs' => 1],
            ], JSON_THROW_ON_ERROR);
        }

        $lastRequest = null;
        $lastOptions = [];
        $transport = static function (RequestInterface $request, array $options) use ($body, $contentType, &$lastRequest, &$lastOptions): FulfilledPromise {
            $lastRequest = $request;
            $lastOptions = $options;

            return new FulfilledPromise(new Response(200, ['Content-Type' => $contentType], $body));
        };
        $http = new RuntimeBenchmarkHttpFactory($transport, app(Dispatcher::class));
        Http::swap($http->registerConnection(AiManager::HTTP_CONNECTION));
        $provider = app(AiManager::class)->textProvider('bedrock');
        $gateway = new class extends BedrockTextGateway {
            public int $constructions = 0;

            private ?BedrockRuntimeClient $benchmarkClient = null;

            /**
             * Construct the benchmark-only reused client outside measurement.
             */
            public function prime(Provider $provider): void
            {
                $this->benchmarkClient = $this->createBedrockClient($provider, 60);
            }

            /**
             * Compare the production factory with one retained benchmark client.
             */
            protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
            {
                if ($this->benchmarkClient !== null) {
                    return $this->benchmarkClient;
                }

                ++$this->constructions;

                return parent::createBedrockClient($provider, $timeout);
            }
        };

        if ($reused) {
            $gateway->prime($provider);
        }

        $arguments = [$provider, 'benchmark-model', 'Answer the question.', [new UserMessage('Explain the supplied document.')], [], null, null, 60, new StepContext];
        $result = $this->measure(
            'bedrock.step.' . ($streamed ? 'streamed' : 'buffered') . '.' . ($reused ? 'reused' : 'fresh'),
            100,
            static function () use ($gateway, $arguments, $streamed): StepResponse {
                if (! $streamed) {
                    return $gateway->generateTextStep(...$arguments);
                }

                $stream = $gateway->generateStreamStep('benchmark', ...$arguments);

                foreach ($stream as $event);

                return $stream->getReturn();
            },
        );

        $this->assertSame($text, $result->text);
        $this->assertSame(8, $result->usage->inputTokens);
        $this->assertSame(32, $result->usage->outputTokens);
        $this->assertSame(FinishReason::Stop, $result->finishReason);
        $this->assertSame([], $result->toolCalls);
        $this->assertSame(60.0, (float) $lastOptions['timeout']);
        $this->assertStringContainsString('Credential=benchmark-access/', $lastRequest->getHeaderLine('Authorization'));
        $this->assertSame('Explain the supplied document.', json_decode((string) $lastRequest->getBody(), true, flags: JSON_THROW_ON_ERROR)['messages'][0]['content'][0]['text']);

        if ($reused) {
            $this->assertSame(1, $gateway->constructions);
        } else {
            $this->assertGreaterThan(1, $gateway->constructions);
        }
    }

    /**
     * Compare complete buffered and streamed steps with fresh and reused SDK clients.
     */
    public static function bedrockStepModes(): array
    {
        return [
            'buffered fresh' => [false, false],
            'buffered reused' => [false, true],
            'streamed fresh' => [true, false],
            'streamed reused' => [true, true],
        ];
    }

    public function testCompletedBedrockCredentialReuse(): void
    {
        $holder = new BedrockCredentials;
        $loads = 0;
        $credentials = new Credentials('benchmark-access', 'benchmark-secret');
        $resolve = static function () use (&$loads, $credentials): Credentials {
            ++$loads;

            return $credentials;
        };

        $result = $this->measure('bedrock.completed_credentials', 10000, static fn (): CredentialsInterface => $holder->get($resolve));

        $this->assertSame($credentials, $result);
        $this->assertSame(1, $loads);
    }

    #[DataProvider('skillModes')]
    public function testSkillDiscovery(int $count, bool $cached): void
    {
        $directory = ParallelTesting::tempDir('AiRuntimeBenchmark');
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->makeDirectory($directory, recursive: true);
        $instructions = substr(str_repeat("Read the supplied document, identify its main claims, and explain them with references to the relevant passages.\n", 40), 0, 4096);

        try {
            for ($index = 0; $index < $count; ++$index) {
                $path = $directory . '/skill-' . $index;
                $files->makeDirectory($path);
                $files->put($path . '/SKILL.md', "---\nname: skill-{$index}\ndescription: Explain document {$index}.\nlicense: MIT\nmetadata:\n  audience: contributors\n---\n{$instructions}\n");
            }

            config(['ai.skills.cached_paths' => $cached ? [$directory] : []]);
            LoadSkill::flushState();
            $description = $this->measure(
                'skills.' . $count . '.' . ($cached ? 'cached' : 'uncached'),
                200,
                static fn (): string => (string) (new LoadSkill([$directory]))->description(),
            );

            $this->assertSame($count, substr_count($description, ': Explain document '));
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    /**
     * Supply modest and larger fixed skill directories.
     */
    public static function skillModes(): array
    {
        return [
            '20 cached' => [20, true],
            '20 uncached' => [20, false],
            '100 cached' => [100, true],
            '100 uncached' => [100, false],
        ];
    }

    #[DataProvider('executionModes')]
    public function testToolExecution(bool $streamed, bool $concurrent): void
    {
        $provider = app(AiManager::class)->textProvider('openai');
        $tool = new RuntimeBenchmarkTool;
        $loop = new TextGenerationLoop(new RuntimeBenchmarkGateway);
        $agent = $concurrent ? new RuntimeBenchmarkConcurrentAgent('', [], []) : new AnonymousAgent('', [], []);
        $options = new TextGenerationOptions(agent: $agent);

        $text = $this->measure(
            'tools.' . ($streamed ? 'streamed' : 'buffered') . '.' . ($concurrent ? 'concurrent' : 'serial'),
            20,
            static function () use ($loop, $provider, $tool, $options, $streamed): string {
                if (! $streamed) {
                    return $loop->generate($provider, 'benchmark-model', '', tools: [$tool], options: $options)->text;
                }

                $text = '';
                foreach ($loop->stream('benchmark', $provider, 'benchmark-model', '', tools: [$tool], options: $options) as $event) {
                    if ($event instanceof TextDelta) {
                        $text .= $event->delta;
                    }
                }

                return $text;
            },
        );

        $this->assertSame('call-0,call-1,call-2,call-3', $text);
        $this->assertSame($concurrent ? 4 : 1, $tool->peak);
        $this->assertSame(0, $tool->active);
    }

    #[DataProvider('executionModes')]
    public function testConversationTitles(bool $streamed, bool $concurrent): void
    {
        config([
            'ai.conversations.generate_title' => true,
            'ai.conversations.concurrent_title' => $concurrent,
            'ai.providers.openai.models.text.cheapest' => 'benchmark-title',
        ]);
        $bodies = [];
        foreach (['benchmark-main' => 'Benchmark answer', 'benchmark-title' => 'Benchmark title'] as $model => $text) {
            $completed = $this->responseCompleted(2, 2, output: [[
                'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]],
            ]]);
            $bodies[$model] = [
                'buffered' => json_encode($completed['response'], JSON_THROW_ON_ERROR),
                'streamed' => $this->ssePayload([$this->responseCreated(), $this->outputTextDelta($text), $this->outputTextDone($text), $completed]),
            ];
        }

        $active = 0;
        $peak = 0;
        $requests = 0;
        $invocations = 0;
        $transport = static function (RequestInterface $request, array $options) use ($bodies, &$active, &$peak, &$requests): FulfilledPromise {
            $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $peak = max($peak, ++$active);
            ++$requests;

            try {
                usleep(20000);

                return new FulfilledPromise(new Response(200, [
                    'Content-Type' => ($payload['stream'] ?? false) ? 'text/event-stream' : 'application/json',
                ], $bodies[$payload['model']][($payload['stream'] ?? false) ? 'streamed' : 'buffered']));
            } finally {
                --$active;
            }
        };
        $http = new RuntimeBenchmarkHttpFactory($transport, app(Dispatcher::class));
        Http::swap($http->registerConnection(AiManager::HTTP_CONNECTION));

        $response = $this->measure(
            'titles.' . ($streamed ? 'streamed' : 'buffered') . '.' . ($concurrent ? 'concurrent' : 'serial'),
            20,
            static function () use ($streamed, &$invocations): AgentResponse|StreamableAgentResponse {
                ++$invocations;
                $agent = (new RememberingAssistantAgent)->forUser((object) ['id' => 1]);

                if (! $streamed) {
                    return $agent->prompt('Hello', provider: 'openai', model: 'benchmark-main');
                }

                $response = $agent->stream('Hello', provider: 'openai', model: 'benchmark-main');
                foreach ($response as $event);

                return $response;
            },
        );

        $this->assertSame('Benchmark answer', $response->text);
        $this->assertSame(2 * $invocations, $requests);
        $this->assertSame($concurrent ? 2 : 1, $peak);
        $this->assertSame(0, $active);
        $this->assertDatabaseHas('agent_conversations', ['id' => $response->conversationId, 'title' => 'Benchmark title']);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $response->assistantMessageId, 'content' => 'Benchmark answer']);
    }

    /**
     * Compare both delivery paths with serial and opted-in concurrent execution.
     */
    public static function executionModes(): array
    {
        return [
            'buffered serial' => [false, false], 'buffered concurrent' => [false, true],
            'streamed serial' => [true, false], 'streamed concurrent' => [true, true],
        ];
    }

    #[DataProvider('streamModes')]
    public function testStreamResponse(int $deltas, string $mode): void
    {
        $request = Request::create('/');
        $contextCalls = 0;
        $lastChunk = '';
        $runner = static function (Closure $callback) use (&$contextCalls): mixed {
            ++$contextCalls;
            $previous = CoroutineContext::get('benchmark.account');
            CoroutineContext::set('benchmark.account', 'account-1');

            try {
                return $callback();
            } finally {
                CoroutineContext::set('benchmark.account', $previous);
            }
        };
        $response = $this->measure('stream.' . $deltas . '.' . $mode, 200, static function () use ($deltas, $mode, $runner, $request, &$lastChunk): StreamableAgentResponse {
            $response = new StreamableAgentResponse('invocation', static function () use ($deltas): Generator {
                yield new StreamStart('start', 'benchmark', 'model', 1);
                yield new TextStart('text-start', 'message', 1);

                for ($index = 0; $index < $deltas; ++$index) {
                    yield new TextDelta('delta-' . $index, 'message', str_repeat('x', 32), 1);
                }

                yield new TextEnd('text-end', 'message', 1);
                yield new StreamEnd('end', 'stop', new TextUsage(2, $deltas), 1);
            });

            if ($mode === 'context') {
                $response->usingContext($runner);
            }

            if ($mode === 'vercel' || $mode === 'agui') {
                $mode === 'vercel'
                    ? $response->usingVercelDataProtocol()
                    : $response->usingAgentUserInteractionProtocol();

                /** @var IterableStreamedResponse $httpResponse */
                $httpResponse = $response->toResponse($request);
                $httpResponse->streamTo(static function (string $chunk) use (&$lastChunk): bool {
                    $lastChunk = $chunk;

                    return true;
                });
            } else {
                foreach ($response as $event);
            }

            return $response;
        });

        $this->assertSame(str_repeat('x', 32 * $deltas), $response->text);
        $this->assertSame($deltas, $response->usage->outputTokens);
        $this->assertCount($deltas + 4, $response->events);
        $this->assertSame($mode === 'context', $contextCalls > 0);
        $this->assertNull(CoroutineContext::get('benchmark.account'));

        if ($mode === 'vercel') {
            $this->assertSame("data: [DONE]\n\n", $lastChunk);
        } elseif ($mode === 'agui') {
            $this->assertStringContainsString('"type":"RUN_FINISHED"', $lastChunk);
        }
    }

    /**
     * Supply short and longer streams through each response path.
     */
    public static function streamModes(): array
    {
        $cases = [];

        foreach ([32, 1024] as $deltas) {
            foreach (['plain', 'context', 'vercel', 'agui'] as $mode) {
                $cases[$deltas . ' ' . $mode] = [$deltas, $mode];
            }
        }

        return $cases;
    }

    /**
     * Record first-use and warm costs, keeping setup and correctness checks outside the loop.
     */
    private function measure(string $case, int $iterations, Closure $operation): mixed
    {
        $smoke = getenv('BENCH_SMOKE') === '1';
        $iterations = $smoke ? 3 : $iterations;
        $result = null;

        for ($sample = 0; $sample <= ($smoke ? 1 : 5); ++$sample) {
            if ($sample === 1) {
                for ($warmup = 0; $warmup < 3; ++$warmup) {
                    $result = $operation();
                }
            }

            unset($result);
            gc_collect_cycles();
            memory_reset_peak_usage();
            $heapBefore = memory_get_usage();
            $cpuBefore = getrusage();
            $gcBefore = gc_status();
            $count = $sample === 0 ? 1 : $iterations;
            $started = hrtime(true);

            for ($iteration = 0; $iteration < $count; ++$iteration) {
                $result = $operation();
            }

            $elapsed = hrtime(true) - $started;
            $cpuAfter = getrusage();
            $gcAfter = gc_status();
            file_put_contents((string) getenv('BENCH_OUT'), json_encode([
                'case' => $case,
                'phase' => $sample === 0 ? 'first_use' : 'warm',
                'sample' => $sample,
                'iterations' => $count,
                'smoke' => $smoke,
                'wall_us_per_operation' => $elapsed / 1000 / $count,
                'cpu_us_per_operation' => (
                    ($cpuAfter['ru_utime.tv_sec'] + $cpuAfter['ru_stime.tv_sec'] - $cpuBefore['ru_utime.tv_sec'] - $cpuBefore['ru_stime.tv_sec']) * 1e6
                    + $cpuAfter['ru_utime.tv_usec'] + $cpuAfter['ru_stime.tv_usec'] - $cpuBefore['ru_utime.tv_usec'] - $cpuBefore['ru_stime.tv_usec']
                ) / $count,
                'heap_before' => $heapBefore,
                'heap_after' => memory_get_usage(),
                'peak_heap' => memory_get_peak_usage(),
                'gc_runs' => $gcAfter['runs'] - $gcBefore['runs'],
            ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
        }

        return $result;
    }
}

#[ConcurrentTools(max: 4)]
class RuntimeBenchmarkConcurrentAgent extends AnonymousAgent
{
}

class RuntimeBenchmarkTool implements Tool
{
    public int $active = 0;

    public int $peak = 0;

    /**
     * Return the tool name.
     */
    public function name(): string
    {
        return 'benchmark_tool';
    }

    /**
     * Describe the local tool operation.
     */
    public function description(): string
    {
        return 'Wait for local I/O and return the call ID.';
    }

    /**
     * Return the tool's empty argument schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Track overlapping calls through a local I/O delay.
     */
    public function handle(ToolRequest $request): string
    {
        $this->peak = max($this->peak, ++$this->active);

        try {
            usleep(20000);

            return (string) $request->toolCallId();
        } finally {
            --$this->active;
        }
    }
}

class RuntimeBenchmarkGateway implements StepTextGateway
{
    /**
     * Exercise the real tool loop without provider I/O or fake request history.
     */
    public function generateTextStep(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): StepResponse
    {
        $last = end($messages);

        if ($last instanceof ToolResultMessage) {
            return new StepResponse($last->toolResults->pluck('result')->implode(','), [], FinishReason::Stop, new TextUsage, new Meta);
        }

        return new StepResponse('', array_map(
            static fn (int $index): ToolCall => new ToolCall('call-' . $index, 'benchmark_tool', []),
            range(0, 3),
        ), FinishReason::ToolCalls, new TextUsage, new Meta);
    }

    /**
     * Return the same steps through the actual streaming tool loop.
     */
    public function generateStreamStep(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): Generator
    {
        $response = $this->generateTextStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);

        if ($response->text !== '') {
            yield new TextStart('start', 'message', time());
            yield new TextDelta('text', 'message', $response->text, time());
            yield new TextEnd('end', 'message', time());
        }

        return $response;
    }
}

class RuntimeBenchmarkHttpFactory extends Factory
{
    /**
     * Replace only the transport, retaining the framework's HTTP middleware and events.
     */
    public function __construct(private Closure $transport, Dispatcher $events)
    {
        parent::__construct($events);
    }

    /**
     * Return a local response without recording fake request history.
     */
    protected function createConnectionHandler(array $options): callable
    {
        return $this->transport;
    }
}
