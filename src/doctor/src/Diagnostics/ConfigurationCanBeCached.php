<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Support\Arr;
use LogicException;
use Throwable;
use UnitEnum;

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
            if (is_object($value) && ! $value instanceof UnitEnum && ! method_exists($value, '__set_state')) {
                return new LogicException(
                    sprintf('The configuration value at [%s] is not serializable.', $key),
                    0,
                    $previous,
                );
            }
        }

        return new LogicException('The configuration files are not serializable: ' . $previous->getMessage(), 0, $previous);
    }
}
