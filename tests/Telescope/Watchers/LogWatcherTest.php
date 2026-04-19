<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\LogWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use stdClass;

#[WithConfig('logging.default', 'null')]
class LogWatcherTest extends FeatureTestCase
{
    #[DataProvider('logLevelProvider')]
    #[WithConfig('telescope.watchers', [
        LogWatcher::class => true,
    ])]
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

    #[DataProvider('logLevelProvider')]
    #[WithConfig('telescope.watchers', [
        LogWatcher::class => [
            'enabled' => true,
            'level' => 'error',
        ],
    ])]
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

    #[DataProvider('logLevelProvider')]
    #[WithConfig('telescope.watchers', [
        LogWatcher::class => [
            'level' => 'debug',
        ],
    ])]
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

    #[DataProvider('logLevelProvider')]
    #[WithConfig('telescope.watchers', [
        LogWatcher::class => false,
    ])]
    public function testLogWatcherDoNotRegistersEntryWhenDisabledOnTheBooleanFormat(string $level)
    {
        $logger = $this->app->make(LoggerInterface::class);

        $logger->{$level}("Logging Level [{$level}].", [
            'user' => 'Claire Redfield',
            'role' => 'Zombie Hunter',
        ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNull($entry);
    }

    #[DataProvider('logLevelProvider')]
    #[WithConfig('telescope.watchers', [
        LogWatcher::class => [
            'enabled' => false,
            'level' => 'error',
        ],
    ])]
    public function testLogWatcherDoNotRegistersEntryWhenDisabledOnTheArrayFormat(string $level)
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

    #[WithConfig('telescope.watchers', [
        LogWatcher::class => true,
    ])]
    public function testLogWatcherRegistersEntryWithExceptionKey()
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

    #[DataProvider('interpolationProvider')]
    #[WithConfig('telescope.watchers', [
        LogWatcher::class => [
            'enabled' => true,
            'level' => 'info',
        ],
    ])]
    public function testLogWatcherInterpolatesMessage(string $message, array $context, string $expectedMessage)
    {
        $logger = $this->app->make(LoggerInterface::class);

        $logger->info($message, $context);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::LOG, $entry->type);
        $this->assertSame('info', $entry->content['level']);
        $this->assertSame($expectedMessage, $entry->content['message']);
    }

    public static function interpolationProvider()
    {
        $stringableObject = new class {
            public function __toString()
            {
                return 'Stringable Object';
            }
        };

        return [
            'all placeholders replaced' => [
                'User {id} created: {name}.',
                ['id' => 123, 'name' => 'Jill Valentine'],
                'User 123 created: Jill Valentine.',
            ],
            'some placeholders not replaced' => [
                'User {id} created: {name}.',
                ['id' => 456],
                'User 456 created: {name}.',
            ],
            'non-stringable object value' => [
                'Data: {data}, User: {user_id}.',
                ['data' => new stdClass, 'user_id' => 789],
                'Data: {data}, User: 789.',
            ],
            'array value' => [
                'Request data: {payload}.',
                ['payload' => ['a' => 1, 'b' => 2]],
                'Request data: {payload}.',
            ],
            'stringable object value' => [
                'Object: {obj}.',
                ['obj' => $stringableObject],
                'Object: Stringable Object.',
            ],
            'no placeholders present in context' => [
                'Message with {unprovided} placeholder.',
                ['some_other_key' => 'value'],
                'Message with {unprovided} placeholder.',
            ],
            'null value' => [
                'Value is {value}.',
                ['value' => null],
                'Value is {value}.',
            ],
        ];
    }

    #[WithConfig('telescope.watchers', [
        LogWatcher::class => true,
    ])]
    public function testLogWatcherStoresExtraWhenContextFacadeUsed()
    {
        ContextRepository::getInstance()->add('trace_id', 'abc-123');

        $logger = $this->app->make(LoggerInterface::class);
        $logger->error('test message');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertArrayHasKey('extra', $entry->content);
        $this->assertSame('abc-123', $entry->content['extra']['trace_id']);
    }

    #[WithConfig('telescope.watchers', [
        LogWatcher::class => true,
    ])]
    public function testLogWatcherOmitsExtraWhenContextFacadeNotUsed()
    {
        $logger = $this->app->make(LoggerInterface::class);
        $logger->error('test message');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertArrayNotHasKey('extra', $entry->content);
    }
}
