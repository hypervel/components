<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures;

trait OverridesConfig
{
    /**
     * Run a callback with the given config values, restoring the config afterwards.
     *
     * @template TResult
     *
     * @param array<string, mixed> $values
     * @param callable(): TResult $callback
     * @return TResult
     */
    private function withConfig(array $values, callable $callback): mixed
    {
        $items = config()->all();

        try {
            config($values);

            return $callback();
        } finally {
            config()->replaceItems($items);
        }
    }
}
