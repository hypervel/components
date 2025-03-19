<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hyperf\Contract\ConfigInterface;
use Hyperf\ViewEngine\Contract\ViewInterface;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\ViewWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Hypervel\View\Events\ViewRendered;
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
        $view = m::mock(ViewInterface::class);
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
            ->dispatch(new ViewRendered($view));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::VIEW, $entry->type);
        $this->assertSame('tests::welcome', $entry->content['name']);
        $this->assertSame('/welcome.blade.php', $entry->content['path']);
        $this->assertSame(['foo'], $entry->content['data']);
    }
}
