<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Log;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Log\Context\ResolvedContextLogProcessor;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Log\Handlers\FingersCrossedHandler as HypervelFingersCrossedHandler;
use Hypervel\Log\Handlers\RotatingFileHandler as HypervelRotatingFileHandler;
use Hypervel\Log\Handlers\StreamHandler as HypervelStreamHandler;
use Hypervel\Log\Logger;
use Hypervel\Log\LogManager;
use Hypervel\Log\Processors\UidProcessor as HypervelUidProcessor;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use ReflectionProperty;
use RuntimeException;
use Stringable;

class LogManagerTest extends TestCase
{
    protected string $logDirectory;

    /**
     * Configure the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->logDirectory = $app->storagePath('logs/log-manager');
        $app->make(Filesystem::class)->ensureDirectoryExists($this->logDirectory);

        $app->make('config')->set('logging.channels.single', [
            'driver' => 'single',
            'path' => $this->logDirectory . '/hypervel.log',
        ]);
    }

    /**
     * Remove the test's log files.
     */
    protected function tearDown(): void
    {
        $this->app->make(Filesystem::class)->deleteDirectory($this->logDirectory);

        parent::tearDown();
    }

    public function testLogManagerCachesLoggerInstances(): void
    {
        $manager = new LogManager($this->app);

        $logger1 = $manager->channel('single')->getLogger();
        $logger2 = $manager->channel('single')->getLogger();

        $this->assertSame($logger1, $logger2);
    }

    public function testLogManagerGetDefaultDriver(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.default' => 'single']);
        $this->assertEmpty($manager->getChannels());

        // we don't specify any channel name
        $manager->channel();
        $this->assertCount(1, $manager->getChannels());
        $this->assertSame('single', $manager->getDefaultDriver());
    }

