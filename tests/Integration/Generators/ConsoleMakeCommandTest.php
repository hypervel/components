<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

class ConsoleMakeCommandTest extends TestCase
{
    protected $files = [
        'app/Console/Commands/FooCommand.php',
    ];

    public function testItCanGenerateConsoleFile()
    {
        $this->artisan('make:command', ['name' => 'FooCommand'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Console\Commands;',
            'use Hypervel\Console\Command;',
            "protected ?string \$signature = 'app:foo-command';",
            "protected string \$description = 'Command description';",
            'class FooCommand extends Command',
        ], 'app/Console/Commands/FooCommand.php');
    }

    public function testItCanGenerateConsoleFileWithCommandOption()
    {
        $this->artisan('make:command', ['name' => 'FooCommand', '--command' => 'foo:bar'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Console\Commands;',
            'use Hypervel\Console\Command;',
            "protected ?string \$signature = 'foo:bar';",
            "protected string \$description = 'Command description';",
            'class FooCommand extends Command',
        ], 'app/Console/Commands/FooCommand.php');
    }
}
