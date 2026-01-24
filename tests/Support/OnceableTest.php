<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Support\HasOnceHash;
use Hypervel\Support\Onceable;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class OnceableTest extends TestCase
{
    public function testTryFromTraceCapturesCallingObject(): void
    {
        $onceable = $this->createOnceable(fn () => 'value');

        $this->assertSame($this, $onceable->object);
    }

    public function testHashUsesOnceHashImplementation(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);

        $value = new OnceHashStub('same');
        $onceableA = Onceable::tryFromTrace($trace, fn () => $value);

        $value = new OnceHashStub('same');
        $onceableB = Onceable::tryFromTrace($trace, fn () => $value);

        $value = new OnceHashStub('different');
        $onceableC = Onceable::tryFromTrace($trace, fn () => $value);

        $this->assertNotNull($onceableA);
        $this->assertNotNull($onceableB);
        $this->assertNotNull($onceableC);
        $this->assertSame($onceableA->hash, $onceableB->hash);
        $this->assertNotSame($onceableA->hash, $onceableC->hash);
    }

    private function createOnceable(callable $callback): Onceable
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);

        $onceable = Onceable::tryFromTrace($trace, $callback);

        $this->assertNotNull($onceable);

        return $onceable;
    }
}

class OnceHashStub implements HasOnceHash
{
    public function __construct(private string $hash)
    {
    }

    public function onceHash(): string
    {
        return $this->hash;
    }
}
