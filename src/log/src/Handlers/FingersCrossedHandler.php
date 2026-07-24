<?php

declare(strict_types=1);

namespace Hypervel\Log\Handlers;

use Closure;
use Hypervel\Context\CoroutineContext;
use Monolog\Handler\FingersCrossed\ActivationStrategyInterface;
use Monolog\Handler\FingersCrossedHandler as MonologFingersCrossedHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\ResettableInterface;
use Override;

class FingersCrossedHandler extends MonologFingersCrossedHandler
{
    /**
     * The next worker-unique handler identifier.
     *
     * This identity must never reset while old coroutine-context entries may
     * survive, otherwise a new handler can inherit another handler's buffer.
     */
    private static int $nextHandlerId = 0;

    private readonly string $stateKey;

    public function __construct(
        Closure|HandlerInterface $handler,
        int|string|Level|ActivationStrategyInterface|null $activationStrategy = null,
        int $bufferSize = 0,
        bool $bubble = true,
        bool $stopBuffering = true,
        int|string|Level|null $passthruLevel = null
    ) {
        parent::__construct(
            $handler,
            $activationStrategy,
            $bufferSize,
            $bubble,
            $stopBuffering,
            $passthruLevel
        );

        $this->stateKey = '__log.fingers_crossed.' . ++self::$nextHandlerId;
    }

    #[Override]
    public function activate(): void
    {
        $state = $this->state();

        if ($this->stopBuffering) {
            $state->buffering = false;
        }

        // Move the current batch before invoking user handlers. Re-entrant
        // records must remain in the new buffer when buffering is resumed.
        $buffer = $state->buffer;
        $state->buffer = [];

        $this->getHandler(end($buffer) ?: null)->handleBatch($buffer);
    }

    #[Override]
    public function handle(LogRecord $record): bool
    {
        if (count($this->processors) > 0) {
            $record = $this->processRecord($record);
        }

        $state = $this->state();

        if ($state->buffering) {
            $state->buffer[] = $record;

            if ($this->bufferSize > 0 && count($state->buffer) > $this->bufferSize) {
                array_shift($state->buffer);
            }

            if ($this->activationStrategy->isHandlerActivated($record)) {
                $this->activate();
            }
        } else {
            $this->getHandler($record)->handle($record);
        }

        return $this->bubble === false;
    }

    #[Override]
    public function close(): void
    {
        $this->flushBuffer();
        $this->getHandler()->close();
    }

    #[Override]
    public function reset(): void
    {
        $this->flushBuffer();
        $this->resetProcessors();

        if ($this->getHandler() instanceof ResettableInterface) {
            $this->getHandler()->reset();
        }
    }

    #[Override]
    public function clear(): void
    {
        $state = $this->state();
        $state->buffer = [];
        $state->buffering = true;

        $this->resetProcessors();

        if ($this->getHandler() instanceof ResettableInterface) {
            $this->getHandler()->reset();
        }
    }

    private function state(): FingersCrossedState
    {
        return CoroutineContext::getOrSet(
            $this->stateKey,
            static fn () => new FingersCrossedState
        );
    }

    private function flushBuffer(): void
    {
        $state = $this->state();
        $buffer = $state->buffer;
        $state->buffer = [];
        $state->buffering = true;

        if ($this->passthruLevel !== null) {
            $passthruLevel = $this->passthruLevel;
            $buffer = array_values(array_filter(
                $buffer,
                static fn (LogRecord $record): bool => $passthruLevel->includes($record->level)
            ));

            if ($buffer !== []) {
                $this->getHandler(end($buffer))->handleBatch($buffer);
            }
        }
    }
}
