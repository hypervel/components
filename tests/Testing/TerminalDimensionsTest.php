<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Testing\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionProperty;
use Swoole\Coroutine;
use Symfony\Component\Console\Terminal;

#[RunTestsInSeparateProcesses]
class TerminalDimensionsTest extends UnitTestCase
{
    /**
     * Remove inherited dimensions before the framework invokes the test method.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->runTestsInCoroutine = $this->providedData()[0];

        putenv('COLUMNS');
        putenv('LINES');
        unset($_ENV['COLUMNS'], $_ENV['LINES'], $_SERVER['COLUMNS'], $_SERVER['LINES']);
        (new ReflectionProperty(Terminal::class, 'width'))->setValue(null, null);
        (new ReflectionProperty(Terminal::class, 'height'))->setValue(null, null);
    }

    #[DataProvider('coroutineModes')]
    public function testConfiguresHeadlessTerminalDimensionsWithoutAnApplication(bool $runTestsInCoroutine): void
    {
        $this->assertFalse(stream_isatty(STDOUT));
        $this->assertSame($runTestsInCoroutine, Coroutine::getCid() !== -1);

        $this->assertSame([false, false], [getenv('COLUMNS'), getenv('LINES')]);
        $this->assertSame([80, 24], [(new Terminal)->getWidth(), (new Terminal)->getHeight()]);
    }

    /**
     * Provide coroutine-enabled and coroutine-disabled test modes.
     */
    public static function coroutineModes(): array
    {
        return [
            'framework coroutine' => [true],
            'without framework coroutine' => [false],
        ];
    }
}
