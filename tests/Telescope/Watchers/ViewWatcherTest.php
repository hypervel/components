<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\ViewWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Hypervel\View\Contracts\View as ViewContract;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class ViewWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get(ConfigInterface::class)
            ->set('telescope.watchers', [
                ViewWatcher::class => true,
            ]);

        $this->startTelescope();
    }

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

        $this->app->get(EventDispatcherInterface::class)
            ->dispatch('composing:tests::welcome', [$view]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::VIEW, $entry->type);
        $this->assertSame('tests::welcome', $entry->content['name']);
        $this->assertSame('/welcome.blade.php', $entry->content['path']);
        $this->assertSame(['foo'], $entry->content['data']);
    }
}
