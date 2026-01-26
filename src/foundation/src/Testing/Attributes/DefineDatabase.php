<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Attributes;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Contracts\Attributes\Actionable;
use Hypervel\Foundation\Testing\Contracts\Attributes\AfterEach;
use Hypervel\Foundation\Testing\Contracts\Attributes\BeforeEach;

/**
 * Calls a test method for database setup with deferred execution support.
 *
 * Resets RefreshDatabaseState before and after each test.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class DefineDatabase implements Actionable, AfterEach, BeforeEach
{
    public function __construct(
        public readonly string $method,
        public readonly bool $defer = true
    ) {
    }

    /**
     * Handle the attribute before each test.
     */
    public function beforeEach(ApplicationContract $app): void
    {
        ResetRefreshDatabaseState::run();
    }

    /**
     * Handle the attribute after each test.
     */
    public function afterEach(ApplicationContract $app): void
    {
        ResetRefreshDatabaseState::run();
    }

    /**
     * Handle the attribute.
     *
     * @param Closure(string, array<int, mixed>):void $action
     */
    public function handle(ApplicationContract $app, Closure $action): ?Closure
    {
        $resolver = function () use ($app, $action) {
            \call_user_func($action, $this->method, [$app]);
        };

        if ($this->defer === false) {
            $resolver();

            return null;
        }

        return $resolver;
    }
}
