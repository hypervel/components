<?php

declare(strict_types=1);

namespace Hypervel\Tests\ExceptionHandler;

use Hypervel\ExceptionHandler\Formatter\DefaultFormatter;
use Hypervel\Tests\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class FormatterTest extends TestCase
{
    public function testDefaultFormatter()
    {
        $formatter = new DefaultFormatter();

        $message = uniqid();
        $code = rand(1000, 9999);
        $exception = new RuntimeException($message, $code);
        $this->assertSame((string) $exception, $formatter->format($exception));
    }
}
