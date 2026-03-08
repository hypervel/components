<?php

declare(strict_types=1);

namespace Hypervel\Tests\Framework;

use Hypervel\Config\Repository;
use Hypervel\Framework\Logger\StdoutLogger;
use Hypervel\Tests\Framework\Fixtures\TestObject;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * @internal
 * @coversNothing
 */
class StdoutLoggerTest extends TestCase
{
    public function testLog()
    {
        $logger = $this->getLogger('<info>[INFO]</> Hello Hypervel.');
        $logger->info('Hello {name}.', ['name' => 'Hypervel']);
    }

    public function testFixedErrorContextCount()
    {
        $logger = $this->getLogger('<info>[INFO]</> [test tag] Hello Hypervel.');
        $logger->info('Hello {name}.', [
            'component' => 'test tag',
            'name' => 'Hypervel',
        ]);
    }

    public function testLogComplexityContext()
    {
        $logger = $this->getLogger('<info>[INFO]</> [test tag] Hello Hypervel <OBJECT> Hypervel\Tests\Framework\Fixtures\TestObject.');
        $logger->info('Hello {name} {object}.', [
            'name' => 'Hypervel',
            // tags
            'component' => 'test tag',
            // object can not be cast to string
            'object' => new TestObject(),
        ]);
    }

    public function testLogThrowable()
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldReceive('writeln')->with(m::any())->once()->andReturnUsing(function ($message) {
            $this->assertMatchesRegularExpression('/RuntimeException: Invalid Arguments./', $message);
        });
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log_level' => [LogLevel::ERROR]],
        ]), $output);

        $logger->error(new RuntimeException('Invalid Arguments.'));
    }

    protected function getLogger(string $expected): StdoutLogger
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldReceive('writeln')->with(m::any())->once()->andReturnUsing(function ($message) use ($expected) {
            $this->assertSame($expected, $message);
        });
        return new StdoutLogger(new Repository([
            'app' => ['stdout_log_level' => [LogLevel::INFO]],
        ]), $output);
    }
}
