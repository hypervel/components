<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Log;

use Exception;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Hypervel\Support\Facades\Context;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Monolog\LogRecord;

/**
 * @internal
 * @coversNothing
 */
class ContextLoggingIntegrationTest extends TestCase
{
    public function testContextIsNotUsedAsMessageParameters()
    {
        $path = $this->app->storagePath() . '/logs/hypervel.log';
        file_put_contents($path, '');

        Context::add('name', 'James');

        Log::channel('single')->info('My name is {name}');
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertSame('testing.INFO: My name is {name}  {"name":"James"}', Str::trim($log));

        file_put_contents($path, '');
    }

    public function testUsesClosureForContextProcessor()
    {
        $path = $this->app->storagePath() . '/logs/hypervel.log';
        file_put_contents($path, '');

        $this->app->bind(
            ContextLogProcessorContract::class,
            fn () => function (LogRecord $record): LogRecord {
                $logChannel = Context::getHidden('log_channel_name');

                return $record->with(
                    context: array_merge(Context::all(), $record->context),
                    channel: $logChannel ?? $record->channel,
                );
            }
        );

        Context::addHidden('log_channel_name', 'closure-test');
        Context::add(['value_from_context' => 'hello']);

        Log::info('This is an info log.', ['value_from_log_info_context' => 'foo']);

        $log = Str::after(file_get_contents($path), '] ');
        $this->assertSame(
            'closure-test.INFO: This is an info log. {"value_from_context":"hello","value_from_log_info_context":"foo"}',
            Str::trim($log)
        );
        file_put_contents($path, '');
    }

    public function testCanRebindToSeparateClass()
    {
        TestAddContextProcessor::$wasConstructed = false;

        $path = $this->app->storagePath() . '/logs/hypervel.log';
        file_put_contents($path, '');

        $this->app->bind(ContextLogProcessorContract::class, TestAddContextProcessor::class);

        Context::add(['this-will-be-included' => false]);

        Log::info('This is an info log.', ['value_from_log_info_context' => 'foo']);
        $log = Str::after(file_get_contents($path), '] ');
        $this->assertSame(
            'testing.INFO: This is an info log. {"value_from_log_info_context":"foo","inside of TestAddContextProcessor":true}',
            Str::trim($log)
        );
        $this->assertTrue(TestAddContextProcessor::$wasConstructed);

        file_put_contents($path, '');
    }

    public function testItAddsContextToLoggedExceptions()
    {
        $path = $this->app->storagePath() . '/logs/hypervel.log';
        file_put_contents($path, '');
        Str::createUuidsUsingSequence(['550e8400-e29b-41d4-a716-446655440000']);

        Context::add('trace_id', (string) Str::uuid());
        Context::add('foo.bar', 123);
        Context::push('bar.baz', 456);
        Context::push('bar.baz', 789);

        $this->app[ExceptionHandler::class]->report(new Exception('Whoops!'));
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertStringEndsWith(' {"trace_id":"550e8400-e29b-41d4-a716-446655440000","foo.bar":123,"bar.baz":[456,789]}', Str::trim($log));

        file_put_contents($path, '');
        Str::createUuidsNormally();
    }

    public function testClosureBoundProcessorRunsOnceOnStackedLogger()
    {
        $invocationCount = 0;

        $this->app->bind(
            ContextLogProcessorContract::class,
            function () use (&$invocationCount) {
                return function (LogRecord $record) use (&$invocationCount): LogRecord {
                    ++$invocationCount;

                    return $record->with(extra: [
                        ...$record->extra,
                        'custom_processor' => true,
                    ]);
                };
            }
        );

        $config = $this->app->make('config');
        $config->set('logging.channels.stack_test_a', [
            'driver' => 'single',
            'path' => $this->app->storagePath() . '/logs/stack-a.log',
        ]);
        $config->set('logging.channels.stack_test_b', [
            'driver' => 'single',
            'path' => $this->app->storagePath() . '/logs/stack-b.log',
        ]);

        $manager = new \Hypervel\Log\LogManager($this->app);
        $stack = $manager->stack(['stack_test_a', 'stack_test_b']);
        $stack->info('test message');

        $this->assertSame(1, $invocationCount);
    }
}

class TestAddContextProcessor implements ContextLogProcessorContract
{
    public static bool $wasConstructed = false;

    public function __construct()
    {
        self::$wasConstructed = true;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: array_merge($record->context, ['inside of TestAddContextProcessor' => true]));
    }
}
