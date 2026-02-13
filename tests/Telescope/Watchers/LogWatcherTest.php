<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\LogWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @internal
 * @coversNothing
 */
class LogWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config = match ($this->name()) {
            'testLogWatcherRegistersEntryForAnyLevelByDefault' => true,
            'testLogWatcherOnlyRegistersEntriesForTheSpecifiedErrorLevelPriority' => [
                'enabled' => true,
                'level' => 'error',
            ],
            'testLogWatcherOnlyRegistersEntriesForTheSpecifiedDebugLevelPriority' => [
                'level' => 'debug',
            ],
            'testLogWatcherDoNotRegistersRetryWhenDisabledOnTheBooleanFormat' => false,
            'testLogWatcherDoNotRegistersRetryWhenDisabledOnTheArrayFormat' => [
                'enabled' => false,
                'level' => 'error',
            ],
            'testLogWatcherRegistersRetryWithExceptionKey' => true,
        };

        $this->app->make('config')
            ->set('telescope.watchers', [
                LogWatcher::class => $config,
            ]);
        $this->app->make('config')
            ->set('logging.default', 'null');

        $this->startTelescope();
    }

    /**
     * @dataProvider logLevelProvider
     */
    public function testLogWatcherRegistersEntryForAnyLevelByDefault(string $level)
    {
        $logger = $this->app->make(LoggerInterface::class);

        $logger->{$level}("Logging Level [{$level}].", [
            'user' => 'Claire Redfield',
            'role' => 'Zombie Hunter',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::LOG, $entry->type);
        $this->assertSame($level, $entry->content['level']);
        $this->assertSame("Logging Level [{$level}].", $entry->content['message']);
        $this->assertSame('Claire Redfield', $entry->content['context']['user']);
        $this->assertSame('Zombie Hunter', $entry->content['context']['role']);
    }

    /**
     * @dataProvider logLevelProvider
     */
    public function testLogWatcherOnlyRegistersEntriesForTheSpecifiedErrorLevelPriority(string $level)
    {
        $logger = $this->app->make(LoggerInterface::class);

        $logger->{$level}("Logging Level [{$level}].", [
            'user' => 'Claire Redfield',
            'role' => 'Zombie Hunter',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        if (in_array($level, [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR])) {
            $this->assertSame(EntryType::LOG, $entry->type);
            $this->assertSame($level, $entry->content['level']);
            $this->assertSame("Logging Level [{$level}].", $entry->content['message']);
            $this->assertSame('Claire Redfield', $entry->content['context']['user']);
            $this->assertSame('Zombie Hunter', $entry->content['context']['role']);
        } else {
            $this->assertNull($entry);
        }
    }

    /**
     * @dataProvider logLevelProvider
     */
    public function testLogWatcherOnlyRegistersEntriesForTheSpecifiedDebugLevelPriority(string $level)
    {
        $logger = $this->app->make(LoggerInterface::class);

        $logger->{$level}("Logging Level [{$level}].", [
            'user' => 'Claire Redfield',
            'role' => 'Zombie Hunter',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::LOG, $entry->type);
        $this->assertSame($level, $entry->content['level']);
        $this->assertSame("Logging Level [{$level}].", $entry->content['message']);
        $this->assertSame('Claire Redfield', $entry->content['context']['user']);
        $this->assertSame('Zombie Hunter', $entry->content['context']['role']);
    }

    /**
     * @dataProvider logLevelProvider
     */
    public function testLogWatcherDoNotRegistersRetryWhenDisabledOnTheBooleanFormat(string $level)
    {
        $logger = $this->app->make(LoggerInterface::class);

        $logger->{$level}("Logging Level [{$level}].", [
            'user' => 'Claire Redfield',
            'role' => 'Zombie Hunter',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNull($entry);
    }

    /**
     * @dataProvider logLevelProvider
     */
    public function testLogWatcherDoNotRegistersRetryWhenDisabledOnTheArrayFormat(string $level)
    {
        $logger = $this->app->make(LoggerInterface::class);

        $logger->{$level}("Logging Level [{$level}].", [
            'user' => 'Claire Redfield',
            'role' => 'Zombie Hunter',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNull($entry);
    }

    public static function logLevelProvider()
    {
        return [
            [LogLevel::EMERGENCY],
            [LogLevel::ALERT],
            [LogLevel::CRITICAL],
            [LogLevel::ERROR],
            [LogLevel::WARNING],
            [LogLevel::NOTICE],
            [LogLevel::INFO],
            [LogLevel::DEBUG],
        ];
    }

    public function testLogWatcherRegistersRetryWithExceptionKey()
    {
        $logger = $this->app->make(LoggerInterface::class);

        $logger->error('Some message', [
            'exception' => 'Some error message',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::LOG, $entry->type);
        $this->assertSame('error', $entry->content['level']);
        $this->assertSame('Some message', $entry->content['message']);
        $this->assertSame('Some error message', $entry->content['context']['exception']);
    }
}
