<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\MailMakeCommand;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

class MailMakeCommandTest extends TestCase
{
    protected $files = [
        'app/Mail/*.php',
        'resources/views/foo-mail.blade.php',
        'resources/views/mail/*.blade.php',
        'tests/Feature/Mail/*.php',
    ];

    public function testItCanGenerateMailFile(): void
    {
        $this->artisan('make:mail', ['name' => 'FooMail'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Mail;',
            'use Hypervel\Mail\Mailable;',
            'class FooMail extends Mailable',
        ], 'app/Mail/FooMail.php');

        $this->assertFilenameNotExists('resources/views/foo-mail.blade.php');
        $this->assertFilenameNotExists('tests/Feature/Mail/FooMailTest.php');
    }

    public function testItCanGenerateMailFileWithMarkdownOption(): void
    {
        $this->artisan('make:mail', ['name' => 'FooMail', '--markdown' => 'foo-mail'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Mail;',
            'use Hypervel\Mail\Mailable;',
            'class FooMail extends Mailable',
            'return new Content(',
            "markdown: 'foo-mail',",
        ], 'app/Mail/FooMail.php');

        $this->assertFileContains([
            '<x-mail::message>',
            '<x-mail::button :url="\'\'">',
            '</x-mail::button>',
            '</x-mail::message>',
        ], 'resources/views/foo-mail.blade.php');
    }

    public function testErrorsWillBeDisplayedWhenMarkdownsAlreadyExist(): void
    {
        $existingMarkdownPath = 'resources/views/existing-markdown.blade.php';
        $this->app['files']
            ->put(
                $this->app->basePath($existingMarkdownPath),
                '<x-mail::message>My existing markdown</x-mail::message>'
            );
        $this->artisan('make:mail', ['name' => 'FooMail', '--markdown' => 'existing-markdown'])
            ->expectsOutputToContain('already exists.')
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Mail;',
            'use Hypervel\Mail\Mailable;',
            'class FooMail extends Mailable',
            'return new Content(',
            "markdown: 'existing-markdown',",
        ], 'app/Mail/FooMail.php');
        $this->assertFileContains([
            '<x-mail::message>',
            'My existing markdown',
            '</x-mail::message>',
        ], $existingMarkdownPath);
    }

    public function testItCanGenerateMailFileWithViewOption(): void
    {
        $this->artisan('make:mail', ['name' => 'FooMail', '--view' => 'foo-mail'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Mail;',
            'use Hypervel\Mail\Mailable;',
            'class FooMail extends Mailable',
            'return new Content(',
            "view: 'foo-mail',",
        ], 'app/Mail/FooMail.php');

        $this->assertFilenameExists('resources/views/foo-mail.blade.php');
    }

    public function testErrorsWillBeDisplayedWhenViewsAlreadyExist(): void
    {
        $existingViewPath = 'resources/views/existing-template.blade.php';
        $this->app['files']
            ->put(
                $this->app->basePath($existingViewPath),
                '<div>My existing template</div>'
            );
        $this->artisan('make:mail', ['name' => 'FooMail', '--view' => 'existing-template'])
            ->expectsOutputToContain('already exists.')
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Mail;',
            'use Hypervel\Mail\Mailable;',
            'class FooMail extends Mailable',
            'return new Content(',
            "view: 'existing-template',",
        ], 'app/Mail/FooMail.php');
        $this->assertFilenameExists($existingViewPath);
        $this->assertFileContains([
            '<div>My existing template</div>',
        ], $existingViewPath);
    }

    public function testItCanGenerateMailFileWithTest(): void
    {
        $this->artisan('make:mail', ['name' => 'FooMail', '--test' => true])
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Mail/FooMail.php');
        $this->assertFilenameNotExists('resources/views/foo-mail.blade.php');
        $this->assertFilenameExists('tests/Feature/Mail/FooMailTest.php');
    }

    public function testItCanGenerateMailWithNoInitialInput(): void
    {
        $this->artisan('make:mail')
            ->expectsQuestion('What should the mailable be named?', 'FooMail')
            ->expectsQuestion('Would you like to create a view?', 'none')
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Mail/FooMail.php');
        $this->assertFilenameDoesNotExists('resources/views/mail/foo-mail.blade.php');
    }

    public function testItCanGenerateMailWithViewWithNoInitialInput(): void
    {
        $this->artisan('make:mail')
            ->expectsQuestion('What should the mailable be named?', 'MyFooMail')
            ->expectsQuestion('Would you like to create a view?', 'view')
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Mail/MyFooMail.php');
        $this->assertFilenameExists('resources/views/mail/my-foo-mail.blade.php');
    }

    public function testItCanGenerateMailWithMarkdownViewWithNoInitialInput(): void
    {
        $this->artisan('make:mail')
            ->expectsQuestion('What should the mailable be named?', 'FooMail')
            ->expectsQuestion('Would you like to create a view?', 'markdown')
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Mail/FooMail.php');
        $this->assertFilenameExists('resources/views/mail/foo-mail.blade.php');
    }

    public function testViewPublicationFailureDoesNotReportViewSuccess(): void
    {
        $viewPath = resource_path('views/failed-mail.blade.php');
        $files = m::mock(Filesystem::class)->makePartial();
        $publicationException = new RuntimeException('Unable to publish mail view.');
        $files->shouldReceive('replace')->byDefault()->passthru();
        $files->shouldReceive('replace')
            ->once()
            ->with($viewPath, m::type('string'), null)
            ->andThrow($publicationException);

        $tester = $this->commandTester(new MailMakeCommand($files));

        try {
            $tester->execute(['name' => 'FailedMail', '--view' => 'failed-mail']);
            $this->fail('Expected mail view publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertStringNotContainsString("View [{$viewPath}] created successfully.", $tester->getDisplay());
        $this->assertFileDoesNotExist($viewPath);
    }

    public function testMarkdownStubReadFailureDoesNotReportMarkdownSuccess(): void
    {
        $stubPath = dirname(__DIR__, 3) . '/src/foundation/src/Console/stubs/markdown.stub';
        $viewPath = resource_path('views/failed-markdown.blade.php');
        $files = m::mock(Filesystem::class)->makePartial();
        $readException = new FileNotFoundException("File does not exist at path [{$stubPath}].");
        $files->shouldReceive('get')->byDefault()->passthru();
        $files->shouldReceive('get')->once()->with($stubPath)->andThrow($readException);

        $tester = $this->commandTester(new MailMakeCommand($files));

        try {
            $tester->execute(['name' => 'FailedMarkdown', '--markdown' => 'failed-markdown']);
            $this->fail('Expected mail Markdown stub reading to fail.');
        } catch (FileNotFoundException $exception) {
            $this->assertSame($readException, $exception);
        }

        $this->assertStringNotContainsString("Markdown view [{$viewPath}] created successfully.", $tester->getDisplay());
        $this->assertFileDoesNotExist($viewPath);
    }

    /**
     * Create a tester for a mail generator command.
     */
    protected function commandTester(MailMakeCommand $command): CommandTester
    {
        $command->setHypervel($this->app);
        $application = new ConsoleApplication;
        $application->addCommand($command);

        return new CommandTester($command);
    }
}
