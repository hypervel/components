<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway;

use Closure;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\ToolFailed;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Gateway\Concerns\InvokesTools;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Events\Dispatcher;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Stringable;

function toolInvokingGateway(): object
{
    return new class {
        use InvokesTools;

        /**
         * Invoke a tool with its run context.
         */
        public function invoke(Tool $tool, array $arguments = [], ?RunContext $context = null): string
        {
            return $this->executeTool($tool, $arguments, null, $context);
        }
    };
}

function stubTool(string $name, Closure $handler): Tool
{
    return new class($name, $handler) implements Tool {
        /**
         * Create the tool.
         */
        public function __construct(
            protected string $name,
            protected Closure $handler,
        ) {
        }

        /**
         * Get the tool description.
         */
        public function description(): string
        {
            return $this->name;
        }

        /**
         * Execute the tool.
         */
        public function handle(Request $request): Stringable|string
        {
            return call_user_func($this->handler, $request);
        }

        /**
         * Get the tool schema.
         *
         * @return array<string, Type>
         */
        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };
}

function stubRunContext(Dispatcher $events, string $invocationId = 'inv_1'): RunContext
{
    return new RunContext(
        $invocationId,
        m::mock(Agent::class),
        m::mock(TextProvider::class),
        'stub-model',
        $events,
    );
}

class InvokesToolsTest extends TestCase
{
    public function testEachToolInvocationReportsAgainstTheContextItWasGiven(): void
    {
        $seen = [];

        $context = function (string $label) use (&$seen): RunContext {
            $events = new Dispatcher;

            $events->listen(InvokingTool::class, function (InvokingTool $event) use (&$seen, $label): void {
                $seen[] = $label . ' invoking ' . $event->tool->description();
            });

            $events->listen(ToolInvoked::class, function (ToolInvoked $event) use (&$seen, $label): void {
                $seen[] = $label . ' invoked ' . $event->tool->description() . ':' . $event->result;
            });

            return stubRunContext($events);
        };

        $gateway = toolInvokingGateway();

        $nestedTool = stubTool('nested', fn (): string => 'nested result');

        $delegatingTool = stubTool('delegating', function () use ($gateway, $nestedTool, $context): string {
            $gateway->invoke($nestedTool, context: $context('sub'));

            return 'delegated result';
        });

        $siblingTool = stubTool('sibling', fn (): string => 'sibling result');

        $gateway->invoke($delegatingTool, context: $context('parent'));
        $gateway->invoke($siblingTool, context: $context('parent'));

        $this->assertSame([
            'parent invoking delegating',
            'sub invoking nested',
            'sub invoked nested:nested result',
            'parent invoked delegating:delegated result',
            'parent invoking sibling',
            'parent invoked sibling:sibling result',
        ], $seen);
    }

    public function testTheInvokingAndInvokedEventsShareAToolInvocationId(): void
    {
        $ids = [];

        $events = new Dispatcher;
        $events->listen(InvokingTool::class, function (InvokingTool $event) use (&$ids): void {
            $ids[] = $event->toolInvocationId;
        });
        $events->listen(ToolInvoked::class, function (ToolInvoked $event) use (&$ids): void {
            $ids[] = $event->toolInvocationId;
        });

        toolInvokingGateway()->invoke(stubTool('paired', fn (): string => 'result'), context: stubRunContext($events));

        $this->assertCount(2, $ids);
        $this->assertSame($ids[1], $ids[0]);
    }

    public function testOnlyTheToolHandlerItselfCanFailAToolInvocation(): void
    {
        $invoked = 0;
        $failed = 0;

        $events = new Dispatcher;
        $events->listen(ToolInvoked::class, function () use (&$invoked): void {
            ++$invoked;
        });
        $events->listen(ToolFailed::class, function () use (&$failed): void {
            ++$failed;
        });

        $exception = new RuntimeException('Stringify exploded.');
        $tool = stubTool('unstringable', fn (): Stringable => new class($exception) implements Stringable {
            /**
             * Store the conversion failure.
             */
            public function __construct(private RuntimeException $exception)
            {
            }

            /**
             * Fail while converting the result to text.
             */
            public function __toString(): string
            {
                throw $this->exception;
            }
        });

        try {
            toolInvokingGateway()->invoke($tool, context: stubRunContext($events));
            $this->fail('Expected result conversion to fail.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        // The handler succeeded, so the invocation is reported once and never as a failure...
        $this->assertSame(1, $invoked);
        $this->assertSame(0, $failed);
    }

    public function testAListenerThatThrowsIsNotReportedAsAToolFailure(): void
    {
        $failed = 0;

        $events = new Dispatcher;
        $exception = new RuntimeException('Listener exploded.');
        $events->listen(ToolInvoked::class, function () use ($exception): void {
            throw $exception;
        });
        $events->listen(ToolFailed::class, function () use (&$failed): void {
            ++$failed;
        });

        $tool = stubTool('fine', fn (): string => 'result');

        try {
            toolInvokingGateway()->invoke($tool, context: stubRunContext($events));
            $this->fail('Expected the listener to fail.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(0, $failed);
    }

    public function testAFailingToolHandlerIsReportedAsAToolFailureAndRethrown(): void
    {
        $failure = null;

        $events = new Dispatcher;
        $events->listen(ToolFailed::class, function (ToolFailed $event) use (&$failure): void {
            $failure = $event;
        });

        $exception = new RuntimeException('Handler exploded.');
        $tool = stubTool('exploding', function () use ($exception): string {
            throw $exception;
        });

        try {
            toolInvokingGateway()->invoke($tool, ['a' => 1], stubRunContext($events));
            $this->fail('Expected the tool handler to fail.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertInstanceOf(ToolFailed::class, $failure);
        $this->assertSame('inv_1', $failure->invocationId);
        $this->assertSame(['a' => 1], $failure->arguments);
        $this->assertSame($exception, $failure->exception);
    }

    public function testToolEventsCarryTheWallTimeSpentInTheHandler(): void
    {
        $invoked = null;

        $events = new Dispatcher;
        $events->listen(ToolInvoked::class, function (ToolInvoked $event) use (&$invoked): void {
            $invoked = $event;
        });

        $tool = stubTool('slow', function (): string {
            usleep(2000);

            return 'result';
        });

        toolInvokingGateway()->invoke($tool, context: stubRunContext($events));

        $this->assertIsFloat($invoked->time);
        $this->assertGreaterThan(1.0, $invoked->time);
    }

    public function testAToolInvocationWithoutARunContextIsSilent(): void
    {
        $gateway = new class {
            use InvokesTools;

            /**
             * Invoke a tool without a run context.
             */
            public function invoke(Tool $tool): string
            {
                return $this->executeTool($tool, []);
            }
        };

        $this->assertSame('result', $gateway->invoke(stubTool('unobserved', fn (): string => 'result')));
    }
}
