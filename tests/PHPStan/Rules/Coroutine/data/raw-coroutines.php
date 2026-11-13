<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\Data;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Swoole\Coroutine as SwooleCoroutine;

use function Hypervel\Coroutine\co;
use function Hypervel\Coroutine\go;
use function Swoole\Coroutine\go as swooleGo;
use function Swoole\Coroutine\run;

class RawCoroutines
{
    public function spawns(): void
    {
        SwooleCoroutine::create(static function (): void {});
        swooleGo(static function (): void {});
        EngineCoroutine::create(static function (): void {});
        (new EngineCoroutine(static function (): void {}))->execute();
        InheritingEngineCoroutine::create(static function (): void {});

        Coroutine::create(static function (): void {});
        Coroutine::fork(static function (): void {});
        go(static function (): void {});
        co(static function (): void {});
        run(static function (): void {});
        OverridingEngineCoroutine::create(static function (): void {});
        EngineCoroutine::create(...);
    }
}

class InheritingEngineCoroutine extends EngineCoroutine
{
}

class OverridingEngineCoroutine extends EngineCoroutine
{
    public static function create(callable $callable, mixed ...$data): static
    {
        return new static($callable);
    }
}
