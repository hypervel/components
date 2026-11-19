<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Generator;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

class GeneratorCommandTest extends TestCase
{
    use InteractsWithPublishedFiles;

    protected array $files = [
        'app/Console/Commands/FooCommand.php',
        'resources/views/foo/php.blade.php',
        'tests/Feature/fixtures.php/SomeTest.php',
        'app/Mail/Envelope.php',
        'stubs/mail.stub',
        'app/Listeners/OrderShipped.php',
        'app/View/Components/View.php',
        'resources/views/components/view.blade.php',
        'database/factories/ClassFactory.php',
        'database/seeders/ClassSeeder.php',
        'app/Http/Controllers/ClassController.php',
        'app/Policies/ClassPolicy.php',
        'app/Models/School/*.php',
        'app/Models/Int.php',
        'app/School/Extends.php',
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

    #[TestWith(['make:model', 'School/Class', 'app/Models/School/Class.php'])]
    #[TestWith(['make:model', 'Int', 'app/Models/Int.php'])]
    #[TestWith(['make:model', 'School/Extends', 'app/Models/School/Extends.php'])]
    #[TestWith(['make:model', 'School/Implements', 'app/Models/School/Implements.php'])]
    #[TestWith(['make:enum', 'School/Extends', 'app/School/Extends.php'])]
    public function testItCannotGenerateClassWhoseGeneratedNameIsReserved(string $command, string $name, string $path): void
    {
        $this->artisan($command, ['name' => $name])
            ->expectsOutputToContain('The name "' . class_basename($name) . '" is reserved by PHP.')
            ->assertExitCode(1);

        $this->assertFilenameDoesNotExists($path);
    }

    public function testItCanGenerateClassNamedLikeAContextualKeyword(): void
    {
        $this->artisan('make:model', ['name' => 'School/Enum'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Models\School;',
            'class Enum extends Model',
        ], 'app/Models/School/Enum.php');
    }

    public function testItCannotGenerateClassNamedLikeAnImportedClass(): void
    {
        $this->artisan('make:mail', ['name' => 'Envelope'])
            ->expectsOutputToContain('The class name "Envelope" conflicts with the imported [Hypervel\Mail\Mailables\Envelope] class.')
            ->assertExitCode(1);

        $this->assertFilenameDoesNotExists('app/Mail/Envelope.php');
    }

    public function testItCannotGenerateClassNamedLikeAClassItsOptionsImport(): void
    {
        $this->artisan('make:listener', ['name' => 'OrderShipped', '--event' => 'OrderShipped'])
            ->expectsOutputToContain('The class name "OrderShipped" conflicts with the imported [App\Events\OrderShipped] class.')
            ->assertExitCode(1);

        $this->assertFilenameDoesNotExists('app/Listeners/OrderShipped.php');
    }

    public function testItCanGenerateClassNamedLikeAnAliasedImport(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->app->basePath('stubs'));
        $files->put($this->app->basePath('stubs/mail.stub'), <<<'PHP'
            <?php

            namespace {{ namespace }};

            use Hypervel\Mail\Mailable;
            use Hypervel\Mail\Mailables\{Content, Envelope as MailEnvelope};

            class {{ class }} extends Mailable
            {
                use Concerns\Envelope;

                public function envelope(): MailEnvelope
                {
                    return new MailEnvelope(subject: 'Envelope');
                }
            }
            PHP);

        $this->artisan('make:mail', ['name' => 'Envelope'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'use Hypervel\Mail\Mailables\{Content, Envelope as MailEnvelope};',
            'class Envelope extends Mailable',
            'use Concerns\Envelope;',
        ], 'app/Mail/Envelope.php');
    }

    public function testItCannotGenerateClassNamedLikeAClassImportedAfterADeclaration(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->app->basePath('stubs'));
        $files->put($this->app->basePath('stubs/mail.stub'), <<<'PHP'
            <?php

            namespace {{ namespace }};

            use Hypervel\Mail\Mailable;

            class {{ class }} extends Mailable
            {
            }

            use Hypervel\Mail\Mailables\Envelope;
            PHP);

        $this->artisan('make:mail', ['name' => 'Envelope'])
            ->expectsOutputToContain('The class name "Envelope" conflicts with the imported [Hypervel\Mail\Mailables\Envelope] class.')
            ->assertExitCode(1);

        $this->assertFilenameDoesNotExists('app/Mail/Envelope.php');
    }

    public function testForceDoesNotReplaceFilesForClassNamedLikeAnImportedClass(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->app->basePath('app/View/Components'));
        $files->ensureDirectoryExists($this->app->resourcePath('views/components'));
        $files->put($this->app->basePath('app/View/Components/View.php'), 'existing class');
        $files->put($this->app->resourcePath('views/components/view.blade.php'), 'existing view');

        $this->artisan('make:component', ['name' => 'View', '--force' => true])
            ->expectsOutputToContain('The class name "View" conflicts with the imported [Hypervel\Contracts\View\View] class.')
            ->assertExitCode(1);

        $this->assertStringEqualsFile($this->app->basePath('app/View/Components/View.php'), 'existing class');
        $this->assertStringEqualsFile($this->app->resourcePath('views/components/view.blade.php'), 'existing view');
    }
}
