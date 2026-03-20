<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\Actionable;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ResolvesHypervel implements Actionable
{
    public function __construct(
        public readonly string $method
    ) {
    }

    /**
     * @param Closure(string, array<int, mixed>):void $action
     */
    public function handle(ApplicationContract $app, Closure $action): mixed
    {
        \call_user_func($action, $this->method, [$app]);

        return null;
    }
}
