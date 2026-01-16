<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Attributes;

use Attribute;
use Closure;
use Hypervel\Foundation\Testing\Contracts\Attributes\Actionable;

/**
 * Skips the test if the required environment variable is missing.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequiresEnv implements Actionable
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $message = null
    ) {
    }

    /**
     * Handle the attribute.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     * @param Closure(string, array<int, mixed>):void $action
     */
    public function handle($app, Closure $action): mixed
    {
        $message = $this->message ?? "Missing required environment variable `{$this->key}`";

        if (env($this->key) === null) {
            \call_user_func($action, 'markTestSkipped', [$message]);
        }

        return null;
    }
}
