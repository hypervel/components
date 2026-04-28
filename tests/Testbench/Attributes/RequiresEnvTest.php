<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Attributes\RequiresEnv;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

class RequiresEnvTest extends TestCase
{
    #[Test]
    public function itShouldRunTheTestWhenEnvVariableIsMissing(): void
    {
        $attribute = new RequiresEnv('TESTBENCH_MISSING_ENV');

        $callback = $attribute->handle(m::mock(ApplicationContract::class), function ($method, $parameters): void {
            $this->assertSame('markTestSkipped', $method);
            $this->assertSame(['Missing required environment variable `TESTBENCH_MISSING_ENV`'], $parameters);
        });

        $this->assertNull($callback);
    }
}
