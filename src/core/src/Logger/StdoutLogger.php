<?php

declare(strict_types=1);

namespace Hypervel\Core\Logger;

use DateTimeInterface;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use InvalidArgumentException;
use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Low-level PSR-3 logger that writes directly to stdout.
 *
 * Used by Swoole server infrastructure that needs logging before the application
 * log stack is available. Supports human-readable line and structured JSON output.
 */
class StdoutLogger implements StdoutLoggerInterface
{
    use LoggerTrait;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private const STANDARD_LEVELS = [
        LogLevel::EMERGENCY => true,
        LogLevel::ALERT => true,
        LogLevel::CRITICAL => true,
        LogLevel::ERROR => true,
        LogLevel::WARNING => true,
        LogLevel::NOTICE => true,
        LogLevel::INFO => true,
        LogLevel::DEBUG => true,
    ];

    private OutputInterface $output;

    private string $format;

    /** @var array<string, true> */
    private array $logLevels;

    public function __construct(private Repository $config, ?OutputInterface $output = null)
    {
        $this->output = $output ?? new ConsoleOutput;
        $this->reloadConfiguration();
    }

    /**
     * Reload the cached stdout logger configuration.
     *
     * Boot-only. The cached format and enabled levels affect every subsequent
     * log entry in the worker.
     */
    public function reloadConfiguration(): void
    {
        $format = $this->config->string('app.stdout_log.format');

        if (! in_array($format, ['line', 'json'], true)) {
            throw new InvalidArgumentException("Unsupported stdout log format [{$format}].");
        }

        $logLevels = [];

        foreach ($this->config->array('app.stdout_log.level') as $level) {
            if (! is_string($level)) {
                throw new InvalidArgumentException(sprintf(
                    'Stdout log levels must be strings, %s given.',
                    get_debug_type($level),
                ));
            }

            $logLevels[$level] = true;
        }

        $this->format = $format;
        $this->logLevels = $logLevels;
    }

    /**
     * Log a message at the given level.
     * @param mixed $level
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (! is_string($level)) {
            throw new PsrInvalidArgumentException(sprintf(
                'Log level must be a string, %s given.',
                get_debug_type($level),
            ));
        }

        if (! isset(self::STANDARD_LEVELS[$level]) && ! isset($this->logLevels[$level])) {
            throw new PsrInvalidArgumentException("Unknown log level [{$level}].");
        }

        if (! isset($this->logLevels[$level])) {
            return;
        }

        $tags = [];

        if (array_key_exists('component', $context)) {
            $tags['component'] = $this->stringify($context['component']);
            unset($context['component']);
        }

        $context = array_map($this->normalizeContextValue(...), $context);
        $interpolated = $this->interpolate($this->stringify($message), $context);

        if ($this->format === 'json') {
            $this->output->writeln(
                $this->getJsonMessage($interpolated, $level, $tags, $context),
                OutputInterface::OUTPUT_RAW,
            );
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
        $line = '[' . $timestamp . '] <' . $style . '>[' . $this->escapeLineValue(strtoupper($level)) . ']</>';

        foreach ($tags as $value) {
            $line .= ' [' . $this->escapeLineValue($value) . ']';
        }

        return $line . ' ' . $this->escapeLineValue($message);
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

        if (($json = $this->encodeJson($entry)) !== null) {
            return $json;
        }

        unset($entry['context']);

        return $this->encodeJson($entry) ?? 'null';
    }

    /**
     * Encode a structured log entry without allowing user serializers to escape.
     */
    protected function encodeJson(array $entry): ?string
    {
        try {
            $json = json_encode($entry, self::JSON_FLAGS);

            return is_string($json) ? $json : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Interpolate context values into a message.
     */
    protected function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            $replacements['{' . $key . '}'] = $this->stringify($value);
        }

        return strtr($message, $replacements);
    }

    /**
     * Normalize a top-level context value for structured output.
     */
    protected function normalizeContextValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339),
            $value instanceof Stringable => $this->stringify($value),
            is_object($value) => '<OBJECT> ' . $value::class,
            is_resource($value) => '<RESOURCE> ' . get_resource_type($value),
            default => $value,
        };
    }

    /**
     * Convert a log value to a safe string representation.
     */
    protected function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339),
            $value instanceof Stringable => $this->stringifyStringable($value),
            is_object($value) => '<OBJECT> ' . $value::class,
            is_array($value) => '<ARRAY>',
            is_resource($value) => '<RESOURCE> ' . get_resource_type($value),
            default => '<' . strtoupper(get_debug_type($value)) . '>',
        };
    }

    /**
     * Convert a stringable object without allowing its failure to mask a log entry.
     */
    protected function stringifyStringable(Stringable $value): string
    {
        try {
            return (string) $value;
        } catch (Throwable) {
            return '<OBJECT> ' . $value::class;
        }
    }

    /**
     * Escape dynamic text that Symfony Console would otherwise interpret as markup.
     */
    protected function escapeLineValue(string $value): string
    {
        if (! str_contains($value, '<') && ! str_contains($value, '>') && ! str_ends_with($value, '\\')) {
            return $value;
        }

        return OutputFormatter::escape($value);
    }
}
