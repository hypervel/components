<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\DumpWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('telescope.watchers', [
    DumpWatcher::class => true,
])]
class DumpWatcherTest extends FeatureTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make(Repository::class)->forever('telescope:dump-watcher', true);
    }

    public function testDumpWatcherRegisterEntry()
    {
        dump($var = 'Telescopes are better than binoculars');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::DUMP, $entry->type);
        $this->assertStringContainsString($var, $entry->content['dump']);
    }
}
