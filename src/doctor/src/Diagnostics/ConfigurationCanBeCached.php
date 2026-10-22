<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Support\Arr;
use LogicException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class ConfigurationCanBeCached extends Diagnostic
{
    public string $name = 'Config can be cached';

    public string $group = 'configuration';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'cannot-cache' => Message::make(
                summary: 'The application configuration cannot be cached.',
                remediation: 'Remove closures and other non-serializable values from the configuration files before running `php artisan config:cache`.',
            )->link(Link::docs('configuration', 'configuration-caching')),
            'can-cache' => 'The application configuration can be cached.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        try {
            $this->cacheConfiguration();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            return $this->fail('cannot-cache')
                ->withDetails($e->getMessage());
        }

        return $this->pass('can-cache');
    }

    /**
     * Validate the current configuration's exported PHP representation.
     */
    private function cacheConfiguration(): void
    {
        try {
            eval('return ' . var_export(config()->all(), true) . ';');
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            throw $this->serializationException($e);
        }
    }

    /**
     * Build the most specific serialization exception available.
     */
    private function serializationException(Throwable $previous): Throwable
    {
        foreach (Arr::dot(config()->all()) as $key => $value) {
            try {
                eval('return ' . var_export($value, true) . ';');
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                return new LogicException(
                    sprintf('The configuration value at [%s] is not serializable: %s', $key, $exception->getMessage()),
                    0,
                    $previous,
                );
            }
        }

        return new LogicException('The configuration files are not serializable: ' . $previous->getMessage(), 0, $previous);
    }
}
