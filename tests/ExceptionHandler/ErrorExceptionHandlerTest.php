<?php

declare(strict_types=1);

namespace Hypervel\Tests\ExceptionHandler;

use ErrorException;
use Hypervel\ExceptionHandler\Listener\ErrorExceptionHandler;
use Hypervel\Framework\Events\BootApplication;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;

/**
 * @internal
 * @coversNothing
 */
class ErrorExceptionHandlerTest extends TestCase
{
    #[WithoutErrorHandler]
    public function testHandleError()
    {
        $listener = new ErrorExceptionHandler();
        $listener->handle(new BootApplication());

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Undefined array key 1');

        try {
            $array = [];
            $array[1];
        } finally {
            restore_error_handler();
        }
    }
}
