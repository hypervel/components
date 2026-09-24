<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Filesystem\Filesystem;

class TraitMakeCommandTest extends TestCase
{
    protected array $files = [
        'app/FooTrait.php',
        'app/Traits/FooTrait.php',
        'app/Concerns/FooTrait.php',
    ];

    public function testItCanGenerateTraitFile()
    {
        $this->artisan('make:trait', ['name' => 'FooTrait'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App;',
            'trait FooTrait',
        ], 'app/FooTrait.php');
    }

    public function testItCanGenerateTraitFileWhenTraitsFolderExists(): void
    {
        $traitsFolderPath = app_path('Traits');

        /** @var Filesystem $files */
        $files = $this->app->make('files');

        $files->ensureDirectoryExists($traitsFolderPath);

        $this->artisan('make:trait', ['name' => 'FooTrait'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Traits;',
            'trait FooTrait',
        ], 'app/Traits/FooTrait.php');

        $files->deleteDirectory($traitsFolderPath);
    }

    public function testItCanGenerateTraitFileWhenConcernsFolderExists(): void
    {
        $traitsFolderPath = app_path('Concerns');

        /** @var Filesystem $files */
        $files = $this->app->make('files');

        $files->ensureDirectoryExists($traitsFolderPath);

        $this->artisan('make:trait', ['name' => 'FooTrait'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Concerns;',
            'trait FooTrait',
        ], 'app/Concerns/FooTrait.php');

        $files->deleteDirectory($traitsFolderPath);
    }
}
