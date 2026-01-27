<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Attributes;

use Attribute;
use Closure;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Contracts\Attributes\Actionable;
use Hypervel\Router\Router;

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
        $router = $app->get(Router::class);

        \call_user_func($action, $this->method, [$router]);

        return null;
    }
}
