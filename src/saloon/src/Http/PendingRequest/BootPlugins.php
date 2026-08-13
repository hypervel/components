<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\PendingRequest;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;

class BootPlugins
{
    /**
     * The plugin boot methods for each resource class.
     *
     * @var array<class-string, list<string>>
     */
    protected static array $methods = [];

    /**
     * Boot the connector and request plugins.
     */
    public function __invoke(PendingRequest $pendingRequest): PendingRequest
    {
        $this->boot($pendingRequest->connector(), $pendingRequest);
        $this->boot($pendingRequest->request(), $pendingRequest);

        return $pendingRequest;
    }

    /**
     * Boot the plugins used by the resource.
     */
    protected function boot(Connector|Request $resource, PendingRequest $pendingRequest): void
    {
        foreach (static::$methods[$resource::class] ??= $this->methods($resource) as $method) {
            $resource->{$method}($pendingRequest);
        }
    }

    /**
     * Resolve the plugin boot methods used by the resource.
     *
     * @return list<string>
     */
    protected function methods(Connector|Request $resource): array
    {
        $methods = [];

        foreach (class_uses_recursive($resource) as $trait) {
            $method = 'boot' . class_basename($trait);

            if (method_exists($resource, $method)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$methods = [];
    }
}
