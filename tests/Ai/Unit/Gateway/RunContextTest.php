<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\StartingStep;
use Hypervel\Ai\Events\StepCompleted;
use Hypervel\Ai\Events\StepFailed;
use Hypervel\Ai\Events\ToolFailed;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class RunContextTest extends TestCase
{
    public function testStepAndToolEventsRequireInterestedListeners(): void
    {
        $events = new Dispatcher;
        $observed = [];
        $events->listen('*', function (string $name) use (&$observed): void {
            $observed[] = $name;
        });
        $context = new RunContext('invocation', m::mock(Agent::class), m::mock(TextProvider::class), 'model', $events);
        $step = new StepContext;
        $response = new StepResponse('Answer', [], FinishReason::Stop, new TextUsage, new Meta);
        $tool = m::mock(Tool::class);
        $exception = new RuntimeException('Failed');
        $report = static function () use ($context, $step, $response, $tool, $exception): void {
            $context->startingStep($step, [], null);
            $context->stepCompleted($step, $response, 1.0);
            $context->stepFailed($step, $exception, 1.0);
            $context->invokingTool($tool, [], 'tool');
            $context->toolInvoked($tool, [], 'result', 'tool', 1.0);
            $context->toolFailed($tool, [], $exception, 'tool', 1.0);
        };

        $report();
        $this->assertSame([], $observed);

        $events->listen(InvokingTool::class, static function (InvokingTool $event): void {});
        $report();
        $this->assertSame([InvokingTool::class], $observed);

        $observed = [];
        $events->listen('Hypervel\Ai\Events\*', static function (string $name): void {});
        $report();
        $this->assertSame([
            StartingStep::class,
            StepCompleted::class,
            StepFailed::class,
            InvokingTool::class,
            ToolInvoked::class,
            ToolFailed::class,
        ], $observed);
    }
}
