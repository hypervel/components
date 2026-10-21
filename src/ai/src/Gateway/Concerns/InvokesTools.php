<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Concerns;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Gateway\ParentInvocation;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Ai\Tools\Request;
use Hypervel\Ai\Tools\ToolNameResolver;
use Hypervel\Support\Str;
use Hypervel\Validation\ValidationException;
use Throwable;

trait InvokesTools
{
    use MeasuresDuration;

    /**
     * Execute the given tool with the given arguments.
     *
     * @param array<string, mixed> $arguments
     */
    protected function executeTool(Tool $tool, array $arguments, ?string $toolCallId = null, ?RunContext $context = null): string
    {
        $toolInvocationId = (string) Str::uuid7();

        // Any agent prompted while this tool runs, however it was reached, is a child of this tool call...
        return ParentInvocation::within($context?->invocationId, $toolInvocationId, function () use ($tool, $arguments, $toolCallId, $toolInvocationId, $context): string {
            $context?->invokingTool($tool, $arguments, $toolInvocationId);

            $startedAt = hrtime(true);

            // Only the handler itself may fail the tool call, so a listener that throws is never reported as a tool failure...
            try {
                $result = $tool->handle(new Request($arguments, $toolCallId, $toolInvocationId));
            } catch (ValidationException $exception) {
                // Validation failures are returned to the model so it can correct the arguments and retry...
                $result = implode(' ', $exception->validator->errors()->all()) ?: $exception->getMessage();
            } catch (Throwable $exception) {
                $context?->toolFailed($tool, $arguments, $exception, $toolInvocationId, $this->elapsedMilliseconds($startedAt));

                throw $exception;
            }

            $context?->toolInvoked($tool, $arguments, $result, $toolInvocationId, $this->elapsedMilliseconds($startedAt));

            return (string) $result;
        });
    }

    /**
     * Find a tool by its name from the given tools array.
     */
    protected function findTool(string $name, array $tools): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolSearch) {
                if ($nested = $this->findTool($name, $tool->tools)) {
                    return $nested;
                }

                continue;
            }

            if ($tool instanceof Tool && ToolNameResolver::resolve($tool) === $name) {
                return $tool;
            }
        }

        return null;
    }
}
