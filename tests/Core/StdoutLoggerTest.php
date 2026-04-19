<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core;

use DateTimeImmutable;
use DateTimeInterface;
use Hypervel\Config\Repository;
use Hypervel\Core\Logger\StdoutLogger;
use Hypervel\Tests\Core\Fixtures\TestObject;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;
use Symfony\Component\Console\Output\ConsoleOutput;

class StdoutLoggerTest extends TestCase
{
    public function testLog()
    {
        $logger = $this->getLineLogger('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] <info>\[INFO\]<\/> Hello Hypervel\.$/');
        $logger->info('Hello {name}.', ['name' => 'Hypervel']);
    }

    public function testFixedErrorContextCount()
    {
        $logger = $this->getLineLogger('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] <info>\[INFO\]<\/> \[test tag\] Hello Hypervel\.$/');
        $logger->info('Hello {name}.', [
            'component' => 'test tag',
            'name' => 'Hypervel',
        ]);
    }

    public function testLogComplexityContext()
    {
        $logger = $this->getLineLogger('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] <info>\[INFO\]<\/> \[test tag\] Hello Hypervel <OBJECT> Hypervel\\\Tests\\\Core\\\Fixtures\\\TestObject\.$/');
        $logger->info('Hello {name} {object}.', [
            'name' => 'Hypervel',
            // tags
            'component' => 'test tag',
            // object can not be cast to string
            'object' => new TestObject,
        ]);
    }

    public function testLogThrowable()
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldReceive('writeln')->with(m::any())->once()->andReturnUsing(function ($message) {
            $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\].*RuntimeException: Invalid Arguments\./', $message);
        });
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::ERROR]]],
        ]), $output);

        $logger->error(new RuntimeException('Invalid Arguments.'));
    }

    public function testLevelFiltering()
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldNotReceive('writeln');
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::ERROR]]],
        ]), $output);

        $logger->info('This should not be logged.');
    }

    public function testDefaultFormatIsLine()
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldReceive('writeln')->with(m::any())->once()->andReturnUsing(function ($message) {
            // Line format has colored tags, not JSON
            $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] <info>\[INFO\]<\/>/', $message);
        });
        // No 'format' key — should default to 'line'
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::INFO]]],
        ]), $output);

        $logger->info('Hello.');
    }

    public function testJsonFormatBasicMessage()
    {
        $data = $this->logJson(LogLevel::INFO, 'Hello Hypervel.');

        $this->assertSame('info', $data['level']);
        $this->assertSame('Hello Hypervel.', $data['message']);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayNotHasKey('tags', $data);
        $this->assertArrayNotHasKey('context', $data);
    }

    public function testJsonFormatTimestampIsIso8601()
    {
        $data = $this->logJson(LogLevel::INFO, 'test');

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $data['timestamp']);
        $this->assertInstanceOf(DateTimeImmutable::class, $parsed);
    }

    public function testJsonFormatWithContext()
    {
        $data = $this->logJson(LogLevel::INFO, 'Hello {name}.', ['name' => 'Hypervel']);

        $this->assertSame('Hello Hypervel.', $data['message']);
        $this->assertSame(['name' => 'Hypervel'], $data['context']);
    }

    public function testJsonFormatWithTags()
    {
        $data = $this->logJson(LogLevel::INFO, 'Hello {name}.', [
            'component' => 'test tag',
            'name' => 'Hypervel',
        ]);

        $this->assertSame('Hello Hypervel.', $data['message']);
        $this->assertSame(['component' => 'test tag'], $data['tags']);
        $this->assertSame(['name' => 'Hypervel'], $data['context']);
    }

    public function testJsonFormatWithObjectContext()
    {
        $data = $this->logJson(LogLevel::INFO, 'Got {object}.', ['object' => new TestObject]);

        $this->assertSame('Got <OBJECT> Hypervel\Tests\Core\Fixtures\TestObject.', $data['message']);
        $this->assertSame(['object' => '<OBJECT> Hypervel\Tests\Core\Fixtures\TestObject'], $data['context']);
    }

    public function testJsonFormatWithThrowable()
    {
        $data = $this->logJson(LogLevel::ERROR, new RuntimeException('Invalid Arguments.'));

        $this->assertStringContainsString('RuntimeException: Invalid Arguments.', $data['message']);
    }

    public function testJsonFormatLevelFiltering()
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldNotReceive('writeln');
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::ERROR], 'format' => 'json']],
        ]), $output);

        $logger->info('This should not be logged.');
    }

    /**
     * Create a StdoutLogger configured for line format with a regex assertion on output.
     */
    protected function getLineLogger(string $expectedPattern): StdoutLogger
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldReceive('writeln')->with(m::any())->once()->andReturnUsing(function ($message) use ($expectedPattern) {
            $this->assertMatchesRegularExpression($expectedPattern, $message);
        });
        return new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::INFO]]],
        ]), $output);
    }

    /**
     * Log a message in JSON format and return the decoded output.
     */
    protected function logJson(string $level, string|Stringable $message, array $context = []): array
    {
        $captured = null;
        $output = m::mock(ConsoleOutput::class);
        $output->shouldReceive('writeln')->with(m::any())->once()->andReturnUsing(function ($message) use (&$captured) {
            $captured = $message;
        });

        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::INFO, LogLevel::ERROR], 'format' => 'json']],
        ]), $output);

        $logger->log($level, $message, $context);

        $this->assertNotNull($captured, 'Expected a log message to be written.');
        $data = json_decode($captured, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return $data;
    }
}
