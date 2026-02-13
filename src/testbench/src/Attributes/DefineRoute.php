<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Router\Router;
use Hypervel\Testbench\Contracts\Attributes\Actionable;

/**
 * Calls a test method with the router instance for route definition.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class DefineRoute implements Actionable
{
    public function __construct(
        public readonly string $method
    ) {
    }

    /**
     * Handle the attribute.
     *
     * @param Closure(string, array<int, mixed>):void $action
     */
    public function handle(ApplicationContract $app, Closure $action): mixed
    {
        $router = $app->make(Router::class);

        \call_user_func($action, $this->method, [$router]);

        return null;
    }
}
