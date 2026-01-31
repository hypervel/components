<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\Actionable;

/**
 * Calls a test method with the application instance for environment setup.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class DefineEnvironment implements Actionable
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
        \call_user_func($action, $this->method, [$app]);

        return null;
    }
}
