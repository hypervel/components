<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Testbench\Attributes\RequiresEnv;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RequiresEnvTest extends TestCase
{
    #[Test]
    public function itShouldRunTheTestWhenEnvVariableIsMissing(): void
    {
        $attribute = new RequiresEnv('TESTBENCH_MISSING_ENV');

        $callback = $attribute->handle($this->app, function ($method, $parameters): void {
            $this->assertSame('markTestSkipped', $method);
            $this->assertSame(['Missing required environment variable `TESTBENCH_MISSING_ENV`'], $parameters);
        });

        $this->assertNull($callback);
    }
}
