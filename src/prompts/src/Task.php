<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Prompts\Support\InProcessLogger;
use Hypervel\Prompts\Support\Logger;
use Hypervel\Prompts\Support\PromptAnimation;
use Hypervel\Prompts\Support\TaskFrame;
use Hypervel\Prompts\Support\Utils;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Task extends Prompt
{
    use Concerns\TracksTaskOutput;

    /**
     * How long a Logger write may make no progress.
     *
     * This must remain well above the public render interval.
     */
    protected const float LOGGER_WRITE_TIMEOUT_SECONDS = Logger::DEFAULT_WRITE_TIMEOUT_SECONDS;

    /**
     * Scheduling margin added to one complete renderer frame interval.
     *
     * Reset can arrive while the renderer is sleeping between frames.
     */
    protected const int RENDERER_SETTLEMENT_MARGIN_MILLISECONDS = 1000;

    /**
     * The raw reverse-channel acknowledgement byte.
     *
     * Parent-to-renderer messages use TaskFrame; this single byte travels in
     * the opposite direction only after the renderer has settled completely.
     */
    protected const string RENDERER_ACKNOWLEDGEMENT = "\x06";

    /**
     * The minimum width for the longest line calculation.
     */
    protected int $minWidth = 0;

    /**
     * How long to wait between rendering each frame.
     */
    public int $interval = 100;

    /**
     * The number of times the task has been rendered.
     */
    public int $count = 0;

    /**
     * Whether the task can only be rendered once.
     */
    public bool $static = false;

    /**
     * The process ID after forking.
     */
    protected ?int $pid = null;

    /**
     * The socket for IPC communication.
     *
     * @var null|resource
     */
    protected $socket;

    /**
     * The signal handler replaced by the process renderer.
     */
    protected mixed $originalSignalHandler = null;

    /**
     * The async-signal mode replaced by the process renderer.
     */
    protected ?bool $originalAsync = null;

    /**
     * Pre-wrapped log lines for the scrolling output area.
     *
     * @var array<int, string>
     */
    public array $logs = [];

    /**
     * Stable status messages (success, warning, error).
     *
     * @var list<array{type: string, message: string}>
     */
    public array $stableMessages = [];

    /**
     * The maximum number of stable messages to display.
     */
    public int $maxStableMessages = 10;

    /**
     * The identifier retained for Laravel-compatible Task and Logger extension state.
     */
    public string $identifier = '';

    /**
     * Whether the task has finished.
     */
    public bool $finished = false;

    /**
     * The incremental process-message decoder.
     */
    private TaskFrame $frameDecoder;

    /**
     * Create a new Task instance.
     */
    public function __construct(
        public string $label = '',
        public int $limit = 10,
        public bool $keepSummary = false,
        public ?string $subLabel = null,
    ) {
        $this->validateLimit();

        $this->identifier = uniqid();
        $this->frameDecoder = new TaskFrame;
    }

    /**
     * Render the task and execute the callback.
     *
     * @template TReturn of mixed
     *
     * @param Closure(Logger): TReturn $callback
     * @return TReturn
     */
    public function run(Closure $callback): mixed
    {
        $this->validateLimit();
        $this->resetOperationState();

        $configuredLimit = $this->limit;
        $this->limit = min($this->limit, max(0, $this->terminal()->lines() - 10));

        try {
            $this->recalculateMaxStableMessages();

            $this->capturePreviousNewLines();

            if (! static::output()->isDecorated()) {
                return $this->renderStatically($callback);
            }

            if (Coroutine::inCoroutine()) {
                return $this->renderInCoroutine($callback);
            }

            if (! (function_exists('pcntl_fork') && function_exists('posix_kill'))) {
                return $this->renderStatically($callback);
            }

            return $this->renderInProcess($callback);
        } finally {
            $this->limit = $configuredLimit;
        }
    }

    /**
     * Validate the configured task log limit.
     */
    private function validateLimit(): void
    {
        if ($this->limit < 0) {
            throw new InvalidArgumentException('The task log limit must be zero or greater.');
        }
    }

    /**
     * Receive and process messages from the parent process.
     *
     * @param resource $socket
     */
    protected function receiveMessages($socket): void
    {
        while (($data = fread($socket, 65536)) !== false && $data !== '') {
            $this->frameDecoder->append($data);

            while (($message = $this->frameDecoder->next()) !== null) {
                $this->applyMessage($message['type'], $message['payload']);

                if ($this->finished) {
                    return;
                }
            }
        }
    }

    /**
     * Apply one decoded Task message.
     *
     * @internal
     */
    public function applyMessage(?string $type, string $payload): void
    {
        if ($type === null) {
            $this->addLogLines($payload);

            return;
        }

        if ($type === 'reset') {
            if ($payload !== "\x00" && $payload !== "\x01") {
                throw new RuntimeException('The prompt renderer received an invalid settlement message.');
            }

            $this->resetTerminal($this->originalAsync ?? false, $payload === "\x01");

            return;
        }

        if ($type === 'partial') {
            $this->consumePartialOutput($payload);

            return;
        }

        if ($type === 'commitpartial') {
            $this->commitPartialOutput();

            return;
        }

        if ($type === 'label') {
            $this->label = $payload;

            return;
        }

        if ($type === 'sublabel') {
            $this->subLabel = $payload;
            $this->recalculateMaxStableMessages();
            $this->trimStableMessages();

            return;
        }

        if (! in_array($type, ['success', 'warning', 'error'], true)) {
            throw new InvalidArgumentException("Unknown task message type [{$type}].");
        }

        $this->stableMessages[] = ['type' => $type, 'message' => $payload];
        $this->logs = [];
        $this->resetTaskOutputTracking();

        $this->trimStableMessages();
    }

    /**
     * Recompute the stable-message budget based on the current sub-label state.
     */
    protected function recalculateMaxStableMessages(): void
    {
        $reserved = 2 + ($this->subLabel !== null && $this->subLabel !== '' ? 1 : 0);

        $this->maxStableMessages = max(0, $this->terminal()->lines() - 10 - $this->limit - $reserved);
    }

    /**
     * Trim stable messages to the current display budget.
     */
    protected function trimStableMessages(): void
    {
        while (count($this->stableMessages) > $this->maxStableMessages) {
            array_shift($this->stableMessages);
        }
    }

    /**
     * Reset state owned by one task operation.
     */
    protected function resetOperationState(): void
    {
        $this->count = 0;
        $this->static = false;
        $this->finished = false;
        $this->logs = [];
        $this->stableMessages = [];
        $this->frameDecoder->reset();
        $this->resetTaskOutputTracking();
        $this->state = 'initial';
        $this->prevFrame = '';
    }

    /**
     * Determine whether the final task summary should remain visible.
     */
    protected function shouldKeepSummary(): bool
    {
        return $this->keepSummary && count($this->stableMessages) > 0;
    }

    /**
     * Finish rendering the task and clear it unless the summary should remain.
     */
    protected function finishRendering(bool $renderFinalFrame = false, bool $success = true): void
    {
        $this->finished = true;

        if ($renderFinalFrame || $this->shouldKeepSummary()) {
            $this->render();
        }

        if ($this->shouldKeepSummary()) {
            return;
        }

        $this->eraseRenderedLines();

        if ($this->keepSummary && $success && $this->stableMessages === []) {
            $this->printCompletionLine();
        }
    }

    /**
     * Reset the terminal after process rendering.
     */
    protected function resetTerminal(bool $originalAsync, bool $success = true): void
    {
        $this->finished = true;

        pcntl_async_signals($originalAsync);
        pcntl_signal(SIGINT, SIG_DFL);

        $this->closeSocket();
        $this->finishRendering(success: $success);
    }

    /**
     * Print a single-line completion indicator after the task has finished.
     */
    protected function printCompletionLine(): void
    {
        $symbol = static::output()->isDecorated() ? $this->green('✔') : '✔';

        static::output()->writeln(" {$symbol} {$this->label}");
    }

    /**
     * Render the task using a child process for the animation loop.
     *
     * @template TReturn of mixed
     *
     * @param Closure(Logger): TReturn $callback
     * @return TReturn
     */
    protected function renderInProcess(Closure $callback): mixed
    {
        $sockets = $this->createSocketPair();

        if ($sockets === false) {
            return $this->renderStatically($callback);
        }

        $this->captureSignalState();

        try {
            $this->hideCursor();
            $this->render();
            $pid = $this->forkProcess();
        } catch (Throwable $exception) {
            fclose($sockets[0]);
            fclose($sockets[1]);

            try {
                $this->finishRendering(success: false);
            } catch (Throwable) {
                // The setup failure remains primary while cleanup continues.
            }

            $this->restoreProcessState();

            throw $exception;
        }

        if ($pid === -1) {
            fclose($sockets[0]);
            fclose($sockets[1]);
            $cleanupFailure = null;

            try {
                $this->finishRendering(success: false);
            } catch (Throwable $exception) {
                $cleanupFailure = $exception;
            }

            $cleanupFailure = $this->restoreProcessState($cleanupFailure);

            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }

            $this->resetOperationState();

            return $this->renderStatically($callback);
        }

        if ($pid === 0) {
            fclose($sockets[1]);
            $this->runRendererProcess($sockets[0]);
        }

        fclose($sockets[0]);
        $this->pid = $pid;
        $this->socket = $sockets[1];

        $rendererInterval = $this->interval;
        $logger = new Logger($this->identifier, $this->socket, static::LOGGER_WRITE_TIMEOUT_SECONDS);
        $result = null;
        $callbackFailure = null;
        $rendererFailure = null;
        $rendererAcknowledged = false;
        $success = false;

        try {
            $result = $callback($logger);
            $success = true;
        } catch (Throwable $exception) {
            $callbackFailure = $exception;
        }

        $rendererFailure = $logger->transportFailure();

        if ($rendererFailure === null) {
            try {
                Utils::writeAll(
                    $this->socket,
                    TaskFrame::encode('reset', $success ? "\x01" : "\x00"),
                    static::LOGGER_WRITE_TIMEOUT_SECONDS,
                );
                $settlementTimeout = $rendererInterval + static::RENDERER_SETTLEMENT_MARGIN_MILLISECONDS;
                stream_set_timeout(
                    $this->socket,
                    intdiv($settlementTimeout, 1000),
                    ($settlementTimeout % 1000) * 1000,
                );
                $rendererAcknowledged = $this->awaitRendererClosure();
            } catch (RuntimeException $exception) {
                $rendererFailure = $exception;
            }
        }

        $this->closeSocket();

        if ($rendererFailure !== null) {
            $this->terminateRenderer();
        }

        $reapFailure = $this->reapRenderer($rendererFailure === null, $rendererAcknowledged);
        $rendererFailure ??= $reapFailure;

        $rendererFailure = $this->restoreProcessState($rendererFailure);

        if ($callbackFailure !== null) {
            throw $callbackFailure;
        }

        if ($rendererFailure !== null) {
            throw $rendererFailure;
        }

        return $result;
    }

    /**
     * Create the process renderer socket pair.
     *
     * Subclasses and tests override this seam to exercise socket setup failures
     * without replacing the process-rendering lifecycle.
     *
     * @return array{resource, resource}|false
     */
    protected function createSocketPair(): array|false
    {
        return stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    }

    /**
     * Fork the process renderer.
     *
     * Subclasses and tests override this seam to exercise fork and reaping
     * failures without injecting a process abstraction.
     */
    protected function forkProcess(): int
    {
        return pcntl_fork();
    }

    /**
     * Run the child process renderer.
     *
     * When the caller ignores SIGCHLD or sets SA_NOCLDWAIT, the parent cannot
     * obtain an exit status. Subclasses that complete settlement must write
     * static::RENDERER_ACKNOWLEDGEMENT before closing their socket or the
     * renderer will be reported as failed in that configuration.
     *
     * @param resource $socket
     */
    protected function runRendererProcess($socket): never
    {
        $exitCode = 0;

        try {
            stream_set_blocking($socket, false);

            while (true) {
                $this->receiveMessages($socket);

                if ($this->finished) {
                    break;
                }

                if (feof($socket)) {
                    $this->frameDecoder->finish();
                    $this->resetTerminal($this->originalAsync ?? false, false);

                    break;
                }

                $this->render();
                ++$this->count;
                usleep($this->interval * 1000);
            }

            Utils::writeAll($socket, static::RENDERER_ACKNOWLEDGEMENT, static::LOGGER_WRITE_TIMEOUT_SECONDS);
        } catch (Throwable) {
            $exitCode = 1;
        }

        $this->terminalStateRestored = true;
        $this->originalSignalHandler = null;
        $this->originalAsync = null;
        fclose($socket);

        exit($exitCode);
    }

    /**
     * Capture and replace the process renderer's signal state.
     */
    protected function captureSignalState(): void
    {
        $this->originalSignalHandler = pcntl_signal_get_handler(SIGINT);
        $this->originalAsync = pcntl_async_signals(true);

        pcntl_signal(SIGINT, static function (): void {
            exit;
        });
    }

    /**
     * Restore signal state replaced by the process renderer.
     */
    protected function restoreSignalState(): void
    {
        if ($this->originalAsync === null) {
            return;
        }

        /** @var callable|int $handler */
        $handler = $this->originalSignalHandler;

        pcntl_signal(SIGINT, $handler);
        pcntl_async_signals($this->originalAsync);

        $this->originalSignalHandler = null;
        $this->originalAsync = null;
    }

    /**
     * Restore process-owned signal and terminal state.
     */
    private function restoreProcessState(?Throwable $failure = null): ?Throwable
    {
        try {
            $this->restoreSignalState();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        try {
            $this->restoreTerminalState();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        return $failure;
    }

    /**
     * Wait for the child renderer to close its socket.
     */
    protected function awaitRendererClosure(): bool
    {
        $acknowledged = false;

        while (true) {
            $data = @fread($this->socket, 1);
            $metadata = stream_get_meta_data($this->socket);

            if ($metadata['timed_out']) {
                throw new RuntimeException('The prompt renderer timed out while settling.');
            }

            if ($data === '' && $metadata['eof']) {
                return $acknowledged;
            }

            if ($data === false || $data === '') {
                throw new RuntimeException('Unable to confirm that the prompt renderer settled.');
            }

            $acknowledged = $acknowledged || $data === static::RENDERER_ACKNOWLEDGEMENT;
        }
    }

    /**
     * Close the parent process socket.
     */
    protected function closeSocket(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Terminate the owned child renderer.
     */
    protected function terminateRenderer(): void
    {
        if ($this->pid !== null && $this->pid > 0) {
            @posix_kill($this->pid, SIGHUP);
        }
    }

    /**
     * Reap the owned child renderer.
     */
    protected function reapRenderer(bool $requireSuccess, bool $acknowledged = false): ?RuntimeException
    {
        if ($this->pid === null || $this->pid <= 0) {
            return null;
        }

        $pid = $this->pid;
        $status = 0;

        do {
            $reaped = pcntl_waitpid($pid, $status);
            $error = $reaped === -1 ? pcntl_get_last_error() : null;
        } while ($reaped === -1 && $error === PCNTL_EINTR);

        $this->pid = null;

        if ($reaped === -1 && $error === PCNTL_ECHILD) {
            // Automatic reaping discards the exit status, so only the child's acknowledgement can prove success.
            return $requireSuccess && ! $acknowledged
                ? new RuntimeException('The prompt renderer process failed.')
                : null;
        }

        if ($reaped !== $pid) {
            return new RuntimeException('Unable to reap the prompt renderer process.');
        }

        if (! $requireSuccess) {
            return null;
        }

        if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
            return null;
        }

        return new RuntimeException(
            pcntl_wifsignaled($status)
                ? 'The prompt renderer process was terminated by a signal.'
                : 'The prompt renderer process failed.',
        );
    }

    /**
     * Render a static version of the task.
     *
     * @template TReturn of mixed
     *
     * @param Closure(Logger): TReturn $callback
     * @return TReturn
     */
    protected function renderStatically(Closure $callback): mixed
    {
        $this->static = true;
        $result = null;
        $operationFailure = null;
        $success = false;

        try {
            $this->hideCursor();
            $this->render();

            $logger = new InProcessLogger($this);
            $result = $callback($logger);
            $success = true;
        } catch (Throwable $exception) {
            $operationFailure = $exception;
        }

        $cleanupFailure = $this->settleOperation(success: $success);

        if ($operationFailure !== null) {
            throw $operationFailure;
        }

        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }

        return $result;
    }

    /**
     * Render the task using a coroutine for the animation loop.
     *
     * Uses the same in-process Task instance instead of socket IPC.
     *
     * @template TReturn of mixed
     *
     * @param Closure(Logger): TReturn $callback
     * @return TReturn
     */
    protected function renderInCoroutine(Closure $callback): mixed
    {
        $animation = null;
        $result = null;
        $operationFailure = null;
        $success = false;

        try {
            $this->hideCursor();
            $this->render();

            $animation = new PromptAnimation(
                render: function (): void {
                    ++$this->count;
                    $this->render();
                },
                interval: $this->interval,
            );
            $animation->start();

            $logger = new InProcessLogger($this);

            $result = $callback($logger);
            $success = true;
        } catch (Throwable $exception) {
            $operationFailure = $exception;
        }

        $animationFailure = null;

        try {
            $animationFailure = $animation?->stop();
        } catch (Throwable $exception) {
            $animationFailure = $exception;
        }

        $cleanupFailure = $this->settleOperation(renderFinalFrame: true, success: $success);

        if ($operationFailure !== null) {
            throw $operationFailure;
        }

        if ($animationFailure !== null) {
            throw $animationFailure;
        }

        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }

        return $result;
    }

    /**
     * Finish rendering and restore terminal state for one Task operation.
     */
    private function settleOperation(bool $renderFinalFrame = false, bool $success = true): ?Throwable
    {
        $failure = null;

        try {
            $this->finishRendering($renderFinalFrame, $success);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            $this->restoreTerminalState();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        return $failure;
    }

    /**
     * Disable prompting for input.
     *
     * @throws RuntimeException
     */
    public function prompt(): never
    {
        throw new RuntimeException('Task cannot be prompted.');
    }

    /**
     * Get the current value of the prompt.
     */
    public function value(): bool
    {
        return true;
    }

    /**
     * Clear the lines rendered by the task.
     */
    protected function eraseRenderedLines(): void
    {
        $lines = explode(PHP_EOL, $this->prevFrame);
        $this->moveCursor(-999, -count($lines) + 1);
        $this->eraseDown();
    }

    /**
     * Clean up after the task.
     */
    public function __destruct()
    {
        try {
            $this->closeSocket();
            $this->terminateRenderer();
            $this->reapRenderer(requireSuccess: false);
            $this->restoreSignalState();
        } catch (Throwable) {
            // Destructors are a best-effort fallback for an interrupted operation.
        } finally {
            parent::__destruct();
        }
    }
}
