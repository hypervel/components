<?php

declare(strict_types=1);

namespace Hypervel\Tests\Encryption;

use Hypervel\Container\Container;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class CryptFacadeTest extends TestCase
{
    use VerifiesDoubles;

    protected function setUp(): void
    {
        parent::setUp();

        Crypt::clearResolvedInstances();
        Crypt::setFacadeApplication(null);
    }

    public function testDynamicCallsForwardTheirMethodArgumentsAndReturnValue(): void
    {
        $root = Double::for(CryptFacadeRoot::class)->strict();
        $root->expects('probe')
            ->with('value', 42)
            ->returns('result');

        $app = new Container;
        $app->instance('encrypter', $root);

        Crypt::setFacadeApplication($app);

        $this->assertSame('result', Crypt::probe('value', 42));
    }

    public function testDynamicCallsWithoutAFacadeRootThrowTheBaseException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('A facade root has not been set.');

        Crypt::probe();
    }
}

abstract class CryptFacadeRoot
{
    /**
     * Expose a custom method for facade forwarding.
     */
    abstract public function probe(string $value, int $number): string;
}
