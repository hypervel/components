<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Transport;

use Hypervel\Database\ConnectionResolver;
use Hypervel\Mcp\Client\Contracts\Transport;
use Hypervel\Mcp\Client\Exceptions\TimeoutException;
use Hypervel\Mcp\Client\Exceptions\TransportException;
use Hypervel\Process\InvokedProcess;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class StdioTransport implements Transport
{
    protected const int STDERR_LIMIT = 8192;

    protected ?Process $process = null;

    protected ?StdioInput $input = null;

    protected string $buffer = '';

    protected string $stderr = '';

    protected float $timeoutSeconds = 30.0;

    /**
     * Create a subprocess transport.
     *
     * @param array<int, string> $args
     * @param array<string, string> $env
     */
    public function __construct(protected string $command, protected array $args = [], protected array $env = [])
    {
    }

    /**
     * Start the subprocess if it is not already running.
     */
    public function connect(): void
    {
        if ($this->process?->isRunning()) {
            return;
        }

        $this->buffer = '';
        $this->stderr = '';
        $this->input = new StdioInput;
        $this->process = new Process([$this->command, ...$this->args], env: $this->env);
        $this->process->setInput($this->input);
        $this->process->setTimeout(null);

        try {
            $this->process->start();
        } catch (ExceptionInterface $exception) {
            throw new TransportException("Failed to start process [{$this->command}]. Make sure the command exists.", 0, $exception);
        }
    }

    /**
     * Detach the owned state and stop the subprocess.
     */
    public function disconnect(): void
    {
        $process = $this->process;
        $this->input?->close();
        $this->input = null;
        $this->process = null;
        $this->buffer = '';
        $this->stderr = '';

        if ($process !== null) {
            (new InvokedProcess($process))->stop(0.1);
        }
    }

    /**
     * Set the maximum idle time while awaiting a response.
     */
    public function setTimeoutSeconds(float $seconds): void
    {
        $this->timeoutSeconds = $seconds;
    }

    /**
     * Get the configuration needed to recreate this transport.
     *
     * @return array<string, mixed>
     */
    public function recipe(): array
    {
        return [
            'driver' => 'stdio',
            'command' => $this->command,
            'args' => $this->args,
            'env' => $this->env,
            'timeoutSeconds' => $this->timeoutSeconds,
        ];
    }

    /**
     * Write a newline-delimited protocol message.
     *
     * @param array<string, string> $headers
     */
    public function send(string $message, array $headers = []): void
    {
        if ($this->input === null || $this->process === null) {
            throw new TransportException('Transport is not connected.');
        }

        $this->input->write($message . "\n");

        // Service the pipes after enqueueing so notifications do not wait for receive().
        if (! $this->process->isRunning()) {
            throw new TransportException('Transport is not connected.');
        }
    }

    /**
     * Receive the next complete protocol message.
     */
    public function receive(): string
    {
        if (! $this->process instanceof Process) {
            throw new TransportException('Transport is not connected.');
        }

        return $this->popLine() ?? $this->readNextLine($this->process);
    }

    /**
     * Read retained and incoming output until a complete message is available.
     */
    protected function readNextLine(Process $process): string
    {
        $receiveStartedAt = microtime(true);
        $process->setIdleTimeout($this->timeoutSeconds + max(0.0, $receiveStartedAt - $process->getLastOutputTime()));
        ConnectionResolver::releaseIdleConnections();

        try {
            // Retained output must not count the time spent idle between receive calls.
            foreach ($process->getIterator() as $type => $chunk) {
                $process->setIdleTimeout($this->timeoutSeconds + max(0.0, $receiveStartedAt - $process->getLastOutputTime()));

                if ($type === Process::ERR) {
                    $this->stderr = substr($this->stderr . $chunk, -self::STDERR_LIMIT);
                }

                if ($this->bufferUntilNewline($type, $chunk)) {
                    return $this->popLine() ?? $this->failWith('Subprocess output stream did not yield a complete line.');
                }
            }
        } catch (ProcessTimedOutException) {
            $this->disconnectAfterFailure(new TimeoutException('Timed out while waiting for server response.'));
        }

        $stderr = trim($this->stderr);
        $suffix = $stderr === '' ? '' : " stderr: {$stderr}";

        return $this->popLine() ?? $this->failWith("Subprocess [{$this->command}] closed its output before sending a complete response.{$suffix}");
    }

    /**
     * Append standard output and detect a complete message.
     */
    protected function bufferUntilNewline(string $type, string $chunk): bool
    {
        if ($type !== Process::OUT) {
            return false;
        }

        $this->buffer .= $chunk;

        return str_contains($this->buffer, "\n");
    }

    /**
     * Remove the next complete line from the retained output.
     */
    protected function popLine(): ?string
    {
        $newlinePos = strpos($this->buffer, "\n");

        if ($newlinePos === false) {
            return null;
        }

        $line = substr($this->buffer, 0, $newlinePos + 1);
        $this->buffer = substr($this->buffer, $newlinePos + 1);

        return $line;
    }

    /**
     * Disconnect and report a transport failure.
     */
    protected function failWith(string $message): never
    {
        $this->disconnectAfterFailure(new TransportException($message));
    }

    /**
     * Release the child while preserving failure and cancellation precedence.
     */
    private function disconnectAfterFailure(Throwable $exception): never
    {
        try {
            $this->disconnect();
        } catch (Throwable $cleanupException) {
            if ($cleanupException instanceof CanceledException && ! $exception instanceof CanceledException) {
                throw $cleanupException;
            }
        }

        throw $exception;
    }

    /**
     * Disconnect any remaining subprocess.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
