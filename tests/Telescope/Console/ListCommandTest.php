<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Console;

use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Hypervel\Telescope\EntryType;
use Hypervel\Tests\Telescope\FeatureTestCase;

class ListCommandTest extends FeatureTestCase
{
    use CreatesTelescopeEntries;

    /**
     * Indicates if console output should be mocked.
     *
     * Disabled so Artisan::output() captures the real command output.
     */
    public bool $mockConsoleOutput = false;

    public function testListFiltersByType(): void
    {
        $this->createRequest();
        $this->createException(['message' => 'fail']);

        Artisan::call('telescope:list', ['type' => 'request']);
        $output = Artisan::output();

        $this->assertStringContainsString('/test', $output);
        $this->assertStringNotContainsString('fail', $output);
        $this->assertStringContainsString('Showing 1 entry', $output);
    }

    public function testListValidatesTypeArgument(): void
    {
        $this->assertSame(1, $this->artisan('telescope:list', ['type' => 'foobar']));
        $this->assertStringContainsString('Invalid entry type: foobar', Artisan::output());
    }

    public function testListFiltersByBatch(): void
    {
        $batchId = (string) Str::uuid();

        $this->createRequest(['uri' => '/a'], ['batch_id' => $batchId]);
        $this->createRequest(['uri' => '/b']);

        Artisan::call('telescope:list', ['--batch' => $batchId]);
        $output = Artisan::output();

        $this->assertStringContainsString('/a', $output);
        $this->assertStringNotContainsString('/b', $output);
    }

    public function testListFiltersByTag(): void
    {
        $tagged = $this->createRequest(['uri' => '/tagged']);
        $this->createRequest(['uri' => '/untagged']);

        DB::table('telescope_entries_tags')->insert(['entry_uuid' => $tagged->uuid, 'tag' => 'Auth:42']);

        Artisan::call('telescope:list', ['type' => 'request', '--tag' => 'Auth:42']);
        $output = Artisan::output();

        $this->assertStringContainsString('/tagged', $output);
        $this->assertStringNotContainsString('/untagged', $output);
    }

    public function testListPagesBackwardsWithTheBeforeCursor(): void
    {
        $this->createRequest(['uri' => '/older'], ['sequence' => 1]);
        $this->createRequest(['uri' => '/newer'], ['sequence' => 2]);

        Artisan::call('telescope:list', ['type' => 'request', '--before' => 2]);
        $output = Artisan::output();

        $this->assertStringContainsString('/older', $output);
        $this->assertStringNotContainsString('/newer', $output);
    }

    public function testListPrintsACursorWhenThePageIsFull(): void
    {
        foreach (range(1, 3) as $sequence) {
            $this->createRequest([], ['sequence' => $sequence]);
        }

        Artisan::call('telescope:list', ['type' => 'request', '--limit' => 2]);

        $this->assertStringContainsString('Showing 2 entries - Use --before=2 for next page', Artisan::output());

        Artisan::call('telescope:list', ['type' => 'request', '--limit' => 20]);

        $this->assertStringContainsString('No more entries', Artisan::output());
    }

    public function testListRejectsANonPositiveLimit(): void
    {
        $this->createRequest();

        foreach (['abc', '0', '-1'] as $limit) {
            $this->assertSame(1, $this->artisan('telescope:list', ['type' => 'request', '--limit' => $limit]));
            $this->assertStringContainsString('--limit option must be a positive integer', Artisan::output());
        }
    }

    public function testListRejectsANonPositiveBeforeCursor(): void
    {
        $this->createRequest();

        foreach (['abc', '0', '-1'] as $before) {
            $this->assertSame(1, $this->artisan('telescope:list', ['type' => 'request', '--before' => $before]));
            $this->assertStringContainsString('--before option must be a positive integer', Artisan::output());
        }
    }

    public function testListShowsWarningWhenEmpty(): void
    {
        $this->assertSame(0, $this->artisan('telescope:list', ['type' => 'request']));
        $this->assertStringContainsString('No entries found.', Artisan::output());
    }

    public function testListSummarizesMixedEntryTypesWhenNoTypeIsGiven(): void
    {
        $this->createRequest();
        $this->createEntry(EntryType::CACHE, ['type' => 'hit', 'key' => 'user:1']);

        Artisan::call('telescope:list');
        $output = Artisan::output();

        $this->assertStringContainsString('Showing 2 entries', $output);
        $this->assertStringContainsString('GET /test -> 200', $output);
        $this->assertStringContainsString('hit user:1', $output);
    }

    public function testListPreservesConsoleMarkupInRecordedValues(): void
    {
        $this->createQuery(['sql' => "select * from posts where body ~ '\\<draft\\>'"]);
        $this->createEntry(EntryType::LOG, ['level' => 'info', 'message' => '<info>Synced</info> orders']);

        Artisan::call('telescope:list', ['type' => 'query']);
        $this->assertStringContainsString("select * from posts where body ~ '\\<draft\\>'", Artisan::output());

        Artisan::call('telescope:list');
        $this->assertStringContainsString('[info] <info>Synced</info> orders', Artisan::output());
    }

    public function testListOutputsJson(): void
    {
        $entry = $this->createRequest(['payload' => ['note' => '<info>Saved</info> a\>b']]);

        Artisan::call('telescope:list', ['type' => 'request', '--json' => true]);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($entry->uuid, $json[0]['id']);
        $this->assertSame('/test', $json[0]['content']['uri']);
        $this->assertSame(['note' => '<info>Saved</info> a\>b'], $json[0]['content']['payload']);
    }

    public function testListOutputsAnEmptyJsonArrayWhenEmpty(): void
    {
        Artisan::call('telescope:list', ['type' => 'request', '--json' => true]);

        $this->assertSame([], json_decode(Artisan::output(), true));
    }
}
