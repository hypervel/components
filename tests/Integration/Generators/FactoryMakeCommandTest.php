<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Symfony\Component\Process\Process;

class FactoryMakeCommandTest extends TestCase
{
    protected array $files = [
        'database/factories/FooFactory.php',
        'database/factories/PlantFactory.php',
    ];

    public function testItCanGenerateFactoryFile(): void
    {
        $this->artisan('make:factory', ['name' => 'FooFactory'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace Database\Factories;',
            'use Hypervel\Database\Eloquent\Factories\Factory;',
            '@extends Factory<\App\Models\Model>',
            'class FooFactory extends Factory',
            'public function definition()',
        ], 'database/factories/FooFactory.php');
    }

    public function testItCanGenerateFactoryFileForModelsNamedLikeFactoryClasses(): void
    {
        $this->artisan('make:factory', ['name' => 'PlantFactory', '--model' => 'Factory'])
            ->assertExitCode(0);

        $this->assertFileContains([
            '@extends Factory<\App\Models\Factory>',
            'class PlantFactory extends Factory',
        ], 'database/factories/PlantFactory.php');
        $this->assertFileDoesNotContains([
            'use App\Models\Factory;',
        ], 'database/factories/PlantFactory.php');

        $lint = new Process([PHP_BINARY, '-l', $this->app->basePath('database/factories/PlantFactory.php')]);
        $lint->run();

        $this->assertTrue($lint->isSuccessful(), $lint->getOutput() . $lint->getErrorOutput());
    }
}
