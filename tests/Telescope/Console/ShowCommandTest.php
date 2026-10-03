<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Console;

use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Str;
use Hypervel\Telescope\EntryType;
use Hypervel\Tests\Telescope\FeatureTestCase;

class ShowCommandTest extends FeatureTestCase
{
    use CreatesTelescopeEntries;

    /**
     * Indicates if console output should be mocked.
     *
     * Disabled so Artisan::output() captures the real command output.
     */
    public bool $mockConsoleOutput = false;

    public function testShowDisplaysRequestEntry(): void
    {
        $entry = $this->createRequest([
            'uri' => '/api/users', 'duration' => 145, 'controller_action' => 'UserController@index',
            'middleware' => ['web'],
        ]);

        $this->assertSame(0, $this->artisan('telescope:show', ['id' => $entry->uuid]));

        $output = Artisan::output();

        $this->assertStringContainsString('Request: GET /api/users -> 200', $output);
        $this->assertStringContainsString('UserController@index', $output);
        $this->assertStringContainsString('145ms', $output);
        $this->assertStringContainsString('127.0.0.1', $output);
    }

    public function testShowAcceptsAShortenedUuid(): void
    {
        $entry = $this->createRequest(['uri' => '/api/users']);

        $this->assertSame(0, $this->artisan('telescope:show', ['id' => substr($entry->uuid, 0, 8)]));

        $this->assertStringContainsString('Request: GET /api/users -> 200', Artisan::output());
    }

    public function testShowDisplaysExceptionEntry(): void
    {
        $entry = $this->createException([
            'class' => 'InvalidArgumentException', 'message' => 'Something went wrong',
            'file' => 'app/Http/Controllers/UserController.php', 'line' => 38,
        ]);

        $this->assertSame(0, $this->artisan('telescope:show', ['id' => $entry->uuid]));

        $output = Artisan::output();

        $this->assertStringContainsString('Exception: InvalidArgumentException', $output);
        $this->assertStringContainsString('Something went wrong', $output);
        $this->assertStringContainsString('UserController.php:38', $output);
    }

    public function testShowMarksTheExceptionLineInTheCodeContext(): void
    {
        $entry = $this->createException([
            'line' => 38,
            'line_preview' => [37 => '$user = User::find($id);', 38 => 'throw new RuntimeException;'],
        ]);

        Artisan::call('telescope:show', ['id' => $entry->uuid]);
        $output = Artisan::output();

        $this->assertStringContainsString('38 >', $output);
        $this->assertStringNotContainsString('37 >', $output);
    }

    public function testShowDisplaysAFailedJobWithItsException(): void
    {
        $job = $this->createEntry(EntryType::JOB, [
            'name' => 'App\Jobs\SyncOrders', 'status' => 'failed', 'queue' => 'default',
            'connection' => 'redis', 'tries' => 3, 'data' => ['order_id' => 7],
            'exception' => [
                'message' => 'Payment gateway timed out',
                'trace' => [['file' => '/app/Jobs/SyncOrders.php', 'line' => 22]],
            ],
        ]);

        $this->assertSame(0, $this->artisan('telescope:show', ['id' => $job->uuid]));

        $output = Artisan::output();

        $this->assertStringContainsString('Job: SyncOrders [failed]', $output);
        $this->assertStringContainsString('Payment gateway timed out', $output);
        $this->assertStringContainsString('/app/Jobs/SyncOrders.php:22', $output);
    }

    public function testShowDisplaysBatchContext(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest(['uri' => '/api/users'], ['sequence' => 100, 'batch_id' => $batchId]);
        $this->createQuery(['sql' => 'select * from "users"'], ['sequence' => 101, 'batch_id' => $batchId]);
        $this->createException([], ['sequence' => 102, 'batch_id' => $batchId]);

        $this->assertSame(0, $this->artisan('telescope:show', ['id' => $request->uuid]));

        $output = Artisan::output();

        $this->assertStringContainsString('Related Entries - batch ' . substr($batchId, 0, 8), $output);
        $this->assertStringContainsString('select * from "users"', $output);
        $this->assertStringContainsString('Exceptions - 1', $output);
        $this->assertStringContainsString('RuntimeException: Test', $output);
    }

