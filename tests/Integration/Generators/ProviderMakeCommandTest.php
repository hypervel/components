<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

/**
 * @internal
 * @coversNothing
 */
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

        if (file_exists($path)) {
            $this->originalProvidersContents = file_get_contents($path);
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalProvidersContents !== null) {
            file_put_contents(
                $this->app->getBootstrapProvidersPath(),
                $this->originalProvidersContents
            );
        }

        parent::tearDown();
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
