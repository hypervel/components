<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Generator;
use Hypervel\Filesystem\Filesystem;
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
        'app/Mail/Envelope.php',
        'stubs/mail.stub',
        'app/View/Components/View.php',
        'resources/views/components/view.blade.php',
        'database/factories/ClassFactory.php',
        'database/seeders/ClassSeeder.php',
        'app/Http/Controllers/ClassController.php',
        'app/Policies/ClassPolicy.php',
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
        // Laravel writes the class, but Hypervel rejects the invalid namespace Tests\Feature\fixtures.php.
        // The path in the error confirms that the generator preserved the directory's .php suffix.
        $this->artisan('make:test', ['name' => 'fixtures.php/SomeTest'])
            ->expectsOutputToContain('fixtures.php/SomeTest.php] is not valid PHP')
            ->assertExitCode(1);

        $this->assertFilenameDoesNotExists('tests/Feature/fixtures.php/SomeTest.php');
    }

    #[DataProvider('reservedNamesDataProvider')]
    public function testItCannotGenerateClassUsingReservedName(string $given): void
    {
        $path = 'app/Console/Commands/' . $given . '.php';
        $this->files[] = $path;

        $this->artisan('make:command', ['name' => $given])
            ->expectsOutputToContain('The name "' . $given . '" is reserved by PHP.')
            ->assertExitCode(1);

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

    public function testItDoesNotGenerateCompanionFilesForReservedName(): void
    {
        $this->artisan('make:model', ['name' => 'Class', '--all' => true, '--force' => true])
            ->expectsOutputToContain('The name "Class" is reserved by PHP.')
            ->assertExitCode(1);

        $this->assertFilenameDoesNotExists('database/factories/ClassFactory.php');
        $this->assertFilenameDoesNotExists('database/seeders/ClassSeeder.php');
        $this->assertFilenameDoesNotExists('app/Http/Controllers/ClassController.php');
        $this->assertFilenameDoesNotExists('app/Policies/ClassPolicy.php');
        $this->assertMigrationFileNotExists('create_classes_table.php');
    }

    public function testItCanGenerateClassNamedLikeAnAliasedImport(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->app->basePath('stubs'));
        $files->put($this->app->basePath('stubs/mail.stub'), <<<'PHP'
            <?php

            namespace {{ namespace }};

            use Hypervel\Mail\Mailable;
            use Hypervel\Mail\Mailables\Envelope as MailEnvelope;

            class {{ class }} extends Mailable
            {
                public function envelope(): MailEnvelope
                {
                    return new MailEnvelope(subject: 'Envelope');
                }
            }
            PHP);

        $this->artisan('make:mail', ['name' => 'Envelope'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'use Hypervel\Mail\Mailables\Envelope as MailEnvelope;',
            'class Envelope extends Mailable',
        ], 'app/Mail/Envelope.php');
    }

    public function testForceDoesNotReplaceFilesForClassNamedLikeAnImportedClass(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->app->basePath('app/View/Components'));
        $files->ensureDirectoryExists($this->app->resourcePath('views/components'));
        $files->put($this->app->basePath('app/View/Components/View.php'), 'existing class');
        $files->put($this->app->resourcePath('views/components/view.blade.php'), 'existing view');

        $this->artisan('make:component', ['name' => 'View', '--force' => true])
            ->expectsOutputToContain('The generated file [app/View/Components/View.php] is not valid PHP.')
            ->expectsOutputToContain('App\View\Components\View')
            ->assertExitCode(1);

        $this->assertStringEqualsFile($this->app->basePath('app/View/Components/View.php'), 'existing class');
        $this->assertStringEqualsFile($this->app->resourcePath('views/components/view.blade.php'), 'existing view');
    }
}
