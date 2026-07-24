<?php

declare(strict_types=1);

namespace Hypervel\Tests\Encryption;

use Hypervel\Container\Container;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class CryptFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Crypt::clearResolvedInstances();
        Crypt::setFacadeApplication(null);
    }

    public function testDynamicCallsForwardTheirMethodArgumentsAndReturnValue(): void
    {
        $root = m::mock();
        $root->shouldReceive('probe')
            ->once()
            ->with('value', 42)
            ->andReturn('result');

        $app = new Container;
        $app->instance('encrypter', $root);

        Crypt::setFacadeApplication($app);

        $this->assertSame('result', Crypt::probe('value', 42));
    }

    public function testDynamicCallsWithoutAFacadeRootThrowTheBaseException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A facade root has not been set.');

        Crypt::probe();
    }
}
