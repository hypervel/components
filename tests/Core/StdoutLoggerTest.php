<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core;

use DateTimeImmutable;
use DateTimeInterface;
use Hypervel\Config\Repository;
use Hypervel\Core\Logger\StdoutLogger;
use Hypervel\Tests\Core\Fixtures\TestObject;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JsonSerializable;
use Mockery as m;
use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class StdoutLoggerTest extends TestCase
{
    public function testLog(): void
    {
        $logger = $this->getLineLogger('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] <info>\[INFO\]<\/> Hello Hypervel\.$/');
        $logger->info('Hello {name}.', ['name' => 'Hypervel']);
    }

    public function testFixedErrorContextCount(): void
    {
        $logger = $this->getLineLogger('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] <info>\[INFO\]<\/> \[test tag\] Hello Hypervel\.$/');
        $logger->info('Hello {name}.', [
            'component' => 'test tag',
            'name' => 'Hypervel',
        ]);
    }

    public function testLogComplexityContext(): void
    {
        $logger = $this->getLineLogger('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] <info>\[INFO\]<\/> \[test tag\] Hello Hypervel \\\<OBJECT\\\> Hypervel\\\Tests\\\Core\\\Fixtures\\\TestObject\.$/');
        $logger->info('Hello {name} {object}.', [
            'name' => 'Hypervel',
            // tags
            'component' => 'test tag',
            // object can not be cast to string
            'object' => new TestObject,
        ]);
    }

    public function testLogThrowable(): void
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldReceive('writeln')->with(m::any())->once()->andReturnUsing(function ($message) {
            $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\].*RuntimeException: Invalid Arguments\./', $message);
        });
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::ERROR], 'format' => 'line']],
        ]), $output);

        $logger->error(new RuntimeException('Invalid Arguments.'));
    }

    public function testLevelFiltering(): void
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldNotReceive('writeln');
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::ERROR], 'format' => 'line']],
        ]), $output);

        $logger->info('This should not be logged.');
    }

    public function testLevelFilteringUsesConstructionTimeConfig(): void
    {
        $config = new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::ERROR], 'format' => 'line']],
        ]);

        $output = m::mock(ConsoleOutput::class);
        $output->shouldReceive('writeln')->with(m::any())->once();

        $logger = new StdoutLogger($config, $output);

        $config->set('app.stdout_log.level', []);

        $logger->error('This should still be logged.');
    }

    public function testLineFormat(): void
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldReceive('writeln')->with(m::any())->once()->andReturnUsing(function ($message) {
            // Line format has colored tags, not JSON
            $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] <info>\[INFO\]<\/>/', $message);
        });
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::INFO], 'format' => 'line']],
        ]), $output);

        $logger->info('Hello.');
    }

    public function testJsonFormatBasicMessage(): void
    {
        $data = $this->logJson(LogLevel::INFO, 'Hello Hypervel.');

        $this->assertSame('info', $data['level']);
        $this->assertSame('Hello Hypervel.', $data['message']);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayNotHasKey('tags', $data);
        $this->assertArrayNotHasKey('context', $data);
    }

    public function testJsonFormatTimestampIsIso8601(): void
    {
        $data = $this->logJson(LogLevel::INFO, 'test');

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $data['timestamp']);
        $this->assertInstanceOf(DateTimeImmutable::class, $parsed);
    }

    public function testJsonFormatWithContext(): void
    {
        $data = $this->logJson(LogLevel::INFO, 'Hello {name}.', ['name' => 'Hypervel']);

        $this->assertSame('Hello Hypervel.', $data['message']);
        $this->assertSame(['name' => 'Hypervel'], $data['context']);
    }

    public function testJsonFormatWithTags(): void
    {
        $data = $this->logJson(LogLevel::INFO, 'Hello {name}.', [
            'component' => 'test tag',
            'name' => 'Hypervel',
        ]);

        $this->assertSame('Hello Hypervel.', $data['message']);
        $this->assertSame(['component' => 'test tag'], $data['tags']);
        $this->assertSame(['name' => 'Hypervel'], $data['context']);
    }

    public function testJsonFormatWithObjectContext(): void
    {
        $data = $this->logJson(LogLevel::INFO, 'Got {object}.', ['object' => new TestObject]);

        $this->assertSame('Got <OBJECT> Hypervel\Tests\Core\Fixtures\TestObject.', $data['message']);
        $this->assertSame(['object' => '<OBJECT> Hypervel\Tests\Core\Fixtures\TestObject'], $data['context']);
    }

    public function testJsonFormatWithThrowable(): void
    {
        $data = $this->logJson(LogLevel::ERROR, new RuntimeException('Invalid Arguments.'));

        $this->assertStringContainsString('RuntimeException: Invalid Arguments.', $data['message']);
    }

    public function testJsonFormatLevelFiltering(): void
    {
        $output = m::mock(ConsoleOutput::class);
        $output->shouldNotReceive('writeln');
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::ERROR], 'format' => 'json']],
        ]), $output);

        $logger->info('This should not be logged.');
    }

    public function testLineFormatSafelyStringifiesArrayAndResourceContext(): void
    {
        $stream = fopen('php://temp', 'r+');

        try {
            $output = new BufferedOutput;
            $logger = new StdoutLogger(new Repository([
                'app' => ['stdout_log' => ['level' => [LogLevel::INFO], 'format' => 'line']],
            ]), $output);

            $logger->info('Values: {array} {resource}.', [
                'array' => ['first', 'second'],
                'resource' => $stream,
            ]);

            $this->assertStringContainsString('Values: <ARRAY> <RESOURCE> stream.', $output->fetch());
        } finally {
            fclose($stream);
        }
    }

    public function testLineFormatPreservesLiteralMarkupAndPercentBearingTags(): void
    {
        $output = new BufferedOutput;
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::INFO], 'format' => 'line']],
        ]), $output);

        $logger->info('<error>Literal message</error>', [
            'component' => '<comment>worker %s</comment>',
        ]);

        $this->assertStringContainsString(
            '[<comment>worker %s</comment>] <error>Literal message</error>',
            $output->fetch(),
        );
    }

    public function testThrowingStringableDoesNotMaskLineLog(): void
    {
        $output = new BufferedOutput;
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::INFO], 'format' => 'line']],
        ]), $output);

        $logger->info('Value: {value}.', ['value' => new ThrowingStdoutLoggerStringable]);

        $this->assertStringContainsString(
            'Value: <OBJECT> Hypervel\Tests\Core\ThrowingStdoutLoggerStringable.',
            $output->fetch(),
        );
    }

    public function testDateTimeContextUsesRfc3339(): void
    {
        $date = new DateTimeImmutable('2026-07-20T12:34:56+00:00');

        $data = $this->logJson(LogLevel::INFO, 'At {date}.', ['date' => $date]);

        $this->assertSame('At 2026-07-20T12:34:56+00:00.', $data['message']);
        $this->assertSame('2026-07-20T12:34:56+00:00', $data['context']['date']);
    }

    public function testConfiguredCustomLevelIsSupported(): void
    {
        $output = new BufferedOutput;
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => ['audit'], 'format' => 'line']],
        ]), $output);

        $logger->log('audit', 'Recorded.');

        $this->assertStringContainsString('[AUDIT] Recorded.', $output->fetch());
    }

    public function testUnknownLevelThrowsPsrException(): void
    {
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [], 'format' => 'line']],
        ]), new BufferedOutput);

        $this->expectException(PsrInvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown log level [audit].');

        $logger->log('audit', 'Not configured.');
    }

    public function testNonStringLevelThrowsPsrException(): void
    {
        $logger = new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [], 'format' => 'line']],
        ]), new BufferedOutput);

        $this->expectException(PsrInvalidArgumentException::class);
        $this->expectExceptionMessage('Log level must be a string, int given.');

        $logger->log(123, 'Invalid.');
    }

    public function testInvalidFormatFailsDuringConfigurationLoad(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported stdout log format [xml].');

        new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::INFO], 'format' => 'xml']],
        ]), new BufferedOutput);
    }

    public function testNonStringConfiguredLevelFailsDuringConfigurationLoad(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stdout log levels must be strings, int given.');

        new StdoutLogger(new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::INFO, 1], 'format' => 'line']],
        ]), new BufferedOutput);
    }

    public function testFailedReloadPreservesThePreviousConfiguration(): void
    {
        $config = new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::ERROR], 'format' => 'line']],
        ]);
        $output = new BufferedOutput;
        $logger = new StdoutLogger($config, $output);

        $config->set('app.stdout_log.format', 'json');
        $config->set('app.stdout_log.level', [LogLevel::INFO, 1]);

        try {
            $logger->reloadConfiguration();
            $this->fail('The invalid level should prevent the configuration from being published.');
        } catch (InvalidArgumentException) {
        }

        $logger->error('Still using the previous configuration.');
        $logger->info('Still disabled.');

        $captured = $output->fetch();
        $this->assertStringContainsString('Still using the previous configuration.', $captured);
        $this->assertStringNotContainsString('Still disabled.', $captured);
        $this->assertStringStartsWith('[', $captured);
    }

    public function testJsonOutputPreservesLiteralMarkup(): void
    {
        $data = $this->logJson(LogLevel::INFO, '<error>Literal message</error>');

        $this->assertSame('<error>Literal message</error>', $data['message']);
    }

    public function testJsonOutputHandlesResourceContext(): void
    {
        $stream = fopen('php://temp', 'r+');

        try {
            $data = $this->logJson(LogLevel::INFO, 'Stream {stream}.', ['stream' => $stream]);

            $this->assertSame('Stream <RESOURCE> stream.', $data['message']);
            $this->assertSame('<RESOURCE> stream', $data['context']['stream']);
        } finally {
            fclose($stream);
        }
    }

    public function testJsonOutputHandlesRecursiveContext(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        $data = $this->logJson(LogLevel::INFO, 'Recursive {value}.', ['value' => $recursive]);

        $this->assertSame('Recursive <ARRAY>.', $data['message']);
        $this->assertNull($data['context']['value']['self']);
    }

    public function testJsonOutputSubstitutesInvalidUtf8(): void
    {
        $data = $this->logJson(LogLevel::INFO, 'Invalid {value}.', ['value' => "\xB1\x31"]);

        $this->assertSame("Invalid \u{FFFD}1.", $data['message']);
        $this->assertSame("\u{FFFD}1", $data['context']['value']);
    }

    public function testJsonOutputDropsContextWhenNestedSerializerThrows(): void
    {
        $data = $this->logJson(LogLevel::INFO, 'Payload {payload}.', [
            'payload' => ['serializer' => new ThrowingStdoutLoggerJsonSerializable],
        ]);

        $this->assertSame('Payload <ARRAY>.', $data['message']);
        $this->assertArrayNotHasKey('context', $data);
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
            'app' => ['stdout_log' => ['level' => [LogLevel::INFO], 'format' => 'line']],
        ]), $output);
    }

    /**
     * Log a message in JSON format and return the decoded output.
     */
    protected function logJson(string $level, string|Stringable $message, array $context = []): array
    {
        $captured = null;
        $output = m::mock(ConsoleOutput::class);
        $output->shouldReceive('writeln')
            ->with(m::any(), OutputInterface::OUTPUT_RAW)
            ->once()
            ->andReturnUsing(function ($message) use (&$captured) {
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

class ThrowingStdoutLoggerStringable implements Stringable
{
    public function __toString(): string
    {
        throw new RuntimeException('String conversion failed.');
    }
}

class ThrowingStdoutLoggerJsonSerializable implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        throw new RuntimeException('JSON serialization failed.');
    }
}
