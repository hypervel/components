<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Support;

use RuntimeException;

class Logger
{
    /**
     * How long a write may wait for the renderer to accept output.
     *
     * This must remain well above the public render interval.
     */
    public const float DEFAULT_WRITE_TIMEOUT_SECONDS = 10.0;

    /**
     * The first transport failure encountered while writing.
     */
    protected ?RuntimeException $transportFailure = null;

    /**
     * The identifier retained for Laravel-compatible construction and subclass state.
     */
    protected string $identifier;

    /**
     * Create a new Logger instance.
     *
     * @param null|resource $socket
     * @param float $writeTimeout seconds a write may wait for the renderer to accept output
     */
    public function __construct(
        string $identifier,
        protected $socket = null,
        protected float $writeTimeout = self::DEFAULT_WRITE_TIMEOUT_SECONDS,
    ) {
        $this->identifier = $identifier;
    }

    /**
     * Log a line to the process log.
     */
    public function line(string $message): void
    {
        $this->write(rtrim($message));
    }

    /**
     * Append a chunk of text to the current partial output.
     */
    public function partial(string $chunk): void
    {
        $this->write($chunk, 'partial');
    }

    /**
     * Commit the accumulated partial text and start fresh.
     */
    public function commitPartial(): void
    {
        $this->write('', 'commitpartial');
    }

    /**
     * Log a success message to the process log.
     */
    public function success(string $message): void
    {
        $this->write($message, 'success');
    }

    /**
     * Log a warning message to the process log.
     */
    public function warning(string $message): void
    {
        $this->write($message, 'warning');
    }

    /**
     * Log an error message to the process log.
     */
    public function error(string $message): void
    {
        $this->write($message, 'error');
    }

    /**
     * Update the label of the process log.
     */
    public function label(string $message): void
    {
        $this->write($message, 'label');
    }

    /**
     * Update the sub-label of the process log. Pass an empty string to clear.
     */
    public function subLabel(string $message): void
    {
        $this->write($message, 'sublabel');
    }

    /**
     * Write a message to the socket.
     */
    protected function write(string $message, ?string $type = null): void
    {
        $frame = TaskFrame::encode($type, $message);

        if ($this->socket === null) {
            return;
        }

        try {
            Utils::writeAll($this->socket, $frame, $this->writeTimeout);
        } catch (RuntimeException $exception) {
            $this->transportFailure ??= $exception;
            $this->socket = null;
        }
    }

    // REMOVED: Binary framing cannot represent text prefixes. Override write() instead.

    /**
     * Get the first transport failure encountered while writing.
     */
    public function transportFailure(): ?RuntimeException
    {
        return $this->transportFailure;
    }
}
