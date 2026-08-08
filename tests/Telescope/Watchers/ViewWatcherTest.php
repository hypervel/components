<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Closure;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\ViewWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Hypervel\View\Factory;
use Throwable;

#[WithConfig('telescope.watchers', [
    ViewWatcher::class => true,
])]
class ViewWatcherTest extends FeatureTestCase
{
    protected string $viewDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewDir = ParallelTesting::tempDir('TelescopeViewWatcherTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->viewDir);
        $filesystem->ensureDirectoryExists($this->viewDir);

        $this->app->make(Factory::class)->addNamespace('test', $this->viewDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->viewDir);

        parent::tearDown();
    }

    public function testViewWatcherRegistersViews()
    {
        file_put_contents($this->viewDir . '/welcome.blade.php', 'Hello {{ $name }}');

        $this->app->make('view')->make('test::welcome', ['name' => 'Hypervel'])->render();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::VIEW, $entry->type);
        $this->assertSame('test::welcome', $entry->content['name']);
        $this->assertSame(['name'], $entry->content['data']);
    }

    public function testViewWatcherCapturesViewsWithoutComposers()
    {
        file_put_contents($this->viewDir . '/simple.blade.php', 'No composers here');

        $this->app->make('view')->make('test::simple')->render();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::VIEW, $entry->type);
        $this->assertSame('test::simple', $entry->content['name']);
    }

    public function testViewWatcherCapturesFailedRenders()
    {
        file_put_contents($this->viewDir . '/error.blade.php', '{{ $undefined->method() }}');

        try {
            $this->app->make('view')->make('test::error')->render();
        } catch (Throwable) {
            // Expected
        }

        $entries = $this->loadTelescopeEntries();

        $viewEntries = $entries->filter(fn ($entry) => $entry->type === EntryType::VIEW);
        $this->assertCount(1, $viewEntries);
        $this->assertSame('test::error', $viewEntries->first()->content['name']);
    }

    public function testViewWatcherCapturesNestedViews()
    {
        file_put_contents($this->viewDir . '/child.blade.php', 'Child content');
        file_put_contents($this->viewDir . '/parent.blade.php', 'Parent @include("test::child")');

        $this->app->make('view')->make('test::parent')->render();

        $entries = $this->loadTelescopeEntries();

        $viewEntries = $entries->filter(fn ($entry) => $entry->type === EntryType::VIEW);
        $this->assertCount(2, $viewEntries);

        $names = $viewEntries->pluck('content')->pluck('name')->sort()->values()->toArray();
        $this->assertSame(['test::child', 'test::parent'], $names);
    }

    public function testViewWatcherCapturesClassComposer(): void
    {
        file_put_contents($this->viewDir . '/composed.blade.php', 'Composed');
        $this->app->make(Factory::class)->composer('test::composed', ViewWatcherComposer::class);

        $this->app->make(Factory::class)->make('test::composed')->render();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame([
            ['name' => ViewWatcherComposer::class . '@compose', 'type' => 'composer'],
        ], $entry->content['composers']);
    }

    public function testViewWatcherCapturesClosureComposerAndWildcardCreator(): void
    {
        file_put_contents($this->viewDir . '/metadata.blade.php', 'Metadata');
        $factory = $this->app->make(Factory::class);
        $factory->composer('test::metadata', function (ViewContract $view): void {
            $view->with('composed', true);
        });
        $factory->creator('test::*', function (ViewContract $view): void {
            $view->with('created', true);
        });

        $factory->make('test::metadata')->render();

        $composers = $this->loadTelescopeEntries()->first()->content['composers'];

        $this->assertSame(['composer', 'creator'], array_column($composers, 'type'));
        $this->assertStringStartsWith('Closure at ', $composers[0]['name']);
        $this->assertStringStartsWith('Closure at ', $composers[1]['name']);
    }

    public function testViewWatcherFormatsComposerWithoutAClassScope(): void
    {
        file_put_contents($this->viewDir . '/global.blade.php', 'Global');
        $this->app->make(Factory::class)->composer('test::global', telescopeGlobalViewComposer());

        $this->app->make(Factory::class)->make('test::global')->render();

        $composers = $this->loadTelescopeEntries()->first()->content['composers'];

        $this->assertCount(1, $composers);
        $this->assertStringStartsWith('Closure at ', $composers[0]['name']);
    }
}

class ViewWatcherComposer
{
    public function compose(ViewContract $view): void
    {
        $view->with('composed', true);
    }
}

function telescopeGlobalViewComposer(): Closure
{
    return function (ViewContract $view): void {
        $view->with('composed', true);
    };
}
