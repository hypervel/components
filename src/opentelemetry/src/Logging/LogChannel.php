<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Logging;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Monolog\Level;
use Monolog\Logger as Monolog;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributeValidator;

class LogChannel
{
    /**
     * Create an OpenTelemetry log channel.
     */
    public function __construct(
        protected Application $app,
        protected LoggerProviderInterface $loggerProvider,
        protected OpenTelemetryManager $manager,
        protected ExceptionContextRegistry $exceptionContexts,
        protected AttributeValidator $attributeValidator,
    ) {
    }

    /**
     * Create the configured Monolog instance.
     */
    public function __invoke(array $config = []): Monolog
    {
        /** @var string $name */
        $name = $config['name'] ?? ($this->app->bound('env') ? $this->app->environment() : 'production');

        /** @var int|Level|string $level */
        $level = $config['level'] ?? Level::Debug;

        return new Monolog($name, [
            new OpenTelemetryHandler(
                $this->loggerProvider->getLogger($name),
                $this->manager,
                $this->exceptionContexts,
                $this->app,
                $this->attributeValidator,
                $level,
            ),
        ]);
    }
}
