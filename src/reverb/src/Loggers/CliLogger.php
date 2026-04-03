<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Loggers;

use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Reverb\Console\Components\Message;
use Hypervel\Reverb\Contracts\Logger;
use Hypervel\Support\Str;

class CliLogger implements Logger
{
    /**
     * The components factory instance.
     */
    protected Factory $components;

    /**
     * Create a new CLI logger instance.
     */
    public function __construct(protected OutputStyle $output)
    {
        $this->components = new Factory($output);
    }

    /**
     * Log an informational message.
     */
    public function info(string $title, ?string $message = null): void
    {
        $this->components->twoColumnDetail($title, $message);
    }

    /**
     * Log an error message.
     */
    public function error(string $string): void
    {
        $this->output->error($string);
    }

    /**
     * Log a message sent to the server.
     */
    public function message(string $message): void
    {
        $message = json_decode($message, true);

        if (isset($message['data']) && is_string($message['data'])) {
            $message['data'] = json_decode($message['data'], true);
        }

        if (isset($message['data']['channel_data']) && is_string($message['data']['channel_data'])) {
            $message['data']['channel_data'] = json_decode($message['data']['channel_data'], true);
        }

        $message = json_encode($message, JSON_PRETTY_PRINT);

        (new Message($this->output))->render(
            Str::limit($message, 200)
        );
    }

    /**
     * Append a new line to the log.
     */
    public function line(int $lines = 1): void
    {
        $this->output->newLine($lines);
    }
}