    public function testStackChannel(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.stack' => [
            'driver' => 'stack',
            'channels' => ['stderr', 'stdout'],
        ]]);

        config(['logging.channels.stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'level' => 'notice',
            'with' => [
                'stream' => 'php://stderr',
                'bubble' => false,
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ]]);

        config(['logging.channels.stdout' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'level' => 'info',
            'with' => [
                'stream' => 'php://stdout',
                'bubble' => true,
            ],
        ]]);

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

    public function testOnDemandStackUsesItsConfiguredName(): void
    {
        $manager = new LogManager($this->app);

        $logger = $manager->stack([], 'audit');

        $this->assertSame('audit', $logger->getName());
    }

    public function testParsingStackChannels(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.stack' => [
            'driver' => 'stack',
            'channels' => 'single, daily, stderr',
        ]]);

        config(['logging.channels.daily' => [
            'driver' => 'daily',
            'path' => $this->logDirectory . '/hypervel.log',
        ]]);

        config(['logging.channels.stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
        ]]);

        $manager->channel('stack');

        $this->assertSame(
            array_keys($manager->getChannels()),
            ['single', 'daily', 'stderr', 'stack']
        );
    }

    public function testLogManagerCreatesConfiguredMonologHandler(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.nonbubblingstream' => [
            'driver' => 'monolog',
            'name' => 'foobar',
            'handler' => StreamHandler::class,
            'level' => 'notice',
            'with' => [
                'stream' => 'php://stderr',
                'bubble' => false,
            ],
        ]]);

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

        config(['logging.channels.logentries' => [
            'driver' => 'monolog',
            'name' => 'le',
            'handler' => LogEntriesHandler::class,
            'with' => [
                'token' => '123456789',
            ],
        ]]);

        $logger = $manager->channel('logentries');
        $handlers = $logger->getLogger()->getHandlers();

        $logToken = new ReflectionProperty(get_class($handlers[0]), 'logToken');

        $this->assertInstanceOf(LogEntriesHandler::class, $handlers[0]);
        $this->assertSame('123456789', $logToken->getValue($handlers[0]));
    }

    public function testLogManagerCreatesMonologHandlerWithConfiguredFormatter(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.newrelic' => [
            'driver' => 'monolog',
            'name' => 'nr',
            'handler' => NewRelicHandler::class,
            'formatter' => 'default',
        ]]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('newrelic');
        $handler = $logger->getLogger()->getHandlers()[0];

        $this->assertInstanceOf(NewRelicHandler::class, $handler);
        $this->assertInstanceOf(NormalizerFormatter::class, $handler->getFormatter());

        config(['logging.channels.newrelic2' => [
            'driver' => 'monolog',
            'name' => 'nr',
            'handler' => NewRelicHandler::class,
            'formatter' => HtmlFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y/m/d--test',
            ],
        ]]);

        $logger = $manager->channel('newrelic2');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(NewRelicHandler::class, $handler);
        $this->assertInstanceOf(HtmlFormatter::class, $formatter);

        $dateFormat = new ReflectionProperty(get_class($formatter), 'dateFormat');

        $this->assertSame('Y/m/d--test', $dateFormat->getValue($formatter));
    }

    public function testLogManagerCreatesMonologHandlerWithProperFormatter(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
            'formatter' => HtmlFormatter::class,
        ]]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('null');
        $handler = $logger->getLogger()->getHandlers()[0];

        $this->assertInstanceOf(NullHandler::class, $handler);

        config(['logging.channels.null2' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);

        $logger = $manager->channel('null2');
        $handler = $logger->getLogger()->getHandlers()[0];

        $this->assertInstanceOf(NullHandler::class, $handler);
    }

    public function testLogManagerCreatesMonologHandlerWithProcessors(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.memory' => [
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
        ]]);

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

    public function testExactVendorHandlersAndUidProcessorsUseCoroutineSafeImplementations(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.safe' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://memory'],
            'processors' => [
                ['processor' => UidProcessor::class, 'with' => ['length' => 16]],
            ],
        ]]);
        config(['logging.channels.custom' => [
            'driver' => 'monolog',
            'handler' => CustomStreamHandler::class,
            'with' => ['stream' => 'php://memory'],
            'processors' => [CustomUidProcessor::class],
        ]]);

        $safe = $manager->channel('safe')->getLogger();
        $custom = $manager->channel('custom')->getLogger();

        $this->assertSame(HypervelStreamHandler::class, get_class($safe->getHandlers()[0]));
        $this->assertSame(HypervelUidProcessor::class, get_class($safe->getProcessors()[1]));
        $this->assertSame(16, strlen($safe->getProcessors()[1]->getUid()));
        $this->assertSame(CustomStreamHandler::class, get_class($custom->getHandlers()[0]));
        $this->assertSame(CustomUidProcessor::class, get_class($custom->getProcessors()[1]));
    }

    public function testDailyDriverUsesCoroutineSafeRotatingHandler(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.daily-safe' => [
            'driver' => 'daily',
            'path' => $this->logDirectory . '/daily-safe.log',
        ]]);

        $handler = $manager->channel('daily-safe')->getLogger()->getHandlers()[0];

        $this->assertSame(HypervelRotatingFileHandler::class, get_class($handler));
    }

    public function testItUtilizesTheNullDriverDuringTestsWhenNullDriverUsed(): void
    {
        $manager = new class($this->app) extends LogManager {
            protected function createEmergencyLogger(): LoggerInterface
            {
                throw new RuntimeException('Emergency logger was created.');
            }
        };

        $this->app->instance('env', 'testing');
        config(['logging.default' => null]);
        config(['logging.channels.null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);

        // In tests, this should not need to create the emergency logger...
        $manager->info('message');

        // we should also be able to forget the null channel...
        $this->assertCount(1, $manager->getChannels());
        $manager->forgetChannel();
        $this->assertCount(0, $manager->getChannels());

        // However in production we want it to fallback to the emergency logger...
        $this->app->instance('env', 'production');
        try {
            $manager->info('message');

            $this->fail('Emergency logger was not created as expected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Emergency logger was created.', $exception->getMessage());
        }
    }

    public function testLogManagerCreateSingleDriverWithConfiguredFormatter(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.defaultsingle' => [
            'driver' => 'single',
            'name' => 'ds',
            'path' => $path = $this->logDirectory . '/hypervel.log',
            'replace_placeholders' => true,
        ]]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('defaultsingle');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertInstanceOf(LineFormatter::class, $formatter);
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);
        $this->assertInstanceOf(PsrLogMessageProcessor::class, $logger->getLogger()->getProcessors()[1]);

        config(['logging.channels.formattedsingle' => [
            'driver' => 'single',
            'name' => 'fs',
            'path' => $path,
            'formatter' => HtmlFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y/m/d--test',
            ],
            'replace_placeholders' => false,
        ]]);

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

    public function testLogManagerCreateDailyDriverWithConfiguredFormatter(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.defaultdaily' => [
            'driver' => 'daily',
            'name' => 'dd',
            'path' => $path = $this->logDirectory . '/hypervel.log',
            'replace_placeholders' => true,
        ]]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('defaultdaily');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(HypervelRotatingFileHandler::class, $handler);
        $this->assertInstanceOf(LineFormatter::class, $formatter);
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);
        $this->assertInstanceOf(PsrLogMessageProcessor::class, $logger->getLogger()->getProcessors()[1]);

        config(['logging.channels.formatteddaily' => [
            'driver' => 'daily',
            'name' => 'fd',
            'path' => $path,
            'formatter' => HtmlFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y/m/d--test',
            ],
            'replace_placeholders' => false,
        ]]);

        $logger = $manager->channel('formatteddaily');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(HypervelRotatingFileHandler::class, $handler);
        $this->assertInstanceOf(HtmlFormatter::class, $formatter);
        $this->assertCount(1, $logger->getLogger()->getProcessors());
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);

        $dateFormat = new ReflectionProperty(get_class($formatter), 'dateFormat');

        $this->assertSame('Y/m/d--test', $dateFormat->getValue($formatter));
    }

    #[DataProvider('rotatingFileDriverDataProvider')]
    public function testRotatingFileDriversLogToADateStampedFileAndPruneOldOnes(string $driver, string $dateFormat, array $staleDates): void
    {
        $stalePaths = array_map(fn (string $date): string => $this->logDirectory . "/rotating-{$driver}-{$date}.log", $staleDates);

        foreach ($stalePaths as $stalePath) {
            file_put_contents($stalePath, 'stale');
        }

        config(["logging.channels.rotating-{$driver}" => [
            'driver' => $driver,
            'path' => $this->logDirectory . "/rotating-{$driver}.log",
            'level' => 'warning',
            'max_files' => 3,
        ]]);

        $logger = (new LogManager($this->app))->channel("rotating-{$driver}");

        $logger->warning('Something went wrong');

        $handler = $logger->getLogger()->getHandlers()[0];

        $handler->close();

        $expectedPath = $this->logDirectory . "/rotating-{$driver}-" . date($dateFormat) . '.log';

        $this->assertInstanceOf(HypervelRotatingFileHandler::class, $handler);
        $this->assertSame(Level::Warning, $handler->getLevel());
        $this->assertFileExists($expectedPath);
        $this->assertSame($expectedPath, $handler->getUrl());
        $this->assertStringContainsString('WARNING: Something went wrong', file_get_contents($expectedPath));

        // max_files = 3, the oldest 4th file was deleted
        $this->assertFileDoesNotExist($stalePaths[0]);
        $this->assertFileExists($stalePaths[1]);
        $this->assertFileExists($stalePaths[2]);
    }

    /**
     * Provide rotating log drivers and their stale files.
     */
    public static function rotatingFileDriverDataProvider(): array
    {
        return [
            'daily' => ['daily', 'Y-m-d', ['2026-01-01', '2026-01-02', '2026-01-03']],
            'monthly' => ['monthly', 'Y-m', ['2026-01', '2026-02', '2026-03']],
        ];
    }

    public function testLogManagerCreateSyslogDriverWithConfiguredFormatter(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.defaultsyslog' => [
            'driver' => 'syslog',
            'name' => 'ds',
            'replace_placeholders' => true,
        ]]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('defaultsyslog');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(SyslogHandler::class, $handler);
        $this->assertInstanceOf(LineFormatter::class, $formatter);
        $this->assertInstanceOf(ResolvedContextLogProcessor::class, $logger->getLogger()->getProcessors()[0]);
        $this->assertInstanceOf(PsrLogMessageProcessor::class, $logger->getLogger()->getProcessors()[1]);

        config(['logging.channels.formattedsyslog' => [
            'driver' => 'syslog',
            'name' => 'fs',
            'formatter' => HtmlFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y/m/d--test',
            ],
            'replace_placeholders' => false,
        ]]);

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

    public function testLogManagerPurgeResolvedChannels(): void
    {
        $manager = new LogManager($this->app);

        $this->assertEmpty($manager->getChannels());

        $manager->channel('single')->getLogger();

        $this->assertCount(1, $manager->getChannels());

        $manager->forgetChannel('single');

        $this->assertEmpty($manager->getChannels());
    }

    public function testLogManagerCanBuildOnDemandChannel(): void
    {
        $manager = new LogManager($this->app);

        $logger = $manager->build([
            'driver' => 'single',
            'path' => $path = $this->logDirectory . '/on-demand.log',
        ]);
        $handler = $logger->getLogger()->getHandlers()[0];

        $this->assertInstanceOf(StreamHandler::class, $handler);

        $url = new ReflectionProperty(get_class($handler), 'url');

        $this->assertSame($path, $url->getValue($handler));
    }

    public function testOnDemandChannelsAreUncachedAndUseTheirRuntimeTaps(): void
    {
        $manager = new LogManager($this->app);
        $config = [
            'driver' => 'single',
            'tap' => [CustomizeFormatter::class],
            'path' => $this->logDirectory . '/on-demand-tapped.log',
        ];

        $first = $manager->build($config);
        $second = $manager->build($config);
        $format = new ReflectionProperty(
            get_class($first->getLogger()->getHandlers()[0]->getFormatter()),
            'format'
        );

        $this->assertNotSame($first, $second);
        $this->assertSame([], $manager->getChannels());
        $this->assertSame(
            '[%datetime%] %channel%.%level_name%: %message% %context% %extra%',
            rtrim($format->getValue($first->getLogger()->getHandlers()[0]->getFormatter()))
        );

        $manager->shareContext(['request_id' => 'later']);

        $this->assertSame([], $first->getContext());
    }

    public function testLogManagerCanUseOnDemandChannelInOnDemandStack(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.test' => [
            'driver' => 'single',
            'path' => $path = $this->logDirectory . '/custom.log',
        ]]);

        $factory = new class {
            /**
             * Create the logger.
             */
            public function __invoke(): Monolog
            {
                return new Monolog(
                    'uuid',
                    [new StreamHandler(storage_path('logs/log-manager/custom.log'))],
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

    public function testLogManagerCanSetChannelNameForOnDemandStack(): void
    {
        $manager = new LogManager($this->app);

        $logger = $manager->stack(['single'], 'custom');

        $this->assertSame('custom', $logger->getName());
    }

    public function testWrappingHandlerInFingersCrossedWhenActionLevelIsUsed(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.fingerscrossed' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'level' => 'debug',
            'action_level' => 'critical',
            'with' => [
                'stream' => 'php://stderr',
                'bubble' => false,
            ],
        ]]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('fingerscrossed');
        $handlers = $logger->getLogger()->getHandlers();

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertCount(1, $handlers);

        $expectedFingersCrossedHandler = $handlers[0];
        $this->assertInstanceOf(FingersCrossedHandler::class, $expectedFingersCrossedHandler);
        $this->assertSame(HypervelFingersCrossedHandler::class, get_class($expectedFingersCrossedHandler));

        $activationStrategyProp = new ReflectionProperty(get_class($expectedFingersCrossedHandler), 'activationStrategy');
        $activationStrategyValue = $activationStrategyProp->getValue($expectedFingersCrossedHandler);

        $actionLevelProp = new ReflectionProperty(get_class($activationStrategyValue), 'actionLevel');
        $actionLevelValue = $actionLevelProp->getValue($activationStrategyValue);

        $this->assertEquals(Level::Critical, $actionLevelValue);

        $expectedStreamHandler = $expectedFingersCrossedHandler->getHandler();
        $this->assertInstanceOf(StreamHandler::class, $expectedStreamHandler);
        $this->assertEquals(Level::Debug, $expectedStreamHandler->getLevel());
    }

    public function testFingersCrossedHandlerStopsRecordBufferingAfterFirstFlushByDefault(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.fingerscrossed' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'level' => 'debug',
            'action_level' => 'critical',
            'with' => [
                'stream' => 'php://stderr',
                'bubble' => false,
            ],
        ]]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('fingerscrossed');
        $handlers = $logger->getLogger()->getHandlers();

        $expectedFingersCrossedHandler = $handlers[0];

        $stopBufferingProp = new ReflectionProperty(get_class($expectedFingersCrossedHandler), 'stopBuffering');
        $stopBufferingValue = $stopBufferingProp->getValue($expectedFingersCrossedHandler);

        $this->assertTrue($stopBufferingValue);
    }

    public function testFingersCrossedHandlerCanBeConfiguredToResumeBufferingAfterFlushing(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.fingerscrossed' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'level' => 'debug',
            'action_level' => 'critical',
            'stop_buffering' => false,
            'with' => [
                'stream' => 'php://stderr',
                'bubble' => false,
            ],
        ]]);

        // create logger with handler specified from configuration
        $logger = $manager->channel('fingerscrossed');
        $handlers = $logger->getLogger()->getHandlers();

        $expectedFingersCrossedHandler = $handlers[0];

        $stopBufferingProp = new ReflectionProperty(get_class($expectedFingersCrossedHandler), 'stopBuffering');
        $stopBufferingValue = $stopBufferingProp->getValue($expectedFingersCrossedHandler);

        $this->assertFalse($stopBufferingValue);
    }

    public function testItSharesContextWithAlreadyResolvedChannels(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.default' => null]);
        config(['logging.channels.null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);
        $channel = $manager->channel('null');
        $context = null;

        $channel->listen(function (MessageLogged $message) use (&$context): void {
            $context = $message->context;
        });
        $manager->shareContext([
            'invocation-id' => 'expected-id',
        ]);
        $channel->info('xxxx');

        $this->assertSame(['invocation-id' => 'expected-id'], $context);
    }

    public function testItSharesContextWithFreshlyResolvedChannels(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.default' => null]);
        config(['logging.channels.null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);
        $channel = $manager->channel('null');
        $context = null;

        $manager->shareContext([
            'invocation-id' => 'expected-id',
        ]);
        $manager->channel('null')->listen(function (MessageLogged $message) use (&$context): void {
            $context = $message->context;
        });
        $manager->channel('null')->info('xxxx');

        $this->assertSame(['invocation-id' => 'expected-id'], $context);
    }

    public function testContextCanBePubliclyAccessedByOtherLoggingSystems(): void
    {
        $manager = new LogManager($this->app);
        $manager->shareContext([
            'invocation-id' => 'expected-id',
        ]);

        $this->assertSame($manager->sharedContext(), ['invocation-id' => 'expected-id']);
    }

    public function testItSharesContextWithStacksWhenTheyAreResolved(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.default' => null]);
        config(['logging.channels.null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);
        $channel = $manager->channel('null');
        $context = null;

        $manager->shareContext([
            'invocation-id' => 'expected-id',
        ]);
        $stack = $manager->stack(['null']);
        $stack->listen(function (MessageLogged $message) use (&$context): void {
            $context = $message->context;
        });
        $stack->info('xxxx');

        $this->assertSame(['invocation-id' => 'expected-id'], $context);
    }

    public function testItMergesSharedContextRatherThanReplacing(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.default' => null]);
        config(['logging.channels.null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);
        $channel = $manager->channel('null');
        $context = null;

        $manager->shareContext([
            'invocation-id' => 'expected-id',
        ]);
        $manager->shareContext([
            'invocation-start' => 1651800456,
        ]);
        $manager->channel('null')->listen(function (MessageLogged $message) use (&$context): void {
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

    public function testSharedContextPreservesNumericKeys(): void
    {
        config(['logging.channels.first' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ], 'logging.channels.second' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);
        $manager = new LogManager($this->app);
        $first = $manager->channel('first');

        $manager->shareContext(['123' => 'first', '456' => 'kept']);
        $manager->shareContext(['123' => 'updated']);

        $this->assertSame([123 => 'updated', 456 => 'kept'], $manager->sharedContext());
        $this->assertSame([123 => 'updated', 456 => 'kept'], $first->getContext());
        $this->assertSame([123 => 'updated', 456 => 'kept'], $manager->channel('second')->getContext());
    }

    public function testFlushSharedContext(): void
    {
        $manager = new LogManager($this->app);

        $manager->shareContext($context = ['foo' => 'bar']);

        $this->assertSame($context, $manager->sharedContext());

        $manager->flushSharedContext();

        $this->assertEmpty($manager->sharedContext());
    }

    public function testLogManagerCreateCustomFormatterWithTap(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.custom' => [
            'driver' => 'single',
            'tap' => [CustomizeFormatter::class],
            'path' => $this->logDirectory . '/custom.log',
        ]]);

        $logger = $manager->channel('custom');
        $handler = $logger->getLogger()->getHandlers()[0];
        $formatter = $handler->getFormatter();

        $this->assertInstanceOf(LineFormatter::class, $formatter);

        $format = new ReflectionProperty(get_class($formatter), 'format');

        $this->assertSame(
            '[%datetime%] %channel%.%level_name%: %message% %context% %extra%',
            rtrim($format->getValue($formatter))
        );
    }

    public function testDriverUsersPsrLoggerManagerReturnsLogger(): void
    {
        config(['logging.channels.spy' => [
            'driver' => 'spy',
        ]]);

        $manager = new LogManager($this->app);

        $loggerSpy = new LoggerSpy;
        $manager->extend('spy', fn (): LoggerSpy => $loggerSpy);

        // When
        $logger = $manager->channel('spy');
        $logger->alert('some alert');

        // Then
        $this->assertCount(1, $loggerSpy->logs);
        $this->assertSame('some alert', $loggerSpy->logs[0]['message']);
    }

    public function testCustomDriverClosureBoundObjectIsLogManager(): void
    {
        config(['logging.channels.' . __CLASS__ => [
            'driver' => __CLASS__,
        ]]);

        $manager = new LogManager($this->app);
        $manager->extend(__CLASS__, fn (): LogManager => $this);
        $this->assertSame($manager, $manager->channel(__CLASS__)->getLogger());
    }

    public function testCustomDriverAcceptsStaticAnonymousClosure(): void
    {
        config(['logging.channels.static' => ['driver' => 'static']]);
        $manager = new LogManager($this->app);
        $logger = new LoggerSpy;

        $manager->extend('static', static fn (): LoggerSpy => $logger);

        $this->assertSame($logger, $manager->channel('static')->getLogger());
    }

    public function testCustomDriverAcceptsFirstClassCallable(): void
    {
        config(['logging.channels.callable' => ['driver' => 'callable']]);
        $manager = new LogManager($this->app);
        $logger = new LoggerSpy;
        $factory = new LogCreator($logger);

        $manager->extend('callable', $factory->create(...));

        $this->assertSame($logger, $manager->channel('callable')->getLogger());
    }

    public function testLogManagerCanResolveBackedEnumChannel(): void
    {
        $manager = new LogManager($this->app);

        $logger1 = $manager->channel(LogChannelName::Single);
        $logger2 = $manager->channel('single');

        $this->assertSame($logger1, $logger2);
    }

    public function testLogManagerCanResolveUnitEnumChannel(): void
    {
        $manager = new LogManager($this->app);

        $logger1 = $manager->channel(UnitLogChannelName::single);
        $logger2 = $manager->channel('single');

        $this->assertSame($logger1, $logger2);
    }

    public function testLogManagerCanResolveZeroBackedEnumChannel(): void
    {
        config(['logging.channels.0' => config('logging.channels.single')]);

        $manager = new LogManager($this->app);

        $logger1 = $manager->channel(NumericLogChannelName::Zero);
        $logger2 = $manager->channel('0');

        $this->assertSame($logger1, $logger2);

        $manager->forgetChannel(NumericLogChannelName::Zero);

        $this->assertNotSame($logger1, $manager->channel('0'));
    }

    public function testLogManagerCanResolveBackedEnumDriver(): void
    {
        $manager = new LogManager($this->app);

        $logger1 = $manager->driver(LogChannelName::Single);
        $logger2 = $manager->driver('single');

        $this->assertSame($logger1, $logger2);
    }

    public function testSetDefaultDriverAcceptsBackedEnum(): void
    {
        $manager = new LogManager($this->app);
        $manager->setDefaultDriver(LogChannelName::Single);

        $this->assertSame('single', config('logging.default'));
    }

    public function testForgetChannelAcceptsBackedEnum(): void
    {
        $manager = new LogManager($this->app);
        $logger = $manager->channel(LogChannelName::Single);

        $this->assertSame($logger, $manager->channel('single'));

        $manager->forgetChannel(LogChannelName::Single);

        $this->assertNotSame($logger, $manager->channel('single'));
    }

    // -- Hypervel-specific tests --

    public function testItSharesContextWithChannelsResolvedAfterSharing(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);

        // Share context BEFORE resolving any channel
        $manager->shareContext(['invocation-id' => 'expected-id']);

        $context = null;
        $manager->channel('null')->listen(function (MessageLogged $message) use (&$context): void {
            $context = $message->context;
        });
        $manager->channel('null')->info('xxxx');

        $this->assertSame(['invocation-id' => 'expected-id'], $context);
    }

    public function testItSharesContextWithStacksResolvedAfterSharing(): void
    {
        $manager = new LogManager($this->app);
        config(['logging.channels.null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);

        // Share context BEFORE resolving any stack
        $manager->shareContext(['invocation-id' => 'expected-id']);

        $context = null;
        $stack = $manager->stack(['null']);
        $stack->listen(function (MessageLogged $message) use (&$context): void {
            $context = $message->context;
        });
        $stack->info('xxxx');

        $this->assertSame(['invocation-id' => 'expected-id'], $context);
    }
}

class CustomizeFormatter
{
    /**
     * Customize the logger's formatters.
     */
    public function __invoke(Logger $logger): void
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

    /**
     * Record the log message.
     */
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}

class CustomStreamHandler extends StreamHandler
{
}

class CustomUidProcessor extends UidProcessor
{
}

class LogCreator
{
    /**
     * Create the logger factory.
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Return the logger.
     */
    public function create(): LoggerInterface
    {
        return $this->logger;
    }
}

enum LogChannelName: string
{
    case Single = 'single';
}

enum UnitLogChannelName
{
    case single;
}

enum NumericLogChannelName: int
{
    case Zero = 0;
}
