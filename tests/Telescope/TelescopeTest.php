<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Bus\Contracts\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\QueryWatcher;

/**
 * @internal
 * @coversNothing
 */
class TelescopeTest extends FeatureTestCase
{
    protected int $count = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get(ConfigInterface::class)
            ->set('telescope.watchers', [
                QueryWatcher::class => [
                    'enabled' => true,
                    'slow' => 0.9,
                ],
            ]);

        $this->startTelescope();
    }

    public function testRunAfterRecordingCallback()
    {
        Telescope::afterRecording(function (Telescope $telescope, IncomingEntry $entry) {
            ++$this->count;
        });

        EntryModel::count();
        EntryModel::count();

        $this->assertSame(2, $this->count);
    }

    public function testAfterRecordingCallbackCanStoreAndFlush()
    {
        Telescope::afterRecording(function (Telescope $telescope, IncomingEntry $entry) {
            if (count(Telescope::getEntriesQueue()) > 1) {
                $repository = $this->app->get(EntriesRepository::class);
                $telescope->store($repository);
            }
        });

        EntryModel::count();

        $this->assertCount(1, Telescope::getEntriesQueue());

        EntryModel::count();

        $this->assertCount(0, Telescope::getEntriesQueue());

        EntryModel::count();

        $this->assertCount(1, Telescope::getEntriesQueue());
    }

    public function testRunAfterStoreCallback()
    {
        $storedEntries = null;
        $storedBatchId = null;
        Telescope::afterStoring(function (array $entries, $batchId) use (&$storedEntries, &$storedBatchId) {
            $storedEntries = $entries;
            $storedBatchId = $batchId;

            $this->count += count($entries);
        });

        EntryModel::count();

        EntryModel::count();

        $this->assertSame(0, $this->count);

        $repository = $this->app->get(EntriesRepository::class);
        Telescope::store($repository);

        $this->assertSame(2, $this->count);
        $this->assertCount(2, $storedEntries);
        $this->assertSame(36, strlen($storedBatchId));
        $this->assertInstanceOf(IncomingEntry::class, $storedEntries[0]);
    }

    public function testDontStartRecordingWhenDispatchingJobSynchronously()
    {
        Telescope::stopRecording();

        $this->assertFalse(Telescope::isRecording());

        $this->app->get(Dispatcher::class)->dispatch(
            new MySyncJob('Awesome Laravel')
        );

        $this->assertFalse(Telescope::isRecording());
    }
}

class MySyncJob implements ShouldQueue
{
    public $connection = 'sync';

    private $payload;

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {
    }
}
