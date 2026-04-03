<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

/**
 * @internal
 * @coversNothing
 */
class InterfaceMakeCommandTest extends TestCase
{
    protected $files = [
        'app/Gateway.php',
        'app/Contracts/Gateway.php',
        'app/Interfaces/Gateway.php',
    ];

    public function testItCanGenerateInterfaceFile()
    {
        $this->artisan('make:interface', ['name' => 'Gateway'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App;',
            'interface Gateway',
        ], 'app/Gateway.php');
    }

    public function testItCanGenerateInterfaceFileWhenContractsFolderExists()
    {
        $interfacesFolderPath = app_path('Contracts');

        /** @var \Hypervel\Filesystem\Filesystem $files */
        $files = $this->app['files'];

        $files->ensureDirectoryExists($interfacesFolderPath);

        $this->artisan('make:interface', ['name' => 'Gateway'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Contracts;',
            'interface Gateway',
        ], 'app/Contracts/Gateway.php');

        $files->deleteDirectory($interfacesFolderPath);
    }

    public function testItCanGenerateInterfaceFileWhenInterfacesFolderExists()
    {
        $interfacesFolderPath = app_path('Interfaces');

        /** @var \Hypervel\Filesystem\Filesystem $files */
        $files = $this->app['files'];

        $files->ensureDirectoryExists($interfacesFolderPath);

        $this->artisan('make:interface', ['name' => 'Gateway'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Interfaces;',
            'interface Gateway',
        ], 'app/Interfaces/Gateway.php');

        $files->deleteDirectory($interfacesFolderPath);
    }
}
