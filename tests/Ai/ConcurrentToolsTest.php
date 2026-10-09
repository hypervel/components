<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Closure;
use Generator;
use Hypervel\Ai\Ai;
use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Attributes\ConcurrentTools;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\CanActAsTool;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Hypervel\Ai\Tools\AgentTool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class ConcurrentToolsTest extends TestCase
{
    #[DataProvider('deliveryModes')]
    public function testOptedInToolsAreBoundedOrderedAndUseTheCapturedContext(bool $streaming): void
    {
        CoroutineContext::set('tool-test.account', 'caller');
        Ai::captureContextUsing(static function (): Closure {
            $account = CoroutineContext::get('tool-test.account');

            return static function (Closure $work) use ($account): mixed {
                $previous = CoroutineContext::get('tool-test.account');
                CoroutineContext::set('tool-test.account', $account);

                try {
                    return $work();
                } finally {
                    CoroutineContext::set('tool-test.account', $previous);
                }
            };
        });
        Coroutine::afterCreated(static function (): void {
            CoroutineContext::set('tool-test.account', 'startup');
        });
        $providerGateway = m::mock(Gateway::class);
        Ai::extend('tool-test', static fn (Application $app, array $config): Provider => new class($providerGateway, $config, $app->make(Dispatcher::class)) extends Provider {});
        $accountProvider = Ai::build(['name' => 'tool-account', 'driver' => 'tool-test', 'key' => 'account-key']);
        $active = 0;
        $maximum = 0;
        $accounts = [];
        $resolvedProviders = [];
        $tool = m::mock(Tool::class);
        $tool->shouldReceive('name')->andReturn('tool');
        $tool->shouldReceive('handle')->times(3)->andReturnUsing(function (Request $request) use (&$active, &$maximum, &$accounts, &$resolvedProviders): string {
            $maximum = max($maximum, ++$active);
            $accounts[] = CoroutineContext::get('tool-test.account');
            $resolvedProviders[] = Ai::instance('tool-account');

            try {
                usleep($request->toolCallId() === 'first' ? 10000 : 5000);

                return $request->toolCallId();
            } finally {
                --$active;
            }
        });
        $calls = [new ToolCall('first', 'tool', []), new ToolCall('second', 'tool', []), new ToolCall('third', 'tool', [])];
        $provider = m::mock(TextProvider::class);
        $provider->shouldReceive('name')->andReturn('provider');
        $results = [];
        $gateway = $this->gateway($streaming, $calls, $results);
        $loop = new TextGenerationLoop($gateway);
        $options = new TextGenerationOptions(agent: new ConcurrentToolsTestAgent('', [], []));

        if ($streaming) {
            iterator_to_array($loop->stream('invocation', $provider, 'model', '', tools: [$tool], options: $options), false);
        } else {
            $loop->generate($provider, 'model', '', tools: [$tool], options: $options);
        }

        $this->assertSame(2, $maximum);
        $this->assertSame(0, $active);
        $this->assertSame(['caller', 'caller', 'caller'], $accounts);
        $this->assertSame([$accountProvider, $accountProvider, $accountProvider], $resolvedProviders);
        $this->assertSame(['first', 'second', 'third'], $results);
        $this->assertSame('caller', CoroutineContext::get('tool-test.account'));
    }

    /**
     * Provide both generation paths through the same tool group.
     */
    public static function deliveryModes(): array
    {
        return ['prompt' => [false], 'stream' => [true]];
    }

    public function testInvalidConcurrencyFailsClearlyThroughTheLoop(): void
    {
        $provider = m::mock(TextProvider::class);
        $provider->shouldReceive('name')->andReturn('provider');
        $gateway = m::mock(StepTextGateway::class);
        $gateway->shouldReceive('generateTextStep')->once()->andReturn(new StepResponse('Finished', [], FinishReason::Stop, new TextUsage, new Meta));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool concurrency must be at least one.');

        (new TextGenerationLoop($gateway))->generate($provider, 'model', '', options: new TextGenerationOptions(agent: new InvalidConcurrentToolsTestAgent('', [], [])));
    }

    public function testToolFailureCancelsAndJoinsTheOtherTools(): void
    {
        $failure = new RuntimeException('The tool failed.');
        $active = 0;
        $resumed = false;
        $tool = m::mock(Tool::class);
        $tool->shouldReceive('name')->andReturn('tool');
        $tool->shouldReceive('handle')->twice()->andReturnUsing(function (Request $request) use ($failure, &$active, &$resumed): string {
            ++$active;

            try {
                if ($request->toolCallId() === 'first') {
                    usleep(5000);

                    throw $failure;
                }

                usleep(1000000);
                $resumed = true;

                return 'unexpected completion';
            } finally {
                --$active;
            }
        });
        $provider = m::mock(TextProvider::class);
        $provider->shouldReceive('name')->andReturn('provider');
        $results = [];
        $gateway = $this->gateway(false, [new ToolCall('first', 'tool', []), new ToolCall('second', 'tool', [])], $results, completes: false);

        try {
            (new TextGenerationLoop($gateway))->generate($provider, 'model', '', tools: [$tool], options: new TextGenerationOptions(agent: new ConcurrentToolsTestAgent('', [], [])));
            $this->fail('The original tool failure must reach the caller.');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }

        $this->assertSame(0, $active);
        $this->assertFalse($resumed);
    }

    public function testAbandoningLiveSubAgentOutputCancelsAndJoinsItsProducers(): void
    {
        $active = 0;
        $resumed = false;
        $waiting = new Channel(1);
        $agent = m::mock(Agent::class, CanActAsTool::class);
        $agent->shouldReceive('name')->andReturn('child');
        $agent->shouldReceive('stream')->twice()->andReturnUsing(function (string $task) use (&$active, &$resumed, $waiting): StreamableAgentResponse {
            return new StreamableAgentResponse($task, function () use ($task, &$active, &$resumed, $waiting): Generator {
                ++$active;

                try {
                    yield new TextDelta($task, $task, str_repeat('x', 240), time());
                    $waiting->pop(1);
                    $resumed = true;
                } finally {
                    --$active;
                }
            });
        });
        $provider = m::mock(TextProvider::class);
        $provider->shouldReceive('name')->andReturn('provider');
        $results = [];
        $gateway = $this->gateway(true, [
            new ToolCall('first', 'child', ['task' => 'first']),
            new ToolCall('second', 'child', ['task' => 'second']),
        ], $results, completes: false);
        $iterator = (new TextGenerationLoop($gateway))->stream(
            'invocation',
            $provider,
            'model',
            '',
            tools: [new AgentTool($agent)],
            options: new TextGenerationOptions(agent: new ConcurrentToolsTestAgent('', [], [])),
        );
        $receivedLiveOutput = false;

        try {
            foreach ($iterator as $event) {
                if ($event instanceof ToolResultEvent && $event->preliminary) {
                    $receivedLiveOutput = true;
                    $this->assertSame(2, $active);
                    break;
                }
            }
        } finally {
            unset($iterator);
            $waiting->close();
        }

        $this->assertTrue($receivedLiveOutput);
        $this->assertSame(0, $active);
        $this->assertFalse($resumed);
    }

    #[DataProvider('cancellationModes')]
    public function testCancelingTheConsumerCancelsAndJoinsTheTools(bool $throwException): void
    {
        $active = 0;
        $resumed = false;
        $caught = null;
        $waiting = new Channel(1);
        $tool = m::mock(Tool::class);
        $tool->shouldReceive('name')->andReturn('tool');
        $tool->shouldReceive('handle')->twice()->andReturnUsing(function () use ($waiting, &$active, &$resumed): string {
            ++$active;

            try {
                $waiting->pop(1);
                $resumed = true;

                return 'unexpected completion';
            } finally {
                --$active;
            }
        });
        $provider = m::mock(TextProvider::class);
        $provider->shouldReceive('name')->andReturn('provider');
        $results = [];
        $gateway = $this->gateway(true, [new ToolCall('first', 'tool', []), new ToolCall('second', 'tool', [])], $results, completes: false);
        $loop = new TextGenerationLoop($gateway);
        $consumer = EngineCoroutine::create(function () use ($loop, $provider, $tool, &$caught): void {
            try {
                iterator_to_array($loop->stream('invocation', $provider, 'model', '', tools: [$tool], options: new TextGenerationOptions(agent: new ConcurrentToolsTestAgent('', [], []))), false);
            } catch (CanceledException $exception) {
                $caught = $exception;
            }
        });

        try {
            $this->assertSame(2, $active);
            $this->assertTrue(EngineCoroutine::cancelById($consumer->getId(), $throwException));
            Coroutine::join([$consumer->getId()], 1);

            $this->assertInstanceOf(CanceledException::class, $caught);
            $this->assertSame(0, $active);
            $this->assertFalse($resumed);
        } finally {
            $waiting->close();

            if (Coroutine::exists($consumer->getId())) {
                EngineCoroutine::cancelById($consumer->getId(), throwException: true);
                Coroutine::join([$consumer->getId()], 1);
            }
        }
    }

    public static function cancellationModes(): array
    {
        return ['throw' => [true], 'return false' => [false]];
    }

    /**
     * Request tools, then observe their results when generation continues.
     */
    private function gateway(bool $streaming, array $calls, array &$results, bool $completes = true): StepTextGateway
    {
        $step = 0;
        $response = static function (array $messages) use ($calls, &$results, &$step): StepResponse {
            if ($step++ === 0) {
                return new StepResponse('', $calls, FinishReason::ToolCalls, new TextUsage, new Meta);
            }

            $results = end($messages)->toolResults->pluck('result')->all();

            return new StepResponse('Finished', [], FinishReason::Stop, new TextUsage, new Meta);
        };
        $gateway = m::mock(StepTextGateway::class);

        if ($streaming) {
            $gateway->shouldReceive('generateStreamStep')->times($completes ? 2 : 1)->andReturnUsing(static function (...$arguments) use ($response): Generator {
                yield from [];

                return $response($arguments[4]);
            });
        } else {
            $gateway->shouldReceive('generateTextStep')->times($completes ? 2 : 1)->andReturnUsing(static fn (...$arguments): StepResponse => $response($arguments[3]));
        }

        return $gateway;
    }
}

#[ConcurrentTools(max: 2)]
class ConcurrentToolsTestAgent extends AnonymousAgent
{
}

#[ConcurrentTools(max: 0)]
class InvalidConcurrentToolsTestAgent extends AnonymousAgent
{
}
