<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\DumpWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class DumpWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')
            ->set('telescope.watchers', [
                DumpWatcher::class => true,
            ]);

        $this->startTelescope();
    }

    public function testDumpWatcherRegisterEntry()
    {
        dump($var = 'Telescopes are better than binoculars');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::DUMP, $entry->type);
        $this->assertStringContainsString($var, $entry->content['dump']);
    }
}
