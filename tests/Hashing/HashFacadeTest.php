<?php

declare(strict_types=1);

namespace Hypervel\Tests\Hashing;

use Hypervel\Container\Container;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Support\Facades\Hash;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class HashFacadeTest extends TestCase
{
    use VerifiesDoubles;

    protected function setUp(): void
    {
        parent::setUp();

        Hash::clearResolvedInstances();
        Hash::setFacadeApplication(null);
    }

    public function testDynamicCallsForwardTheirMethodArgumentsAndReturnValue(): void
    {
        $root = Double::for(Hasher::class)->strict();
        $root->expects('make')
            ->with('value', ['rounds' => 4])
            ->returns('result');

        $app = new Container;
        $app->instance('hash', $root);

        Hash::setFacadeApplication($app);

        $this->assertSame('result', Hash::make('value', ['rounds' => 4]));
    }

    public function testDynamicCallsWithoutAFacadeRootThrowTheBaseException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('A facade root has not been set.');

        Hash::probe();
    }
}
