<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Closure;
use Generator;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Attributes\RepairToolCalls;
use Hypervel\Ai\Contracts\Approvable;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\FakeAudioGateway;
use Hypervel\Ai\Gateway\ParentInvocation;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Ai\Responses\TextResponse;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Tools\Request;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    public function testOverlappingOnDemandProvidersKeepTheirCredentialsAndFlushLocalState(): void
    {
        $manager = $this->manager();
        $configured = $manager->instance();

        [$first, $second] = parallel([
            function () use ($manager): array {
                $provider = $manager->build(['name' => 'account', 'driver' => 'custom', 'key' => 'first-key']);
                usleep(5000);
                $this->assertSame($provider, $manager->instance('account'));
                $manager->flushState();

                return [$provider->providerCredentials(), WeakReference::create($provider)];
            },
            function () use ($manager): array {
                $provider = $manager->build(['name' => 'account', 'driver' => 'custom', 'key' => 'second-key']);
                usleep(10000);
                $this->assertSame($provider, $manager->instance('account'));

                return [$provider->providerCredentials(), WeakReference::create($provider)];
            },
        ]);

        $this->assertSame(['key' => 'first-key'], $first[0]);
        $this->assertSame(['key' => 'second-key'], $second[0]);
        $this->assertNull($first[1]->get());
        $this->assertNull($second[1]->get());
        $this->assertSame($configured, $manager->instance());
    }

    public function testProviderConfigFollowsOverlappingAndNestedAccountContexts(): void
    {
        $manager = $this->manager();
        $manager->resolveProviderConfigUsing(static fn (string $name, array $config): array => [
            ...$config,
            'key' => CoroutineContext::get('ai-test.account') . '-key',
        ]);

        [$first, $second] = parallel([
            function () use ($manager): array {
                CoroutineContext::set('ai-test.account', 'first');
                $provider = $manager->instance();
                usleep(5000);
                $this->assertSame(['key' => 'first-key'], $manager->instance()->providerCredentials());

                CoroutineContext::set('ai-test.account', 'nested');
                $this->assertSame(['key' => 'nested-key'], $manager->instance()->providerCredentials());

                return [$provider->providerCredentials(), WeakReference::create($provider)];
            },
            function () use ($manager): array {
                CoroutineContext::set('ai-test.account', 'second');
                usleep(5000);

                return $manager->instance()->providerCredentials();
            },
        ]);

        $this->assertSame(['key' => 'first-key'], $first[0]);
        $this->assertSame(['key' => 'second-key'], $second);
        $this->assertNull($first[1]->get());

        $explicit = $manager->build(['driver' => 'custom', 'key' => 'explicit-key']);
        $this->assertSame(['key' => 'explicit-key'], $explicit->providerCredentials());
    }

    public function testExplicitProviderObjectsKeepTheirIdentityAfterNamedProviderReplacement(): void
    {
        $manager = $this->manager();
        $first = $manager->build(['name' => 'account', 'driver' => 'custom', 'key' => 'first-key']);
        $second = $manager->build(['name' => 'account', 'driver' => 'custom', 'key' => 'second-key']);

        $this->assertSame($first, $manager->instance($first));
        $this->assertSame($second, $manager->instance('account'));

        $manager->forgetInstance('account');
        $rebuilt = $manager->instance('account');
        $this->assertNotSame($second, $rebuilt);
        $this->assertSame(['key' => 'second-key'], $rebuilt->providerCredentials());

        $manager->purge('account');
        $purged = $manager->instance('account');
        $this->assertNotSame($rebuilt, $purged);
        $this->assertSame(['key' => 'second-key'], $purged->providerCredentials());

        $this->assertSame(
            [[$first, null], [$second, null], ['openai', null], ['configured', 'fallback-model']],
            Provider::providerAndModelPairs([$first, $second, Lab::OpenAI, 'configured' => 'fallback-model']),
        );
        $this->assertSame([['openai', 'model']], Provider::providerAndModelPairs(Lab::OpenAI, 'model'));
        $this->assertSame(['account' => null], Provider::formatProviderAndModelList($first));
    }

    public function testParentInvocationsStayLocalAndRestoreNestedFailures(): void
    {
        $failure = new RuntimeException('Nested tool failed.');

        [$first, $second] = parallel([
            function () use ($failure): array {
                return ParentInvocation::within('first', 'first-tool', function () use ($failure): array {
                    usleep(5000);

                    try {
                        ParentInvocation::within('nested', 'nested-tool', function () use ($failure): void {
                            $this->assertSame(['nested', 'nested-tool'], ParentInvocation::current());

                            throw $failure;
                        });
                    } catch (RuntimeException $exception) {
                        $this->assertSame($failure, $exception);
                    }

                    return ParentInvocation::current();
                });
            },
            function (): array {
                return ParentInvocation::within('second', 'second-tool', function (): array {
                    usleep(10000);

                    return ParentInvocation::current();
                });
            },
        ]);

        $this->assertSame(['first', 'first-tool'], $first);
        $this->assertSame(['second', 'second-tool'], $second);
        $this->assertSame([null, null], ParentInvocation::current());
    }

    public function testOverlappingCallbacksReserveSeparateSequenceEntries(): void
    {
        $provider = m::mock(AudioProvider::class);
        $provider->shouldReceive('name')->andReturn('audio');
        $gateway = new FakeAudioGateway([
            function (): string {
                usleep(5000);

                return base64_encode('first-audio');
            },
            base64_encode('second-audio'),
        ]);

        $responses = parallel([
            fn (): string => $gateway->generateAudio($provider, 'model', 'First text', 'voice')->audio,
            fn (): string => $gateway->generateAudio($provider, 'model', 'Second text', 'voice')->audio,
        ]);

        $this->assertSame([base64_encode('first-audio'), base64_encode('second-audio')], $responses);
    }

    public function testSharedGenerationLoopKeepsRepairSettingsLocalWhileApprovalChecksSuspend(): void
    {
        $repairStarted = new Channel(1);
        $ordinaryStarted = new Channel(1);
        $tool = m::mock(Tool::class, Approvable::class);
        $tool->shouldReceive('name')->andReturn('known');
        $tool->shouldReceive('shouldRequestApproval')->andReturnUsing(static function (Request $request) use ($repairStarted, $ordinaryStarted): null {
            if ($request['repair']) {
                $repairStarted->push(true);
                $ordinaryStarted->pop(1);
            } else {
                $ordinaryStarted->push(true);
                usleep(5000);
            }

            return null;
        });
        $tool->shouldReceive('handle')->twice()->andReturn('done');
        $provider = m::mock(TextProvider::class);
        $provider->shouldReceive('name')->andReturn('provider');
        $gateway = m::mock(StepTextGateway::class);
        $steps = [];
        $gateway->shouldReceive('generateTextStep')->times(4)->andReturnUsing(static function (...$arguments) use (&$steps): StepResponse {
            $instructions = $arguments[2];
            $step = $steps[$instructions] ?? 0;
            $steps[$instructions] = $step + 1;
            $calls = $step === 0 ? [new ToolCall('known', 'known', ['repair' => $instructions === 'repair'])] : [];

            if ($step === 0 && $instructions === 'repair') {
                $calls[] = new ToolCall('missing', 'missing', []);
            }

            return new StepResponse('done', $calls, $step === 0 ? FinishReason::ToolCalls : FinishReason::Stop, new TextUsage, new Meta);
        });
        $loop = new TextGenerationLoop($gateway);

        try {
            [$repaired, $ordinary] = parallel([
                fn (): TextResponse => $loop->generate($provider, 'model', 'repair', tools: [$tool], options: new TextGenerationOptions(maxSteps: 2, agent: new IsolationRepairAgent('', [], []))),
                function () use ($repairStarted, $loop, $provider, $tool): TextResponse {
                    $repairStarted->pop(1);

                    return $loop->generate($provider, 'model', 'ordinary', tools: [$tool], options: new TextGenerationOptions(maxSteps: 2));
                },
            ]);
        } finally {
            $repairStarted->close();
            $ordinaryStarted->close();
        }

        $this->assertTrue($repaired->toolResults->firstWhere('id', 'missing')->failed);
        $this->assertStringContainsString('Available tools: known.', $repaired->toolResults->firstWhere('id', 'missing')->result);
        $this->assertSame('done', $ordinary->text);
    }

    #[DataProvider('generatedResponses')]
    public function testGeneratedResponseKeepsItsFilenameAcrossCoroutines(GeneratedImage|AudioResponse $response): void
    {
        $disk = m::mock(Filesystem::class);
        $disk->shouldReceive('put')->with(m::type('string'), 'generated bytes', [])->times(3)->andReturnTrue();
        $filesystems = m::mock(FilesystemFactory::class);
        $filesystems->shouldReceive('disk')->with('generated')->times(3)->andReturn($disk);
        Container::getInstance()->instance(FilesystemFactory::class, $filesystems);

        $parentPath = $response->store('outputs', 'generated');
        $childPaths = parallel([
            fn (): string|false => $response->store('outputs', 'generated'),
            fn (): string|false => $response->store('outputs', 'generated'),
        ]);

        $this->assertSame([$parentPath, $parentPath], $childPaths);
    }

    /**
     * Provide generated responses with automatic filenames.
     */
    public static function generatedResponses(): array
    {
        return [
            'image' => [new GeneratedImage(base64_encode('generated bytes'), 'image/png')],
            'audio' => [new AudioResponse(base64_encode('generated bytes'), new Usage, new Meta, 'audio/wav')],
        ];
    }

    #[DataProvider('streamContexts')]
    public function testStreamProductionAndCallbacksRestoreTheirCapturedContext(?string $account): void
    {
        CoroutineContext::set('ai-test.account', $account);
        $observed = [];
        $response = (new StreamableAgentResponse('invocation', function () use (&$observed): Generator {
            $observed['factory'] = CoroutineContext::get('ai-test.account');

            return (function () use (&$observed): Generator {
                try {
                    $observed['first'] = CoroutineContext::get('ai-test.account');
                    yield new TextDelta('first', 'message', 'Hello', 1);
                    $observed['second'] = CoroutineContext::get('ai-test.account');
                    yield new TextDelta('second', 'message', ' world', 1);
                } finally {
                    $observed['cleanup'] = CoroutineContext::get('ai-test.account');
                }
            })();
        }))->usingContext($this->captureAccountContext());
        $response->then(function (StreamedAgentResponse $completed) use (&$observed): void {
            $observed['then'] = CoroutineContext::get('ai-test.account');
            $completed->withStoredMessages('user-message', 'assistant-message');
        });

        [$consumer] = parallel([
            function () use ($response, &$observed): string {
                CoroutineContext::set('ai-test.account', 'consumer');
                foreach ($response as $event) {
                    $this->assertSame('consumer', CoroutineContext::get('ai-test.account'));
                }

                $response->then(function () use (&$observed): void {
                    $observed['late-then'] = CoroutineContext::get('ai-test.account');
                });
                $response->each(function () use (&$observed): void {
                    $observed['each'] = CoroutineContext::get('ai-test.account');
                });

                return CoroutineContext::get('ai-test.account');
            },
        ]);

        $this->assertSame('consumer', $consumer);
        $this->assertSame(array_fill_keys(['factory', 'first', 'second', 'cleanup', 'then', 'late-then', 'each'], $account), $observed);
        $this->assertSame('Hello world', $response->text);
        $this->assertSame('user-message', $response->userMessageId);
        $this->assertSame('assistant-message', $response->assistantMessageId);
    }

    /**
     * Provide account and central contexts for lazy streams.
     */
    public static function streamContexts(): array
    {
        return ['account' => ['producer'], 'central' => [null]];
    }

    public function testStreamFailureCallbacksAndCleanupUseTheCapturedContext(): void
    {
        CoroutineContext::set('ai-test.account', 'producer');
        $failure = new RuntimeException('Provider failed.');
        $observed = [];
        $response = (new StreamableAgentResponse('invocation', function () use ($failure, &$observed): Generator {
            try {
                yield new TextDelta('event', 'message', 'Hello', 1);

                throw $failure;
            } finally {
                $observed['cleanup'] = CoroutineContext::get('ai-test.account');
            }
        }))->usingContext($this->captureAccountContext());
        $response->catch(function (RuntimeException $exception) use ($failure, &$observed): void {
            $this->assertSame($failure, $exception);
            $observed['catch'] = CoroutineContext::get('ai-test.account');
        });

        CoroutineContext::set('ai-test.account', 'consumer');

        try {
            iterator_to_array($response);
            $this->fail('The provider failure must reach the consumer.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(['cleanup' => 'producer', 'catch' => 'producer'], $observed);
        $this->assertSame('consumer', CoroutineContext::get('ai-test.account'));
    }

    public function testAbandonedStreamDisposesItsProducerInTheCapturedContext(): void
    {
        CoroutineContext::set('ai-test.account', 'producer');
        $cleanupAccount = null;
        $completed = false;
        $response = (new StreamableAgentResponse('invocation', function () use (&$cleanupAccount): Generator {
            try {
                yield new TextDelta('event', 'message', 'Hello', 1);
                yield new TextDelta('later', 'message', ' world', 1);
            } finally {
                $cleanupAccount = CoroutineContext::get('ai-test.account');
            }
        }))->usingContext($this->captureAccountContext());
        $response->then(function () use (&$completed): void {
            $completed = true;
        });

        CoroutineContext::set('ai-test.account', 'consumer');
        $iterator = $response->getIterator();
        $this->assertSame('Hello', $iterator->current()->delta);
        $this->assertSame('consumer', CoroutineContext::get('ai-test.account'));
        unset($iterator);

        $this->assertSame('producer', $cleanupAccount);
        $this->assertFalse($completed);
        $this->assertSame('consumer', CoroutineContext::get('ai-test.account'));
    }

    /**
     * Capture only the test account, including an empty central context.
     */
    protected function captureAccountContext(): Closure
    {
        $account = CoroutineContext::get('ai-test.account');

        return static function (Closure $callback) use ($account): mixed {
            $previous = CoroutineContext::get('ai-test.account');
            CoroutineContext::set('ai-test.account', $account);

            try {
                return $callback();
            } finally {
                CoroutineContext::set('ai-test.account', $previous);
            }
        };
    }

    /**
     * Create a manager with a custom provider requiring no external service.
     */
    protected function manager(): AiManager
    {
        $application = m::mock(Application::class);
        $application->shouldReceive('make')->with('config')->andReturn(new Repository([
            'ai' => [
                'default' => 'configured',
                'providers' => ['configured' => ['driver' => 'custom', 'key' => 'configured-key']],
            ],
        ]));
        $gateway = m::mock(Gateway::class);
        $events = m::mock(Dispatcher::class);
        $manager = new AiManager($application);
        $manager->extend('custom', static fn (Application $application, array $config): Provider => new IsolationTestProvider($gateway, $config, $events));

        return $manager;
    }
}

class IsolationTestProvider extends Provider
{
}

#[RepairToolCalls]
class IsolationRepairAgent extends AnonymousAgent
{
}
