<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools;

use Generator;
use Hypervel\Ai\Approvals\Approval;
use Hypervel\Ai\Concerns\InteractsWithApprovals;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Approvable;
use Hypervel\Ai\Contracts\CanActAsTool;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Stringable;
use Swoole\Coroutine\CanceledException;
use Throwable;

class AgentTool implements Approvable, Tool, Transient
{
    use InteractsWithApprovals;

    /**
     * Create a tool that delegates work to the given agent.
     */
    public function __construct(protected Agent $agent)
    {
    }

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return $this->agent instanceof CanActAsTool
            ? $this->agent->name()
            : class_basename($this->agent);
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return $this->agent instanceof CanActAsTool
            ? $this->agent->description()
            : sprintf(
                'Delegates a task to the %s sub-agent and returns its response. Pass a clear, self-contained task description as the sub-agent runs in isolation and has no access to the parent conversation history.',
                $this->name(),
            );
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        try {
            return $this->agent->prompt((string) $request['task'])->text;
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            // Degraded to a result so the parent run survives; the failure surfaces as the sub-agent's own AgentFailed...
            return 'Agent failed: ' . $throwable->getMessage();
        }
    }

    /**
     * Execute the sub-agent and stream its activity.
     *
     * @return Generator<int, StreamEvent, mixed, string>
     */
    public function stream(Request $request): Generator
    {
        try {
            $stream = $this->agent->stream((string) $request['task']);

            yield from $stream;

            return (string) $stream->text;
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            return 'Agent failed: ' . $throwable->getMessage();
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task to delegate to this agent.')->required(),
        ];
    }

    /**
     * Get the underlying agent instance.
     */
    public function agent(): Agent
    {
        return $this->agent;
    }

    /**
     * Determine whether the tool needs approval for the given request.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
