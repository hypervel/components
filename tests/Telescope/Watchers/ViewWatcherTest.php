<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\ViewWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
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

        $this->viewDir = sys_get_temp_dir() . '/viewwatcher_test_' . uniqid();
        mkdir($this->viewDir);

        $this->app->make('view')->addNamespace('test', $this->viewDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->viewDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->viewDir);

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
}
