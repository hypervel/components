<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\Testing\Fixtures\CleanupActions;
use RuntimeException;

class ProviderMakeCommandTest extends TestCase
{
    protected $files = [
        'app/Providers/FooServiceProvider.php',
    ];

    protected ?string $originalProvidersContents = null;

    protected function setUp(): void
    {
        parent::setUp();

        $path = $this->app->getBootstrapProvidersPath();
        $files = new Filesystem;

        if ($files->isFile($path)) {
            $this->originalProvidersContents = $files->get($path);
        }
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $providersPath = $this->app->getBootstrapProvidersPath();

        if ($this->originalProvidersContents !== null) {
            $restore = fn () => $files->replace(
                $providersPath,
                $this->originalProvidersContents,
            );
        } else {
            $restore = static function () use ($files, $providersPath): void {
                if ($files->isFile($providersPath) && ! $files->delete($providersPath)) {
                    throw new RuntimeException("Unable to delete the owned generated providers file [{$providersPath}].");
                }
            };
        }

        CleanupActions::run(
            $restore,
            fn () => parent::tearDown(),
        );
    }

    public function testItCanGenerateServiceProviderFile()
    {
        $this->artisan('make:provider', ['name' => 'FooServiceProvider'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Providers;',
            'use Hypervel\Support\ServiceProvider;',
            'class FooServiceProvider extends ServiceProvider',
            'public function register()',
            'public function boot()',
        ], 'app/Providers/FooServiceProvider.php');

        $this->assertEquals(require $this->app->getBootstrapProvidersPath(), [
            'App\Providers\FooServiceProvider',
        ]);
    }
}
