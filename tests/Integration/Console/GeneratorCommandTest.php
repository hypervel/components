<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Generator;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class GeneratorCommandTest extends TestCase
{
    use InteractsWithPublishedFiles;

    protected array $files = [
        'app/Console/Commands/FooCommand.php',
        'resources/views/foo/php.blade.php',
        'tests/Feature/fixtures.php/SomeTest.php',
    ];

    public function testItChopsPhpExtension(): void
    {
        $this->artisan('make:command', ['name' => 'FooCommand.php'])
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Console/Commands/FooCommand.php');

        $this->assertFileContains([
            'class FooCommand extends Command',
        ], 'app/Console/Commands/FooCommand.php');
    }

    public function testItChopsPhpExtensionFromMakeViewCommands(): void
    {
        $this->artisan('make:view', ['name' => 'foo.php'])
            ->assertExitCode(0);

        $this->assertFilenameExists('resources/views/foo/php.blade.php');
    }

    public function testItOnlyChopsPhpExtensionFromFilename(): void
    {
        $this->artisan('make:test', ['name' => 'fixtures.php/SomeTest'])
            ->assertExitCode(0);

        $this->assertFilenameExists('tests/Feature/fixtures.php/SomeTest.php');

        $this->assertFileContains([
            'class SomeTest extends TestCase',
        ], 'tests/Feature/fixtures.php/SomeTest.php');
    }

    #[DataProvider('reservedNamesDataProvider')]
    public function testItCannotGenerateClassUsingReservedName(string $given): void
    {
        $path = 'app/Console/Commands/' . $given . '.php';
        $this->files[] = $path;

        $this->artisan('make:command', ['name' => $given])
            ->expectsOutputToContain('The name "' . $given . '" is reserved by PHP.')
            ->assertExitCode(0);

        $this->assertFilenameDoesNotExists($path);
    }

    /**
     * Provide reserved class names.
     */
    public static function reservedNamesDataProvider(): Generator
    {
        yield ['__halt_compiler'];
        yield ['__HALT_COMPILER'];
        yield ['array'];
        yield ['ARRAY'];
        yield ['__class__'];
        yield ['__CLASS__'];
    }
}
