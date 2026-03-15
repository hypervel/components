<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\Invokable;

/**
 * Sets an environment variable for the duration of a test.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class WithEnv implements Invokable
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $value
    ) {
    }

    /**
     * Handle the attribute.
     */
    public function __invoke(ApplicationContract $app): Closure
    {
        $key = $this->key;
        $previous = getenv($key);

        putenv("{$key}={$this->value}");
        $_ENV[$key] = $this->value;
        $_SERVER[$key] = $this->value;

        return static function () use ($key, $previous) {
            if ($previous === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$previous}");
                $_ENV[$key] = $previous;
                $_SERVER[$key] = $previous;
            }
        };
    }
}
