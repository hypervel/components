<?php

declare(strict_types=1);

namespace Hypervel\Core\Logger;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Psr\Log\LogLevel;
use Stringable;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;
use function str_replace;

/**
 * Low-level PSR-3 logger that writes directly to stdout.
 *
 * Used by Swoole server infrastructure (connection pools, server lifecycle,
 * response emitter) that needs logging before the application log stack is
 * available. Supports "line" (human-readable colored) and "json" (structured
 * JSON lines for log aggregators) output formats.
 */
class StdoutLogger implements StdoutLoggerInterface
{
    private OutputInterface $output;

    private string $format;

    private array $tags = [
        'component',
    ];

    public function __construct(private Repository $config, ?OutputInterface $output = null)
    {
        $this->output = $output ?? new ConsoleOutput;
        $this->format = $this->config->get('app.stdout_log.format', 'line');
    }

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Log a message at the given level.
     * @param mixed $level
     * @param mixed $message
     */
    public function log($level, $message, array $context = []): void
    {
        $logLevel = $this->config->get('app.stdout_log.level', []);

        // Check if the log level is allowed
        if (! in_array($level, $logLevel, true)) {
            return;
        }

        $tags = array_intersect_key($context, array_flip($this->tags));
        $context = array_diff_key($context, $tags);

        // Handle objects that are not Stringable
        foreach ($context as $key => $value) {
            if (is_object($value) && ! $value instanceof Stringable) {
                $context[$key] = '<OBJECT> ' . $value::class;
            }
        }

        $search = array_map(fn ($key) => sprintf('{%s}', $key), array_keys($context));
        $interpolated = str_replace($search, $context, (string) $message);

        if ($this->format === 'json') {
            $this->output->writeln($this->getJsonMessage($interpolated, $level, $tags, $context));
            return;
        }

        $this->output->writeln($this->getLineMessage($interpolated, $level, $tags));
    }

    /**
     * Format a human-readable log line with timestamp, colored level tag and context tags.
     */
    protected function getLineMessage(string $message, string $level = LogLevel::INFO, array $tags = []): string
    {
        $style = match ($level) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL => 'error',
            LogLevel::ERROR => 'fg=red',
            LogLevel::WARNING, LogLevel::NOTICE => 'comment',
            default => 'info',
        };

        $timestamp = date('Y-m-d H:i:s');
        $template = sprintf('[%s] <%s>[%s]</>', $timestamp, $style, strtoupper($level));
        $implodedTags = '';
        foreach ($tags as $value) {
            $implodedTags .= (' [' . $value . ']');
        }

        return sprintf($template . $implodedTags . ' %s', $message);
    }

    /**
     * Format a structured JSON log line for log aggregators.
     */
    protected function getJsonMessage(string $message, string $level, array $tags, array $context): string
    {
        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
        ];

        if ($tags !== []) {
            $entry['tags'] = $tags;
        }

        if ($context !== []) {
            $entry['context'] = $context;
        }

        return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
