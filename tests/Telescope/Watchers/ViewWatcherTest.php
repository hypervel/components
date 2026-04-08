<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\ViewWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('telescope.watchers', [
    ViewWatcher::class => true,
])]
class ViewWatcherTest extends FeatureTestCase
{
    public function testViewWatcherRegistersViews()
    {
        $view = m::mock(ViewContract::class);
        $view->shouldReceive('name')
            ->once()
            ->andReturn('tests::welcome');
        $view->shouldReceive('getPath')
            ->once()
            ->andReturn('/welcome.blade.php');
        $view->shouldReceive('getData')
            ->once()
            ->andReturn(['foo' => 'bar']);

        $this->app->make(Dispatcher::class)
            ->dispatch('composing:tests::welcome', [$view]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::VIEW, $entry->type);
        $this->assertSame('tests::welcome', $entry->content['name']);
        $this->assertSame('/welcome.blade.php', $entry->content['path']);
        $this->assertSame(['foo'], $entry->content['data']);
    }
}
