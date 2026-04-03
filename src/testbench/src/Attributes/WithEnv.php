<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\Invokable;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\Foundation\UndefinedValue;

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
        $value = Env::get($key, new UndefinedValue());

        Env::set($key, $this->value ?? '(null)');

        return static function () use ($key, $value) {
            if ($value instanceof UndefinedValue) {
                Env::forget($key);
            } else {
                Env::set($key, Env::encode($value));
            }
        };
    }
}
