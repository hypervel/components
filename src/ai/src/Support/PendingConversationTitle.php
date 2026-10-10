<?php

declare(strict_types=1);

namespace Hypervel\Ai\Support;

use Closure;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\Gateway\ParentInvocation;
use Hypervel\Coroutine\WaitConcurrent;
use Throwable;

class PendingConversationTitle
{
    protected ?WaitConcurrent $concurrent;

    protected string $title;

    protected ?Throwable $failure = null;

    /**
     * Start the title request in its captured operation context.
     *
     * @param Closure(): string $generate
     * @param null|Closure(Closure): mixed $runner
     */
    public function __construct(Closure $generate, ?Closure $runner)
    {
        $this->concurrent = new WaitConcurrent(1);

        $this->concurrent->fork(function () use ($generate, $runner): void {
            try {
                $this->title = $runner === null ? $generate() : $runner($generate);
            } catch (Throwable $exception) {
                $this->failure = $exception;
            }
        }, [ParentInvocation::PARENT_INVOCATION_CONTEXT_KEY, AiManager::ON_DEMAND_PROVIDERS_CONTEXT_KEY]);
    }

    /**
     * Wait for the title and return the same result on subsequent reads.
     */
    public function value(): string
    {
        $this->concurrent?->wait();
        $this->concurrent = null;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->title;
    }

    /**
     * Cancel and join any title request that is still running.
     */
    public function cancel(): void
    {
        $this->concurrent?->cancel();
        $this->concurrent?->wait();
        $this->concurrent = null;
    }
}