    public function testShowBatchContextOfAnExceptionIncludesItsRequest(): void
    {
        $batchId = (string) Str::uuid();

        $this->createRequest(['method' => 'POST', 'uri' => '/orders', 'response_status' => 500], ['sequence' => 100, 'batch_id' => $batchId]);
        $exception = $this->createException(['message' => 'Boom'], ['sequence' => 101, 'batch_id' => $batchId]);

        Artisan::call('telescope:show', ['id' => $exception->uuid]);

        $this->assertStringContainsString('POST /orders -> 500', Artisan::output());
    }

    public function testShowRendersBatchEntryTypesWithoutADedicatedSection(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest([], ['sequence' => 100, 'batch_id' => $batchId]);
        $this->createEntry(EntryType::DUMP, ['dump' => '<pre>needle</pre>'], ['sequence' => 101, 'batch_id' => $batchId]);
        $this->createEntry(EntryType::VIEW, ['name' => 'welcome', 'path' => '/v.blade.php'], ['sequence' => 102, 'batch_id' => $batchId]);

        Artisan::call('telescope:show', ['id' => $request->uuid]);
        $output = Artisan::output();

        $this->assertStringContainsString('Dumps (1)', $output);
        $this->assertStringContainsString('needle', $output);
        $this->assertStringContainsString('Views (1)', $output);
        $this->assertStringContainsString('welcome', $output);
    }

    public function testShowBatchEntriesAreChronological(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest([], ['sequence' => 100, 'batch_id' => $batchId]);

        foreach (['select first', 'select second'] as $index => $sql) {
            $this->createQuery(['sql' => $sql, 'hash' => "h{$index}"], ['sequence' => 101 + $index, 'batch_id' => $batchId]);
        }

        Artisan::call('telescope:show', ['id' => $request->uuid]);
        $output = Artisan::output();

        $this->assertLessThan(strpos($output, 'select second'), strpos($output, 'select first'));
    }

    public function testShowBatchFlagsSlowAndDuplicateQueries(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest([], ['sequence' => 100, 'batch_id' => $batchId]);

        foreach ([[101, 150.0, true], [102, 2.0, false]] as [$sequence, $time, $slow]) {
            $this->createQuery([
                'sql' => 'select * from users', 'time' => $time, 'slow' => $slow, 'hash' => 'dup1',
                'file' => '/app/Http/Controllers/UserController.php', 'line' => 3,
            ], ['sequence' => $sequence, 'batch_id' => $batchId]);
        }

        Artisan::call('telescope:show', ['id' => $request->uuid]);
        $output = Artisan::output();

        $this->assertStringContainsString('2 total, 152ms', $output);
        $this->assertStringContainsString('1 slow', $output);
        $this->assertStringContainsString('1 duplicate group', $output);
        $this->assertStringContainsString('SLOW', $output);
        $this->assertStringContainsString('DUP', $output);
        $this->assertStringContainsString('UserController.php:3', $output);
    }

    public function testShowBatchReportsTheCacheHitRate(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest([], ['sequence' => 100, 'batch_id' => $batchId]);

        foreach (['hit', 'hit', 'hit', 'missed', 'set'] as $action) {
            $this->createEntry(EntryType::CACHE, ['type' => $action, 'key' => 'user:1'], ['batch_id' => $batchId]);
        }

        Artisan::call('telescope:show', ['id' => $request->uuid]);

        $this->assertStringContainsString('3 hits, 1 misses - 75% hit rate', Artisan::output());
    }

    public function testShowBatchOmitsTheHitRateWhenNoCacheLookupsWereMade(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest([], ['sequence' => 100, 'batch_id' => $batchId]);
        $this->createEntry(EntryType::CACHE, ['type' => 'set', 'key' => 'user:1'], ['batch_id' => $batchId]);

        Artisan::call('telescope:show', ['id' => $request->uuid]);
        $output = Artisan::output();

        $this->assertStringContainsString('Cache - 0 hits, 0 misses', $output);
        $this->assertStringNotContainsString('hit rate', $output);
    }

    public function testShowLatestShortcut(): void
    {
        $this->createRequest(['uri' => '/old'], ['sequence' => 1]);
        $this->createException(['message' => 'Latest'], ['sequence' => 2]);

        $this->assertSame(0, $this->artisan('telescope:show', ['id' => 'latest']));

        $output = Artisan::output();

        $this->assertStringContainsString('Exception: RuntimeException', $output);
        $this->assertStringContainsString('Latest', $output);
        $this->assertStringNotContainsString('/old', $output);
    }

