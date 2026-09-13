<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Support\HasOnceHash;
use Hypervel\Support\Onceable;
use Hypervel\Tests\TestCase;

class OnceableTest extends TestCase
{
    public function testTryFromTraceCapturesCallingObject(): void
    {
        $onceable = $this->createOnceable(fn (): string => 'value');

        $this->assertSame($this, $onceable->object);
    }

    public function testTryFromTraceSupportsTopLevelCalls(): void
    {
        $trace = [['file' => __FILE__, 'line' => __LINE__, 'function' => 'once']];

        $onceable = Onceable::tryFromTrace($trace, fn (): int => 42);

        $this->assertNotNull($onceable);
        $this->assertNull($onceable->object);
        $this->assertNotEmpty($onceable->hash);
    }

    public function testHashUsesOnceHashImplementation(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);

        $value = new OnceHashStub('same');
        $onceableA = Onceable::tryFromTrace($trace, fn (): OnceHashStub => $value);

        $value = new OnceHashStub('same');
        $onceableB = Onceable::tryFromTrace($trace, fn (): OnceHashStub => $value);

        $value = new OnceHashStub('different');
        $onceableC = Onceable::tryFromTrace($trace, fn (): OnceHashStub => $value);

        $this->assertNotNull($onceableA);
        $this->assertNotNull($onceableB);
        $this->assertNotNull($onceableC);
        $this->assertSame($onceableA->hash, $onceableB->hash);
        $this->assertNotSame($onceableA->hash, $onceableC->hash);
    }

    /**
     * Create a onceable from the calling method.
     */
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
    /**
     * Create an object with an explicit cache identity.
     */
    public function __construct(private string $hash)
    {
    }

    /**
     * Get the object's cache identity.
     */
    public function onceHash(): string
    {
        return $this->hash;
    }
}
