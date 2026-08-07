<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Prompts\Support\InProcessLogger;
use Hypervel\Prompts\Support\Logger;
use Hypervel\Prompts\Support\Utils;
use Hypervel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Task extends Prompt
{
    use InteractsWithStrings;

    /**
     * How long a Logger write may make no progress.
     *
     * This must remain well above the public render interval.
     */
    protected const LOGGER_WRITE_TIMEOUT_SECONDS = 10;

    /**
     * How long the renderer may take to settle after reset.
     */
    protected const RENDERER_SETTLEMENT_TIMEOUT_SECONDS = 1;

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
     * The identifier for the task.
     */
    public string $identifier = '';

    /**
     * Whether the task has finished.
     */
    public bool $finished = false;

    /**
     * Buffer for incomplete lines from non-blocking socket reads.
     */
    protected string $buffer = '';

    /**
     * The log index where the current partial started, or null if not streaming.
     */
    protected ?int $partialStartIndex = null;

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
        $prefix = preg_quote($this->identifier, '/');

        while (($data = fgets($socket)) !== false) {
            // Buffer incomplete lines from non-blocking reads.
            if (! str_ends_with($data, PHP_EOL)) {
                $this->buffer .= $data;

                continue;
            }

            $line = rtrim($this->buffer . $data, PHP_EOL);
            $this->buffer = '';

            if ($line === '') {
                continue;
            }

            // Check for typed messages: {id}_{type}:{content}
            if (preg_match('/^' . $prefix . '_(success|warning|error|label|sublabel|reset|partial|commitpartial):(.*)/', $line, $matches)) {
                $type = $matches[1];
                $content = $matches[2];

                if ($type === 'reset') {
                    $this->resetTerminal($this->originalAsync ?? false, $content === '1');

                    return;
                }

                if ($type === 'partial') {
                    $this->replacePartialLines($content);

                    continue;
                }

                if ($type === 'commitpartial') {
                    $this->partialStartIndex = null;

                    continue;
                }

                if ($type === 'label') {
                    $this->label = $content;
                } elseif ($type === 'sublabel') {
                    $this->subLabel = $content;
                    $this->recalculateMaxStableMessages();
                } else {
                    $this->stableMessages[] = ['type' => $type, 'message' => $content];
                    $this->logs = [];
                    $this->partialStartIndex = null;
                }

                $this->trimStableMessages();

                continue;
            }

            // Regular log line — strip cursor-reset control sequences.
            $line = preg_replace('/\e\[(?:1)?G\e\[2K/', '', $line);

            // Wrap and add to ring buffer.
            $this->addLogLines($line);
        }
    }

    /**
     * Wrap a log line and append to the ring buffer, trimming to the limit.
     */
    protected function addLogLines(string $line): void
    {
        $width = $this->terminal()->cols() - 10;
        $plainText = $this->stripEscapeSequences($line);

        if (mb_strwidth($plainText) > $width) {
            $wrapped = $this->ansiWordwrap($line, $width);
        } else {
            $wrapped = [$line];
        }

        array_push($this->logs, ...$wrapped);

        while (count($this->logs) > $this->limit) {
            array_shift($this->logs);
        }
    }

    /**
     * Replace the in-progress partial lines with the full accumulated text.
     */
    protected function replacePartialLines(string $text): void
    {
        if ($this->partialStartIndex === null) {
            $this->partialStartIndex = count($this->logs);
        }

        // Truncate back to where the partial started.
        $this->logs = array_slice($this->logs, 0, $this->partialStartIndex);

        // Wrap and append the full accumulated partial text.
        $width = $this->terminal()->cols() - 10;
        $plainText = $this->stripEscapeSequences($text);

        if (mb_strwidth($plainText) > $width) {
            $wrapped = $this->ansiWordwrap($text, $width);
        } else {
            $wrapped = [$text];
        }

        array_push($this->logs, ...$wrapped);

        while (count($this->logs) > $this->limit) {
            array_shift($this->logs);
            $this->partialStartIndex = max(0, $this->partialStartIndex - 1);
        }
    }

    /**
     * Append a log line to the scrolling output area.
     */
    public function appendLogLine(string $line): void
    {
        // Strip cursor-reset control sequences.
        $line = preg_replace('/\e\[(?:1)?G\e\[2K/', '', $line);

        $this->addLogLines($line);
    }

    /**
     * Add a stable status message (success, warning, error).
     */
    public function addStableMessage(string $type, string $message): void
    {
        $this->stableMessages[] = ['type' => $type, 'message' => $message];
        $this->logs = [];
        $this->partialStartIndex = null;

        $this->trimStableMessages();
    }

    /**
     * Update the task label.
     */
    public function updateLabel(string $label): void
    {
        $this->label = $label;
    }

    /**
     * Update the task sub-label.
     */
    public function updateSubLabel(string $subLabel): void
    {
        $this->subLabel = $subLabel;
        $this->recalculateMaxStableMessages();
        $this->trimStableMessages();
    }

    /**
     * Replace the in-progress partial text with the full accumulated text.
     */
    public function replacePartialText(string $text): void
    {
        $this->replacePartialLines($text);
    }

    /**
     * Commit the current partial text so it becomes permanent.
     */
    public function commitPartialText(): void
    {
        $this->partialStartIndex = null;
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
        $this->buffer = '';
        $this->partialStartIndex = null;
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
        stream_set_timeout($this->socket, static::LOGGER_WRITE_TIMEOUT_SECONDS);

        $logger = new Logger($this->identifier, $this->socket);
        $result = null;
        $callbackFailure = null;
        $rendererFailure = null;
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
                // A truncated newline-framed message makes a later reset unrecoverable.
                Utils::writeAll($this->socket, $this->identifier . '_reset:' . ($success ? '1' : '0') . PHP_EOL);
                stream_set_timeout($this->socket, static::RENDERER_SETTLEMENT_TIMEOUT_SECONDS);
                $this->awaitRendererClosure();
            } catch (RuntimeException $exception) {
                $rendererFailure = $exception;
            }
        }

        $this->closeSocket();

        if ($rendererFailure !== null) {
            $this->terminateRenderer();
        }

        $reapFailure = $this->reapRenderer($rendererFailure === null);
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
     * @return array{resource, resource}|false
     */
    protected function createSocketPair(): array|false
    {
        return stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    }

    /**
     * Fork the process renderer.
     */
    protected function forkProcess(): int
    {
        return pcntl_fork();
    }

    /**
     * Run the child process renderer.
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
                    $this->buffer = '';
                    $this->resetTerminal($this->originalAsync ?? false, false);

                    break;
                }

                $this->render();
                ++$this->count;
                usleep($this->interval * 1000);
            }
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
    protected function awaitRendererClosure(): void
    {
        while (true) {
            $data = @fread($this->socket, 1);
            $metadata = stream_get_meta_data($this->socket);

            if ($metadata['timed_out']) {
                throw new RuntimeException('The prompt renderer timed out while settling.');
            }

            if ($data === '' && $metadata['eof']) {
                return;
            }

            if ($data === false || $data === '') {
                throw new RuntimeException('Unable to confirm that the prompt renderer settled.');
            }
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
    protected function reapRenderer(bool $requireSuccess): ?RuntimeException
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
            // Ignored SIGCHLD lets the kernel reap the child without retaining its exit status.
            return null;
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
        /** @var bool $animationFinished */
        $animationFinished = false;
        $result = null;
        $operationFailure = null;
        $success = false;

        try {
            $this->hideCursor();
            $this->render();

            Coroutine::fork(function () use (&$animationFinished): void {
                while (! $animationFinished) {
                    $this->render();
                    ++$this->count;
                    usleep($this->interval * 1000);
                }
            });

            $logger = new InProcessLogger($this);

            $result = $callback($logger);
            $success = true;
        } catch (Throwable $exception) {
            $operationFailure = $exception;
        } finally {
            $animationFinished = true;
        }

        $cleanupFailure = $this->settleOperation(renderFinalFrame: true, success: $success);

        if ($operationFailure !== null) {
            throw $operationFailure;
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
