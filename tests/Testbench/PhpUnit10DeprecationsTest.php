<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Log;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Exceptions\DeprecatedException;
use Hypervel\Testbench\Foundation\Env;
use PHPUnit\Framework\Attributes\Test;

class PhpUnit10DeprecationsTest extends TestCase
{
    #[Test]
    public function handlePhp81DeprecationsUsingLogs()
    {
        Log::shouldReceive('channel')
            ->once()->with('deprecations')
            ->andReturnSelf()
            ->shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'zzz in'));

        trigger_error('zzz', E_USER_DEPRECATED);
    }

    #[Test]
    #[DefineEnvironment('defineConvertDeprecationsToExceptions')]
    public function handlePhp81DeprecationsUsingTestbenchException()
    {
        $this->expectException(DeprecatedException::class);
        $this->expectExceptionMessage('zzz');

        trigger_error('zzz', E_USER_DEPRECATED);
    }

    /**
     * Define environment.
     */
    protected function defineConvertDeprecationsToExceptions(ApplicationContract $app): void
    {
        $key = 'TESTBENCH_CONVERT_DEPRECATIONS_TO_EXCEPTIONS';
        $hasOriginalValue = Env::has($key);
        $value = $hasOriginalValue ? Env::forward($key) : null;

        Env::set($key, 'true');

        $this->beforeApplicationDestroyed(function () use ($key, $hasOriginalValue, $value): void {
            if (! $hasOriginalValue) {
                Env::forget($key);
            } else {
                Env::set($key, $value);
            }
        });
    }
}
