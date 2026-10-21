<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

class SeederMakeCommandTest extends TestCase
{
    protected array $files = [
        'database/seeders/FooSeeder.php',
    ];

    public function testItCanGenerateSeederFile(): void
    {
        $this->artisan('make:seeder', ['name' => 'FooSeeder'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace Database\Seeders;',
            'use Hypervel\Database\Seeder;',
            'class FooSeeder extends Seeder',
            'public function run()',
        ], 'database/seeders/FooSeeder.php');
    }
}
