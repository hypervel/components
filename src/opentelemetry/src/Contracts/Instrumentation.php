<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Contracts;

interface Instrumentation
{
    /**
     * Register the instrumentation for the current process.
     *
     * @param array<string, mixed> $options
     */
    public function register(array $options): void;
}
