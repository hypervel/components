<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

class FactoryMakeCommandTest extends TestCase
{
    protected array $files = [
        'database/factories/FooFactory.php',
    ];

    public function testItCanGenerateFactoryFile(): void
    {
        $this->artisan('make:factory', ['name' => 'FooFactory'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace Database\Factories;',
            'use Hypervel\Database\Eloquent\Factories\Factory;',
            'class FooFactory extends Factory',
            'public function definition()',
        ], 'database/factories/FooFactory.php');
    }
}