    public function testShowLatestTypeShortcut(): void
    {
        $this->createRequest(['uri' => '/api/test'], ['sequence' => 1]);
        $this->createException(['message' => 'Error'], ['sequence' => 2]);

        Artisan::call('telescope:show', ['id' => 'latest:request']);
        $output = Artisan::output();

        $this->assertStringContainsString('/api/test', $output);
        $this->assertStringNotContainsString('RuntimeException', $output);
    }

    public function testShowTypeOptionFiltersTheBatch(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest([], ['sequence' => 100, 'batch_id' => $batchId]);
        $this->createQuery([], ['sequence' => 101, 'batch_id' => $batchId]);
        $this->createEntry(EntryType::CACHE, ['type' => 'hit', 'key' => 'test'], ['sequence' => 102, 'batch_id' => $batchId]);

        Artisan::call('telescope:show', ['id' => $request->uuid, '--type' => 'query']);
        $output = Artisan::output();

        $this->assertStringContainsString('select 1', $output);
        $this->assertStringNotContainsString('hit rate', $output);
    }

    public function testShowTypeOptionAcceptsAListWithSpaces(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest([], ['sequence' => 100, 'batch_id' => $batchId]);
        $this->createQuery([], ['sequence' => 101, 'batch_id' => $batchId]);
        $this->createEntry(EntryType::CACHE, ['type' => 'hit', 'key' => 'spaced'], ['sequence' => 102, 'batch_id' => $batchId]);

        $this->assertSame(0, $this->artisan('telescope:show', ['id' => $request->uuid, '--type' => 'query, cache']));

        $output = Artisan::output();

        $this->assertStringContainsString('select 1', $output);
        $this->assertStringContainsString('spaced', $output);
    }

    public function testShowReportsWhenTheBatchHasNoEntriesOfTheRequestedType(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest([], ['sequence' => 100, 'batch_id' => $batchId]);
        $this->createQuery([], ['sequence' => 101, 'batch_id' => $batchId]);

        $this->assertSame(0, $this->artisan('telescope:show', ['id' => $request->uuid, '--type' => 'log']));

        $this->assertStringContainsString('No batch entries of type log.', Artisan::output());
    }

    public function testShowFullOptionDisablesTruncation(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest([], ['sequence' => 100, 'batch_id' => $batchId]);
        $this->createQuery([
            'sql' => 'select * from users where ' . str_repeat('id = 1 or ', 20) . 'id = 2',
        ], ['sequence' => 101, 'batch_id' => $batchId]);

        Artisan::call('telescope:show', ['id' => $request->uuid]);
        $this->assertStringNotContainsString('id = 2', Artisan::output());

        Artisan::call('telescope:show', ['id' => $request->uuid, '--full' => true]);
        $this->assertStringContainsString('id = 2', Artisan::output());
    }

    public function testShowOmitsUnitsForValuesTheEntryDoesNotHave(): void
    {
        $job = $this->createEntry(EntryType::JOB, [
            'name' => 'App\Jobs\SyncOrders', 'status' => 'processed', 'queue' => 'default',
            'connection' => 'redis', 'tries' => null, 'timeout' => null, 'data' => [],
        ]);

        Artisan::call('telescope:show', ['id' => $job->uuid]);

        $this->assertStringNotContainsString('Timeout', Artisan::output());
    }

    public function testShowDisplaysEventListeners(): void
    {
        $entry = $this->createEntry(EntryType::EVENT, [
            'name' => 'App\Events\OrderShipped', 'broadcast' => false, 'payload' => [],
            'listeners' => [['name' => 'App\Listeners\SendShipmentNotification@handle', 'queued' => true]],
        ]);

        Artisan::call('telescope:show', ['id' => $entry->uuid]);

        $this->assertStringContainsString('SendShipmentNotification@handle (queued)', Artisan::output());
    }

    public function testShowDisplaysMailAddresses(): void
    {
        $entry = $this->createEntry(EntryType::MAIL, [
            'mailable' => 'App\Mail\Welcome', 'subject' => 'Welcome', 'queued' => false,
            'to' => ['alice@example.com' => 'Alice'], 'from' => ['noreply@example.com' => null],
        ]);

        Artisan::call('telescope:show', ['id' => $entry->uuid]);

        $this->assertStringContainsString('Alice <alice@example.com>', Artisan::output());
    }

