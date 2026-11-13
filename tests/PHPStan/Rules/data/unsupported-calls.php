<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\UnsupportedCall;

use GuzzleHttp\ClientInterface;
use Hypervel\ApiClient\PendingRequest as ApiPendingRequest;
use Hypervel\Contracts\Container\SelfBuilding;
use Hypervel\Http\Client\PendingRequest as HttpPendingRequest;
use Hypervel\Support\Attributes\Unsupported;
use Hypervel\Support\Facades\Facade;
use LogicException;

interface ServiceContract
{
    /**
     * Run the operation.
     */
    public function bare(): void;
}

class Service implements ServiceContract
{
    /**
     * Reject the operation.
     */
    #[Unsupported]
    public function bare(): never
    {
        throw new LogicException('Unsupported.');
    }

    /**
     * Reject the operation through another unsupported one.
     */
    #[Unsupported]
    public function delegated(): never
    {
        $this->bare();
    }

    /**
     * Reject the operation, naming the alternative.
     */
    #[Unsupported(message: 'Use supported() instead.')]
    public static function guided(): never
    {
        throw new LogicException('Unsupported.');
    }

    /**
     * Run the supported operation.
     */
    public function supported(): void
    {
    }
}

class OverridingService extends Service
{
    /**
     * Run the operation this implementation supports.
     */
    public function bare(): never
    {
        throw new LogicException('Overridden.');
    }
}

class SelfBuildingService extends Service implements SelfBuilding
{
    /**
     * Create a new instance.
     */
    public static function newInstance(): static
    {
        return new static;
    }
}

class FactoryFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'unsupported.factory';
    }
}

class InstanceFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'unsupported.instance';
    }
}

class ChainFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'unsupported.chain';
    }
}

class ExtendedFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'unsupported.extended';
    }
}

class SharedLineFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'unsupported.shared-line';
    }
}

class SelfBuildingFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return SelfBuildingService::class;
    }
}

class ResolvingFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'unsupported.factory';
    }

    /**
     * Resolve the facade root instance another way.
     */
    protected static function resolveFacadeInstance(string $name): mixed
    {
        return new OverridingService;
    }
}

function test(
    Service $service,
    ?Service $optional,
    OverridingService $overriding,
    ServiceContract $contract,
    ApiPendingRequest $apiRequest,
    HttpPendingRequest $httpRequest,
    ClientInterface $client,
): void {
    // Each call is its own function, as code after a call that never returns is unreachable.
    $calls = [
        fn () => $service->bare(),
        fn () => $optional?->BARE(),
        fn () => Service::guided(),
        fn () => $apiRequest->setClient($client),

        fn () => FactoryFacade::bare(),
        fn () => InstanceFacade::whereNotNull('email'),
        fn () => ChainFacade::guided(),

        fn () => $service->bare(...),
        fn () => $service->supported(),
        fn () => $overriding->bare(),
        fn () => $contract->bare(),
        fn () => $httpRequest->setClient($client),
        fn () => ExtendedFacade::bare(),
        fn () => SharedLineFacade::bare(),
        fn () => SelfBuildingFacade::bare(),
        fn () => ResolvingFacade::bare(),
        fn () => ForwardingFacade::bare(),
        fn () => ForwardingFacade::retired(),
    ];
}

class ForwardingFacade extends Facade
{
    /**
     * Forward a static call to another implementation.
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        return (new OverridingService)->{$method}(...$args);
    }

    /**
     * Reject the facade's own operation.
     */
    #[Unsupported]
    public static function retired(): never
    {
        throw new LogicException('Unsupported.');
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'unsupported.factory';
    }
}
