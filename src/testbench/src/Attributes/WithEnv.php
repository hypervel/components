<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\Invokable;
use Hypervel\Testbench\Foundation\Bootstrap\LoadEnvironmentVariablesFromArray;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\Foundation\UndefinedValue;

use function Hypervel\Testbench\parse_environment_variables;

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
        $value = Env::get($key, new UndefinedValue);

        self::setEnvironmentVariable($app, $key, $this->value);

        return static function () use ($app, $key, $value): void {
            if ($value instanceof UndefinedValue) {
                Env::forget($key);
            } else {
                self::setEnvironmentVariable($app, $key, $value);
            }
        };
    }

    /**
     * Set an environment variable through the shared dotenv parser boundary.
     */
    private static function setEnvironmentVariable(
        ApplicationContract $app,
        string $key,
        mixed $value,
    ): void {
        (new LoadEnvironmentVariablesFromArray(
            parse_environment_variables([$key => $value]),
        ))->bootstrap($app);
    }
}
