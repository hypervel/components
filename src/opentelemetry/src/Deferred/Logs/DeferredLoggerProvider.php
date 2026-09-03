<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Logs;

use Hypervel\OpenTelemetry\Deferred\InstrumentationScope;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use Override;

/**
 * Defer real logger creation until a worker-local provider is available.
 */
class DeferredLoggerProvider implements LoggerProviderInterface
{
    protected ?LoggerProviderInterface $delegate = null;

    /** @var list<DeferredLogger> */
    protected array $loggers = [];

    /**
     * Return a logger for the given instrumentation scope.
     */
    #[Override]
    public function getLogger(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = [],
    ): LoggerInterface {
        if ($this->delegate !== null) {
            return $this->delegate->getLogger($name, $version, $schemaUrl, $attributes);
        }

        return $this->loggers[] = new DeferredLogger(
            new InstrumentationScope($name, $version, $schemaUrl, $attributes),
        );
    }

    /**
     * Bind every pre-fork logger to a worker-local provider.
     */
    public function bind(LoggerProviderInterface $provider): void
    {
        $this->delegate = $provider;

        foreach ($this->loggers as $logger) {
            $logger->bind($provider);
        }
    }

    /**
     * Unbind every pre-fork logger from its worker-local provider.
     */
    public function unbind(): void
    {
        foreach ($this->loggers as $logger) {
            $logger->unbind();
        }

        $this->delegate = null;
    }
}
