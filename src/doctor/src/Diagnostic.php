<?php

declare(strict_types=1);

namespace Hypervel\Doctor;

use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Results\Status;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Stringable;

abstract class Diagnostic
{
    public string $name;

    public string $group;

    /**
     * Create a new diagnostic instance.
     */
    public function __construct()
    {
        $this->name ??= Str::of(class_basename(static::class))->snake(' ')->ucfirst()->toString();
        $this->group ??= Str::kebab(class_basename(static::class));
    }

    /**
     * Run the diagnostic.
     */
    abstract public function check(): DiagnosticResult;

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [];
    }

    /**
     * Create a passing diagnostic result from a named message.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     */
    protected function pass(string $message, array $replace = []): DiagnosticResult
    {
        return $this->diagnosticResult(Status::Pass, $message, $replace);
    }

    /**
     * Create a failing diagnostic result from a named message.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     */
    protected function fail(string $message, array $replace = []): DiagnosticResult
    {
        return $this->diagnosticResult(Status::Fail, $message, $replace);
    }

    /**
     * Create a warning diagnostic result from a named message.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     */
    protected function warn(string $message, array $replace = []): DiagnosticResult
    {
        return $this->diagnosticResult(Status::Warn, $message, $replace);
    }

    /**
     * Create a notice diagnostic result from a named message.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     */
    protected function notice(string $message, array $replace = []): DiagnosticResult
    {
        return $this->diagnosticResult(Status::Notice, $message, $replace);
    }

    /**
     * Create a skipped diagnostic result from a named message.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     */
    protected function skip(string $message, array $replace = []): DiagnosticResult
    {
        return $this->diagnosticResult(Status::Skip, $message, $replace);
    }

    /**
     * Create an error diagnostic result from a named message.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     */
    protected function error(string $message, array $replace = []): DiagnosticResult
    {
        return $this->diagnosticResult(Status::Error, $message, $replace);
    }

    /**
     * Create a passing fix result from a named message.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     */
    protected function fixed(string $message, array $replace = []): FixResult
    {
        return $this->diagnosticFixResult(Status::Pass, $message, $replace);
    }

    /**
     * Create a failing fix result from a named message.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     */
    protected function fixFailed(string $message, array $replace = []): FixResult
    {
        return $this->diagnosticFixResult(Status::Fail, $message, $replace);
    }

    /**
     * Create a diagnostic result from a named message.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     */
    private function diagnosticResult(Status $status, string $message, array $replace = []): DiagnosticResult
    {
        $definition = $this->message($message);
        $tokens = $this->tokens($replace);

        $result = new DiagnosticResult(
            $status,
            Str::swap($tokens, $definition->summary),
            $this->code($message),
        );

        if ($definition->remediation !== null) {
            $result->suggest(Str::swap($tokens, $definition->remediation));
        }

        if ($definition->confirmation !== null) {
            $result->confirmUsing(Str::swap($tokens, $definition->confirmation));
        }

        foreach ($definition->links as $link) {
            $result->link($link->replace($tokens));
        }

        return $result;
    }

    /**
     * Create a fix result from a named message.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     */
    private function diagnosticFixResult(Status $status, string $message, array $replace = []): FixResult
    {
        $definition = $this->message($message);

        return new FixResult(
            $status,
            Str::swap($this->tokens($replace), $definition->summary),
            $this->fixCode($message),
        );
    }

    /**
     * Get a named message definition.
     */
    protected function message(string $message): Message
    {
        $definition = $this->messages()[$message] ?? null;

        if (is_string($definition)) {
            return Message::make($definition);
        }

        if (! $definition instanceof Message) {
            throw new InvalidArgumentException(sprintf(
                'Message [%s] is not defined for diagnostic [%s].',
                $message,
                static::class,
            ));
        }

        return $definition;
    }

    /**
     * Get a machine-readable result code for the message.
     */
    protected function code(string $message): string
    {
        return Str::of(class_basename(static::class))
            ->kebab()
            ->append('.' . $message)
            ->toString();
    }

    /**
     * Get a machine-readable fix result code for the message.
     */
    protected function fixCode(string $message): string
    {
        return Str::of(class_basename(static::class))
            ->kebab()
            ->append('.fix.' . $message)
            ->toString();
    }

    /**
     * Normalize replacement values into Str::swap tokens.
     *
     * @param array<string, null|bool|float|int|string|Stringable> $replace
     * @return array<string, string>
     */
    protected function tokens(array $replace): array
    {
        $tokens = [];

        foreach ($replace as $key => $value) {
            $tokens['{' . $key . '}'] = match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }

        return $tokens;
    }

    /**
     * Get the Composer package that provides the diagnostic.
     */
    public function package(): ?string
    {
        return DiagnosticSource::resolve(static::class)->package;
    }
}
