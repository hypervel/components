<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Events\ToolFailed;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Gateway\Concerns\InvokesTools;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Events\Dispatcher;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Validation\ValidationException;
use Mockery as m;

class ToolRequestValidationTest extends TestCase
{
    public function testToolRequestArgumentsCanBeValidated(): void
    {
        $validated = (new Request(['city' => 'Lisbon', 'extra' => 'ignored']))->validate([
            'city' => 'required|string',
        ]);

        $this->assertSame(['city' => 'Lisbon'], $validated);
    }

    public function testInvalidToolRequestArgumentsThrowAValidationException(): void
    {
        $this->expectException(ValidationException::class);

        (new Request(['days' => 'tomorrow']))->validate(['days' => 'required|integer']);
    }

    public function testAValidationFailureIsReturnedToTheModelAsTheToolResult(): void
    {
        $invoked = null;
        $failed = 0;

        $events = new Dispatcher;
        $events->listen(ToolInvoked::class, function (ToolInvoked $event) use (&$invoked): void {
            $invoked = $event;
        });
        $events->listen(ToolFailed::class, function () use (&$failed): void {
            ++$failed;
        });

        $gateway = new class {
            use InvokesTools;

            /**
             * Invoke a tool through the gateway's execution path.
             */
            public function invoke(Tool $tool, array $arguments, RunContext $context): string
            {
                return $this->executeTool($tool, $arguments, null, $context);
            }
        };

        $tool = new class implements Tool {
            /**
             * Get the tool description.
             */
            public function description(): string
            {
                return 'Validating tool.';
            }

            /**
             * Validate and handle the tool request.
             */
            public function handle(Request $request): string
            {
                $request->validate(['city' => 'required|string']);

                return 'never reached';
            }

            /**
             * Get the tool's input schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $context = new RunContext(
            'inv_1',
            m::mock(Agent::class),
            m::mock(TextProvider::class),
            'stub-model',
            $events,
        );

        $result = $gateway->invoke($tool, [], $context);

        $this->assertSame('The city field is required.', $result);
        $this->assertSame('The city field is required.', $invoked->result);
        $this->assertSame(0, $failed);
    }
}
