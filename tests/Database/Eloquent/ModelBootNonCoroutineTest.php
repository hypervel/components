<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent\ModelBootNonCoroutineTest;

use Hypervel\Coroutine\Mutex;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\TestCase;
use ReflectionProperty;

class ModelBootNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testNonCoroutineFirstBootDoesNotCreateAMutex(): void
    {
        Model::flushState();
        Mutex::flushState();
        NonCoroutineBootModel::$bootCalls = 0;

        new NonCoroutineBootModel;

        $this->assertSame(-1, Coroutine::id());
        $this->assertSame(1, NonCoroutineBootModel::$bootCalls);
        $this->assertSame(
            [],
            (new ReflectionProperty(Mutex::class, 'channels'))->getValue()
        );
    }
}

class NonCoroutineBootModel extends Model
{
    public static int $bootCalls = 0;

    protected static function booting(): void
    {
        ++static::$bootCalls;
    }
}
