<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hypervel\Log\Context\ResolvedContextLogProcessor;
use Hypervel\Log\Logger;
use Hypervel\Log\LogManager;
use Hypervel\Testbench\TestCase;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\LogEntriesHandler;
use Monolog\Handler\NewRelicHandler;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\UidProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use ReflectionProperty;
use RuntimeException;
use Stringable;

class LogManagerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('logging.channels.single', [
            'driver' => 'single',
            'path' => __DIR__,
        ]);
    }

    public function testLogManagerCachesLoggerInstances()
    {
        $manager = new LogManager($this->app);

        $logger1 = $manager->channel('single')->getLogger();
        $logger2 = $manager->channel('single')->getLogger();

        $this->assertSame($logger1, $logger2);
    }

    public function testLogManagerGetDefaultDriver()
    {
        $manager = new LogManager($this->app);
        $this->app->make('config')
            ->set('logging.default', 'single');
        $this->assertEmpty($manager->getChannels());

        // we don't specify any channel name
        $manager->channel();
        $this->assertCount(1, $manager->getChannels());
        $this->assertEquals('single', $manager->getDefaultDriver());
    }

    public function testStackChannel()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');

        $config->set('logging.channels.stack', [
            'driver' => 'stack',
            'channels' => ['stderr', 'stdout'],
        ]);

        $config->set('logging.channels.stderr', [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'level' => 'notice',
            'with' => [
                'stream' => 'php://stderr',
                'bubble' => false,
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ]);

        $config->set('logging.channels.stdout', [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'level' => 'info',
            'with' => [
                'stream' => 'php://stdout',
                'bubble' => true,
            ],
        ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('stack');
        $handlers = $logger->getLogger()->getHandlers();

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertCount(2, $handlers);
        $this->assertInstanceOf(StreamHandler::class, $handlers[0]);
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);
        $this->assertInstanceOf(PsrLogMessageProcessor::class, $logger->getLogger()->getProcessors()[1]);
        $this->assertInstanceOf(StreamHandler::class, $handlers[1]);
        $this->assertEquals(Level::Notice, $handlers[0]->getLevel());
        $this->assertEquals(Level::Info, $handlers[1]->getLevel());
        $this->assertFalse($handlers[0]->getBubble());
        $this->assertTrue($handlers[1]->getBubble());
    }

    public function testParsingStackChannels()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');

        $config->set('logging.channels.stack', [
            'driver' => 'stack',
            'channels' => 'single, daily, stderr',
        ]);

        $config->set('logging.channels.daily', [
            'driver' => 'daily',
            'path' => __DIR__ . '/logs/hypervel.log',
        ]);

        $config->set('logging.channels.stderr', [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
        ]);

        $manager->channel('stack');

        $this->assertSame(
            array_keys($manager->getChannels()),
            ['single', 'daily', 'stderr', 'stack']
        );
    }

    public function testLogManagerCreatesConfiguredMonologHandler()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.channels.nonbubblingstream', [
            'driver' => 'monolog',
            'name' => 'foobar',
            'handler' => StreamHandler::class,
            'level' => 'notice',
            'with' => [
                'stream' => 'php://stderr',
                'bubble' => false,
            ],
        ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('nonbubblingstream');
        $handlers = $logger->getLogger()->getHandlers();

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame('foobar', $logger->getName());
        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(StreamHandler::class, $handlers[0]);
        $this->assertEquals(Level::Notice, $handlers[0]->getLevel());
        $this->assertFalse($handlers[0]->getBubble());

        $url = new ReflectionProperty(get_class($handlers[0]), 'url');
        $this->assertSame('php://stderr', $url->getValue($handlers[0]));

        $config->set('logging.channels.logentries', [
            'driver' => 'monolog',
            'name' => 'le',
            'handler' => LogEntriesHandler::class,
            'with' => [
                'token' => '123456789',
            ],
        ]);

        $logger = $manager->channel('logentries');
        $handlers = $logger->getLogger()->getHandlers();

        $logToken = new ReflectionProperty(get_class($handlers[0]), 'logToken');

        $this->assertInstanceOf(LogEntriesHandler::class, $handlers[0]);
        $this->assertSame('123456789', $logToken->getValue($handlers[0]));
    }

    public function testLogManagerCreatesMonologHandlerWithConfiguredFormatter()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.channels.newrelic', [
            'driver' => 'monolog',
            'name' => 'nr',
            'handler' => NewRelicHandler::class,
            'formatter' => 'default',
        ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('newrelic');
        $handler = $logger->getLogger()->getHandlers()[0];

        $this->assertInstanceOf(NewRelicHandler::class, $handler);
        $this->assertInstanceOf(NormalizerFormatter::class, $handler->getFormatter());

        $config->set('logging.channels.newrelic2', [
            'driver' => 'monolog',
            'name' => 'nr',
            'handler' => NewRelicHandler::class,
            'formatter' => HtmlFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y/m/d--test',
            ],
        ]);

        $logger = $manager->channel('newrelic2');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(NewRelicHandler::class, $handler);
        $this->assertInstanceOf(HtmlFormatter::class, $formatter);

        $dateFormat = new ReflectionProperty(get_class($formatter), 'dateFormat');

        $this->assertSame('Y/m/d--test', $dateFormat->getValue($formatter));
    }

    public function testLogManagerCreatesMonologHandlerWithProperFormatter()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
            'formatter' => HtmlFormatter::class,
        ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('null');
        $handler = $logger->getLogger()->getHandlers()[0];

        $this->assertInstanceOf(NullHandler::class, $handler);

        $config->set('logging.channels.null2', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);

        $logger = $manager->channel('null2');
        $handler = $logger->getLogger()->getHandlers()[0];

        $this->assertInstanceOf(NullHandler::class, $handler);
    }

    public function testLogManagerCreatesMonologHandlerWithProcessors()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.channels.memory', [
            'driver' => 'monolog',
            'name' => 'memory',
            'handler' => StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [
                MemoryUsageProcessor::class,
                ['processor' => PsrLogMessageProcessor::class, 'with' => ['removeUsedContextFields' => true]],
            ],
        ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('memory');
        $handler = $logger->getLogger()->getHandlers()[0];
        $processors = $logger->getLogger()->getProcessors();

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $processors[0]);
        $this->assertInstanceOf(MemoryUsageProcessor::class, $processors[1]);
        $this->assertInstanceOf(PsrLogMessageProcessor::class, $processors[2]);

        $removeUsedContextFields = new ReflectionProperty(get_class($processors[2]), 'removeUsedContextFields');

        $this->assertTrue($removeUsedContextFields->getValue($processors[2]));
    }

    public function testItUtilisesTheNullDriverDuringTestsWhenNullDriverUsed()
    {
        $manager = new class($this->app) extends LogManager {
            protected function createEmergencyLogger(): LoggerInterface
            {
                throw new RuntimeException('Emergency logger was created.');
            }
        };

        $this->app['env'] = 'testing';
        $config = $this->app->make('config');
        $config->set('logging.default', null);
        $config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);

        // In tests, this should not need to create the emergency logger...
        $manager->info('message');

        // we should also be able to forget the null channel...
        $this->assertCount(1, $manager->getChannels());
        $manager->forgetChannel();
        $this->assertCount(0, $manager->getChannels());

        // However in production we want it to fallback to the emergency logger...
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Emergency logger was created.');

        $this->app['env'] = 'production';
        $manager->info('message');
    }

    public function testLogManagerCreateSingleDriverWithConfiguredFormatter()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.channels.defaultsingle', [
            'driver' => 'single',
            'name' => 'ds',
            'path' => $path = __DIR__ . '/logs/hypervel.log',
            'replace_placeholders' => true,
        ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('defaultsingle');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertInstanceOf(LineFormatter::class, $formatter);
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);
        $this->assertInstanceOf(PsrLogMessageProcessor::class, $logger->getLogger()->getProcessors()[1]);

        $config->set('logging.channels.formattedsingle', [
            'driver' => 'single',
            'name' => 'fs',
            'path' => $path,
            'formatter' => HtmlFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y/m/d--test',
            ],
            'replace_placeholders' => false,
        ]);

        $logger = $manager->channel('formattedsingle');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertInstanceOf(HtmlFormatter::class, $formatter);
        $this->assertCount(1, $logger->getLogger()->getProcessors());
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);

        $dateFormat = new ReflectionProperty(get_class($formatter), 'dateFormat');

        $this->assertSame('Y/m/d--test', $dateFormat->getValue($formatter));
    }

    public function testLogManagerCreateDailyDriverWithConfiguredFormatter()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.channels.defaultdaily', [
            'driver' => 'daily',
            'name' => 'dd',
            'path' => $path = __DIR__ . '/logs/hypervel.log',
            'replace_placeholders' => true,
        ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('defaultdaily');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertInstanceOf(LineFormatter::class, $formatter);
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);
        $this->assertInstanceOf(PsrLogMessageProcessor::class, $logger->getLogger()->getProcessors()[1]);

        $config->set('logging.channels.formatteddaily', [
            'driver' => 'daily',
            'name' => 'fd',
            'path' => $path,
            'formatter' => HtmlFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y/m/d--test',
            ],
            'replace_placeholders' => false,
        ]);

        $logger = $manager->channel('formatteddaily');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertInstanceOf(HtmlFormatter::class, $formatter);
        $this->assertCount(1, $logger->getLogger()->getProcessors());
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);

        $dateFormat = new ReflectionProperty(get_class($formatter), 'dateFormat');

        $this->assertSame('Y/m/d--test', $dateFormat->getValue($formatter));
    }

    public function testLogManagerCreateSyslogDriverWithConfiguredFormatter()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.channels.defaultsyslog', [
            'driver' => 'syslog',
            'name' => 'ds',
            'replace_placeholders' => true,
        ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('defaultsyslog');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(SyslogHandler::class, $handler);
        $this->assertInstanceOf(LineFormatter::class, $formatter);
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);
        $this->assertInstanceOf(PsrLogMessageProcessor::class, $logger->getLogger()->getProcessors()[1]);

        $config->set('logging.channels.formattedsyslog', [
            'driver' => 'syslog',
            'name' => 'fs',
            'formatter' => HtmlFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y/m/d--test',
            ],
            'replace_placeholders' => false,
        ]);

        $logger = $manager->channel('formattedsyslog');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(SyslogHandler::class, $handler);
        $this->assertInstanceOf(HtmlFormatter::class, $formatter);
        $this->assertCount(1, $logger->getLogger()->getProcessors());
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);

        $dateFormat = new ReflectionProperty(get_class($formatter), 'dateFormat');

        $this->assertSame('Y/m/d--test', $dateFormat->getValue($formatter));
    }

    public function testLogManagerPurgeResolvedChannels()
    {
        $manager = new LogManager($this->app);

        $this->assertEmpty($manager->getChannels());

        $manager->channel('single')->getLogger();

        $this->assertCount(1, $manager->getChannels());

        $manager->forgetChannel('single');

        $this->assertEmpty($manager->getChannels());
    }

    public function testLogManagerCanBuildOnDemandChannel()
    {
        $manager = new LogManager($this->app);

        $logger = $manager->build([
            'driver' => 'single',
            'path' => $path = __DIR__ . '/logs/on-demand.log',
        ]);
        $handler = $logger->getLogger()->getHandlers()[0];

        $this->assertInstanceOf(StreamHandler::class, $handler);

        $url = new ReflectionProperty(get_class($handler), 'url');

        $this->assertSame($path, $url->getValue($handler));
    }

    public function testLogManagerCanUseOnDemandChannelInOnDemandStack()
    {
        $manager = new LogManager($this->app);
        $this->app->make('config')
            ->set('logging.channels.test', [
                'driver' => 'single',
                'path' => $path = __DIR__ . '/logs/custom.log',
            ]);

        $factory = new class {
            public function __invoke()
            {
                return new Monolog(
                    'uuid',
                    [new StreamHandler(__DIR__ . '/logs/custom.log')],
                    [new UidProcessor]
                );
            }
        };
        $channel = $manager->build([
            'driver' => 'custom',
            'via' => get_class($factory),
        ]);
        $logger = $manager->stack(['test', $channel]);

        $handler = $logger->getLogger()->getHandlers()[1];
        $processors = $logger->getLogger()->getProcessors();

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $processors[0]);
        $this->assertInstanceOf(UidProcessor::class, $processors[1]);

        $url = new ReflectionProperty(get_class($handler), 'url');

        $this->assertSame($path, $url->getValue($handler));
    }

    public function testWrappingHandlerInFingersCrossedWhenActionLevelIsUsed()
    {
        $manager = new LogManager($this->app);
        $this->app->make('config')
            ->set('logging.channels.fingerscrossed', [
                'driver' => 'monolog',
                'handler' => StreamHandler::class,
                'level' => 'debug',
                'action_level' => 'critical',
                'with' => [
                    'stream' => 'php://stderr',
                    'bubble' => false,
                ],
            ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('fingerscrossed');
        $handlers = $logger->getLogger()->getHandlers();

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertCount(1, $handlers);

        $expectedFingersCrossedHandler = $handlers[0];
        $this->assertInstanceOf(FingersCrossedHandler::class, $expectedFingersCrossedHandler);

        $activationStrategyProp = new ReflectionProperty(get_class($expectedFingersCrossedHandler), 'activationStrategy');
        $activationStrategyValue = $activationStrategyProp->getValue($expectedFingersCrossedHandler);

        $actionLevelProp = new ReflectionProperty(get_class($activationStrategyValue), 'actionLevel');
        $actionLevelValue = $actionLevelProp->getValue($activationStrategyValue);

        $this->assertEquals(Level::Critical, $actionLevelValue);

        if (method_exists($expectedFingersCrossedHandler, 'getHandler')) {
            $expectedStreamHandler = $expectedFingersCrossedHandler->getHandler();
        } else {
            $handlerProp = new ReflectionProperty(get_class($expectedFingersCrossedHandler), 'handler');
            $expectedStreamHandler = $handlerProp->getValue($expectedFingersCrossedHandler);
        }
        $this->assertInstanceOf(StreamHandler::class, $expectedStreamHandler);
        $this->assertEquals(Level::Debug, $expectedStreamHandler->getLevel());
    }

    public function testFingersCrossedHandlerStopsRecordBufferingAfterFirstFlushByDefault()
    {
        $manager = new LogManager($this->app);
        $this->app->make('config')
            ->set('logging.channels.fingerscrossed', [
                'driver' => 'monolog',
                'handler' => StreamHandler::class,
                'level' => 'debug',
                'action_level' => 'critical',
                'with' => [
                    'stream' => 'php://stderr',
                    'bubble' => false,
                ],
            ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('fingerscrossed');
        $handlers = $logger->getLogger()->getHandlers();

        $expectedFingersCrossedHandler = $handlers[0];

        $stopBufferingProp = new ReflectionProperty(get_class($expectedFingersCrossedHandler), 'stopBuffering');
        $stopBufferingValue = $stopBufferingProp->getValue($expectedFingersCrossedHandler);

        $this->assertTrue($stopBufferingValue);
    }

    public function testFingersCrossedHandlerCanBeConfiguredToResumeBufferingAfterFlushing()
    {
        $manager = new LogManager($this->app);
        $this->app->make('config')
            ->set('logging.channels.fingerscrossed', [
                'driver' => 'monolog',
                'handler' => StreamHandler::class,
                'level' => 'debug',
                'action_level' => 'critical',
                'stop_buffering' => false,
                'with' => [
                    'stream' => 'php://stderr',
                    'bubble' => false,
                ],
            ]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('fingerscrossed');
        $handlers = $logger->getLogger()->getHandlers();

        $expectedFingersCrossedHandler = $handlers[0];

        $stopBufferingProp = new ReflectionProperty(get_class($expectedFingersCrossedHandler), 'stopBuffering');
        $stopBufferingValue = $stopBufferingProp->getValue($expectedFingersCrossedHandler);

        $this->assertFalse($stopBufferingValue);
    }

    public function testItSharesContextWithAlreadyResolvedChannels()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.default', null);
        $config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);
        $channel = $manager->channel('null');
        $context = null;

        $channel->listen(function ($message) use (&$context) {
            $context = $message->context;
        });
        $manager->shareContext([
            'invocation-id' => 'expected-id',
        ]);
        $channel->info('xxxx');

        $this->assertSame(['invocation-id' => 'expected-id'], $context);
    }

    public function testItSharesContextWithFreshlyResolvedChannels()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.default', null);
        $config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);
        $channel = $manager->channel('null');
        $context = null;

        $manager->shareContext([
            'invocation-id' => 'expected-id',
        ]);
        $manager->channel('null')->listen(function ($message) use (&$context) {
            $context = $message->context;
        });
        $manager->channel('null')->info('xxxx');

        $this->assertSame(['invocation-id' => 'expected-id'], $context);
    }

    public function testContextCanBePubliclyAccessedByOtherLoggingSystems()
    {
        $manager = new LogManager($this->app);
        $manager->shareContext([
            'invocation-id' => 'expected-id',
        ]);

        $this->assertSame($manager->sharedContext(), ['invocation-id' => 'expected-id']);
    }

    public function testItSharesContextWithStacksWhenTheyAreResolved()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.default', null);
        $config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);
        $channel = $manager->channel('null');
        $context = null;

        $manager->shareContext([
            'invocation-id' => 'expected-id',
        ]);
        $stack = $manager->stack(['null']);
        $stack->listen(function ($message) use (&$context) {
            $context = $message->context;
        });
        $stack->info('xxxx');

        $this->assertSame(['invocation-id' => 'expected-id'], $context);
    }

    public function testItMergesSharedContextRatherThanReplacing()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.default', null);
        $config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);
        $channel = $manager->channel('null');
        $context = null;

        $manager->shareContext([
            'invocation-id' => 'expected-id',
        ]);
        $manager->shareContext([
            'invocation-start' => 1651800456,
        ]);
        $manager->channel('null')->listen(function ($message) use (&$context) {
            $context = $message->context;
        });
        $manager->channel('null')->info('xxxx', [
            'logged' => 'context',
        ]);

        $this->assertSame([
            'invocation-id' => 'expected-id',
            'invocation-start' => 1651800456,
            'logged' => 'context',
        ], $context);
        $this->assertSame([
            'invocation-id' => 'expected-id',
            'invocation-start' => 1651800456,
        ], $manager->sharedContext());
    }

    public function testFlushSharedContext()
    {
        $manager = new LogManager($this->app);

        $manager->shareContext($context = ['foo' => 'bar']);

        $this->assertSame($context, $manager->sharedContext());

        $manager->flushSharedContext();

        $this->assertEmpty($manager->sharedContext());
    }

    public function testLogManagerCreateCustomFormatterWithTap()
    {
        $manager = new LogManager($this->app);
        $this->app->make('config')
            ->set('logging.channels.custom', [
                'driver' => 'single',
                'tap' => [CustomizeFormatter::class],
                'path' => __DIR__ . '/logs/custom.log',
            ]);

        $logger = $manager->channel('custom');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(LineFormatter::class, $formatter);

        $format = new ReflectionProperty(get_class($formatter), 'format');

        $this->assertEquals(
            '[%datetime%] %channel%.%level_name%: %message% %context% %extra%',
            rtrim($format->getValue($formatter))
        );
    }

    public function testDriverUsersPsrLoggerManagerReturnsLogger()
    {
        $config = $this->app->make('config');
        $config->set('logging.channels.spy', [
            'driver' => 'spy',
        ]);

        $manager = new LogManager($this->app);

        $loggerSpy = new LoggerSpy;
        $manager->extend('spy', fn () => $loggerSpy);

        // When
        $logger = $manager->channel('spy');
        $logger->alert('some alert');

        // Then
        $this->assertCount(1, $loggerSpy->logs);
        $this->assertEquals('some alert', $loggerSpy->logs[0]['message']);
    }

    public function testCustomDriverClosureBoundObjectIsLogManager()
    {
        $config = $this->app->make('config');
        $config->set('logging.channels.' . __CLASS__, [
            'driver' => __CLASS__,
        ]);

        $manager = new LogManager($this->app);
        $manager->extend(__CLASS__, fn () => $this);
        $this->assertSame($manager, $manager->channel(__CLASS__)->getLogger());
    }

    // -- Hypervel-specific tests --

    public function testItSharesContextWithChannelsResolvedAfterSharing()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);

        // Share context BEFORE resolving any channel
        $manager->shareContext(['invocation-id' => 'expected-id']);

        $context = null;
        $manager->channel('null')->listen(function ($message) use (&$context) {
            $context = $message->context;
        });
        $manager->channel('null')->info('xxxx');

        $this->assertSame(['invocation-id' => 'expected-id'], $context);
    }

    public function testItSharesContextWithStacksResolvedAfterSharing()
    {
        $manager = new LogManager($this->app);
        $config = $this->app->make('config');
        $config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);

        // Share context BEFORE resolving any stack
        $manager->shareContext(['invocation-id' => 'expected-id']);

        $context = null;
        $stack = $manager->stack(['null']);
        $stack->listen(function ($message) use (&$context) {
            $context = $message->context;
        });
        $stack->info('xxxx');

        $this->assertSame(['invocation-id' => 'expected-id'], $context);
    }
}

class CustomizeFormatter
{
    public function __invoke($logger)
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new LineFormatter(
                '[%datetime%] %channel%.%level_name%: %message% %context% %extra%'
            ));
        }
    }
}

class LoggerSpy implements LoggerInterface
{
    use LoggerTrait;

    public array $logs = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}
