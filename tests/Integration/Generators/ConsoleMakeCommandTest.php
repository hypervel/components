<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

/**
 * @internal
 * @coversNothing
 */
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
            'use Hypervel\Console\Attributes\Description;',
            'use Hypervel\Console\Attributes\Signature;',
            'use Hypervel\Console\Command;',
            "#[Signature('app:foo-command')]",
            "#[Description('Command description')]",
            'class FooCommand extends Command',
        ], 'app/Console/Commands/FooCommand.php');
    }

    public function testItCanGenerateConsoleFileWithCommandOption()
    {
        $this->artisan('make:command', ['name' => 'FooCommand', '--command' => 'foo:bar'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Console\Commands;',
            'use Hypervel\Console\Attributes\Description;',
            'use Hypervel\Console\Attributes\Signature;',
            'use Hypervel\Console\Command;',
            "#[Signature('foo:bar')]",
            "#[Description('Command description')]",
            'class FooCommand extends Command',
        ], 'app/Console/Commands/FooCommand.php');
    }
}
