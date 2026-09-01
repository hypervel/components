<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Hypervel\Tinker\Console\TinkerCommand;
use Hypervel\Tinker\TinkerServiceProvider;

class TinkerServiceProviderTest extends TestCase
{
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->filesystem->delete(config_path('tinker.php'));
    }

    protected function tearDown(): void
    {
        try {
            $this->filesystem->delete(config_path('tinker.php'));
        } finally {
            parent::tearDown();
        }
    }

    protected function getPackageProviders(Application $app): array
    {
        return [TinkerServiceProvider::class];
    }

    public function testTinkerCommandIsRegisteredLazily(): void
    {
        $this->assertFalse($this->app->bound('command.tinker'));
        $this->assertFalse($this->app->resolved(TinkerCommand::class));

        $this->artisan('env')->assertSuccessful();

        $this->assertFalse($this->app->resolved(TinkerCommand::class));

        $this->assertInstanceOf(TinkerCommand::class, $this->app->make(TinkerCommand::class));
        $this->assertTrue($this->app->resolved(TinkerCommand::class));
    }

    public function testTinkerConfigIsMerged(): void
    {
        $config = $this->app->make('config');

        $this->assertIsArray($config->get('tinker.commands'));
        $this->assertIsArray($config->get('tinker.alias'));
        $this->assertIsArray($config->get('tinker.dont_alias'));
        $this->assertNotNull($config->get('tinker.trust_project'));
    }

    public function testPublishedConfigDoesNotExcludeApplicationNamespacesByDefault(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'tinker-config',
            '--force' => true,
        ])->assertSuccessful();

        $config = require config_path('tinker.php');

        $this->assertSame([], $config['dont_alias']);
    }
}
