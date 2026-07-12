<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Swoole\Coroutine as SwooleCoroutine;

class CoroutineCreateFailureTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testCoroutineCreationFailureThrowsTheNativeError(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);

        SwooleCoroutine\run(function (): void {
            try {
                Coroutine::create(static fn (): null => null);
                $this->fail('Expected coroutine creation to fail.');
            } catch (CoroutineCreateException $exception) {
                $this->assertSame(SWOOLE_ERROR_PHP_FATAL_ERROR, $exception->getCode());
                $this->assertStringContainsString(
                    swoole_strerror(SWOOLE_ERROR_PHP_FATAL_ERROR),
                    $exception->getMessage(),
                );
            }
        });
    }
}
