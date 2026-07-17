<?php

declare(strict_types=1);

namespace Hypervel\Tests\Hashing;

use Hypervel\Container\Container;
use Hypervel\Support\Facades\Hash;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class HashFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Hash::clearResolvedInstances();
        Hash::setFacadeApplication(null);
    }

    public function testDynamicCallsForwardTheirMethodArgumentsAndReturnValue(): void
    {
        $root = m::mock();
        $root->shouldReceive('probe')
            ->once()
            ->with('value', 42)
            ->andReturn('result');

        $app = new Container;
        $app->instance('hash', $root);

        Hash::setFacadeApplication($app);

        $this->assertSame('result', Hash::probe('value', 42));
    }

    public function testDynamicCallsWithoutAFacadeRootThrowTheBaseException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A facade root has not been set.');

        Hash::probe();
    }
}