    public function testShowDisplaysAFailedScheduledTask(): void
    {
        $entry = $this->createEntry(EntryType::SCHEDULED_TASK, [
            'command' => 'php artisan reports:send', 'expression' => '0 * * * *', 'timezone' => 'UTC',
            'description' => '', 'output' => '', 'status' => 'failed', 'exit_code' => 1,
            'exception' => ['class' => 'RuntimeException', 'message' => 'Mailer unavailable'],
        ]);

        Artisan::call('telescope:show', ['id' => $entry->uuid]);
        $output = Artisan::output();

        $this->assertStringContainsString('Scheduled Task: php artisan reports:send', $output);
        $this->assertMatchesRegularExpression('/Status\W+failed\W/', $output);
        $this->assertMatchesRegularExpression('/Exit Code\W+1\W/', $output);
        $this->assertStringContainsString('Mailer unavailable', $output);
    }

    public function testShowPreservesConsoleMarkupInRecordedValues(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest(['response' => '<info>Saved</info> a\>b'], ['sequence' => 100, 'batch_id' => $batchId]);
        $this->createQuery(['sql' => "select * from posts where body ~ '\\<draft\\>'"], ['sequence' => 101, 'batch_id' => $batchId]);
        $this->createEntry(EntryType::REDIS, ['command' => "set greeting '<comment>hi</comment>'"], ['sequence' => 102, 'batch_id' => $batchId]);

        Artisan::call('telescope:show', ['id' => $request->uuid]);
        $output = Artisan::output();

        $this->assertStringContainsString('<info>Saved</info> a\>b', $output);
        $this->assertStringContainsString("select * from posts where body ~ '\\<draft\\>'", $output);
        $this->assertStringContainsString("set greeting '<comment>hi</comment>'", $output);
    }

    public function testShowPreservesConsoleMarkupInExceptionDetails(): void
    {
        $entry = $this->createException([
            'message' => 'Unable to render "<info>Synced</info>" near a\>b',
            'line' => 38,
            'line_preview' => [38 => "\$this->line('<info>Synced</info>');"],
        ]);

        Artisan::call('telescope:show', ['id' => $entry->uuid]);
        $output = Artisan::output();

        $this->assertStringContainsString('Unable to render "<info>Synced</info>" near a\>b', $output);
        $this->assertStringContainsString("\$this->line('<info>Synced</info>');", $output);
    }

    public function testShowOutputsJson(): void
    {
        $batchId = (string) Str::uuid();

        $request = $this->createRequest([], ['sequence' => 100, 'batch_id' => $batchId]);
        $this->createQuery(['sql' => "select * from posts where body = '<info>a\\>b</info>'"], ['sequence' => 101, 'batch_id' => $batchId]);
        $this->createEntry(EntryType::CACHE, ['type' => 'hit', 'key' => 'test'], ['sequence' => 102, 'batch_id' => $batchId]);

        Artisan::call('telescope:show', ['id' => $request->uuid, '--json' => true, '--type' => 'query']);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($request->uuid, $json['entry']['id']);
        $this->assertCount(1, $json['batch']);
        $this->assertSame("select * from posts where body = '<info>a\\>b</info>'", $json['batch'][0]['content']['sql']);
    }

    public function testShowEntryNotFound(): void
    {
        $this->assertSame(1, $this->artisan('telescope:show', ['id' => 'nonexistent-uuid']));
        $this->assertStringContainsString('Entry not found: nonexistent-uuid', Artisan::output());
    }

    public function testShowLatestWithNoEntries(): void
    {
        $this->assertSame(1, $this->artisan('telescope:show', ['id' => 'latest']));
        $this->assertStringContainsString('No entries found.', Artisan::output());
    }

    public function testShowLatestTypeWithNoMatchingEntries(): void
    {
        $this->createRequest();

        $this->assertSame(1, $this->artisan('telescope:show', ['id' => 'latest:exception']));
        $this->assertStringContainsString('No exception entries found.', Artisan::output());
    }

    public function testShowValidatesLatestType(): void
    {
        $this->assertSame(1, $this->artisan('telescope:show', ['id' => 'latest:foobar']));
        $this->assertStringContainsString('Invalid entry type: foobar', Artisan::output());
    }

    public function testShowValidatesTypeOption(): void
    {
        $entry = $this->createRequest();

        $this->assertSame(1, $this->artisan('telescope:show', ['id' => $entry->uuid, '--type' => 'foobar']));
        $this->assertStringContainsString('Invalid entry type: foobar', Artisan::output());
    }
}
