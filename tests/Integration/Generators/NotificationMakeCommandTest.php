<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\NotificationMakeCommand;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

class NotificationMakeCommandTest extends TestCase
{
    protected $files = [
        'app/Notifications/FooNotification.php',
        'resources/views/foo-notification.blade.php',
        'tests/Feature/Notifications/FooNotificationTest.php',
    ];

    public function testItCanGenerateNotificationFile(): void
    {
        $this->artisan('make:notification', ['name' => 'FooNotification'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Notifications;',
            'use Hypervel\Notifications\Notification;',
            'class FooNotification extends Notification',
            'return (new MailMessage)',
        ], 'app/Notifications/FooNotification.php');

        $this->assertFilenameNotExists('resources/views/foo-notification.blade.php');
        $this->assertFilenameNotExists('tests/Feature/Notifications/FooNotificationTest.php');
    }

    public function testItCanGenerateNotificationFileWithMarkdownOption(): void
    {
        $this->artisan('make:notification', ['name' => 'FooNotification', '--markdown' => 'foo-notification'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Notifications;',
            'class FooNotification extends Notification',
            "return (new MailMessage)->markdown('foo-notification')",
        ], 'app/Notifications/FooNotification.php');

        $this->assertFileContains([
            '<x-mail::message>',
        ], 'resources/views/foo-notification.blade.php');
    }

    public function testItCanGenerateNotificationFileWithTest(): void
    {
        $this->artisan('make:notification', ['name' => 'FooNotification', '--test' => true])
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Notifications/FooNotification.php');
        $this->assertFilenameNotExists('resources/views/foo-notification.blade.php');
        $this->assertFilenameExists('tests/Feature/Notifications/FooNotificationTest.php');
    }

    public function testItCanGenerateNotificationFileWithNoInitialInput(): void
    {
        $this->artisan('make:notification')
            ->expectsQuestion('What should the notification be named?', 'FooNotification')
            ->expectsQuestion('Would you like to create a markdown view?', false)
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Notifications/FooNotification.php');
        $this->assertFilenameDoesNotExists('resources/views/foo-notification.blade.php');
    }

    public function testItCanGenerateNotificationFileWithMarkdownTemplateWithNoInitialInput(): void
    {
        $this->artisan('make:notification')
            ->expectsQuestion('What should the notification be named?', 'FooNotification')
            ->expectsQuestion('Would you like to create a markdown view?', true)
            ->expectsQuestion('What should the markdown view be named?', 'foo-notification')
            ->assertExitCode(0);

        $this->assertFilenameExists('app/Notifications/FooNotification.php');
        $this->assertFilenameExists('resources/views/foo-notification.blade.php');
    }

    public function testMarkdownPublicationFailureDoesNotReportMarkdownSuccess(): void
    {
        $viewPath = resource_path('views/failed-notification.blade.php');
        $files = m::mock(Filesystem::class)->makePartial();
        $publicationException = new RuntimeException('Unable to publish notification Markdown view.');
        $files->shouldReceive('replace')->byDefault()->passthru();
        $files->shouldReceive('replace')
            ->once()
            ->with($viewPath, m::type('string'), null)
            ->andThrow($publicationException);

        $tester = $this->commandTester(new NotificationMakeCommand($files));

        try {
            $tester->execute(['name' => 'FailedNotification', '--markdown' => 'failed-notification']);
            $this->fail('Expected notification Markdown publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertStringNotContainsString("Markdown [{$viewPath}] created successfully.", $tester->getDisplay());
        $this->assertFileDoesNotExist($viewPath);
    }

    public function testMarkdownStubReadFailureDoesNotReportMarkdownSuccess(): void
    {
        $stubPath = dirname(__DIR__, 3) . '/src/foundation/src/Console/stubs/markdown.stub';
        $viewPath = resource_path('views/unreadable-notification.blade.php');
        $files = m::mock(Filesystem::class)->makePartial();
        $readException = new FileNotFoundException("File does not exist at path [{$stubPath}].");
        $files->shouldReceive('get')->byDefault()->passthru();
        $files->shouldReceive('get')->once()->with($stubPath)->andThrow($readException);

        $tester = $this->commandTester(new NotificationMakeCommand($files));

        try {
            $tester->execute(['name' => 'UnreadableNotification', '--markdown' => 'unreadable-notification']);
            $this->fail('Expected notification Markdown stub reading to fail.');
        } catch (FileNotFoundException $exception) {
            $this->assertSame($readException, $exception);
        }

        $this->assertStringNotContainsString("Markdown [{$viewPath}] created successfully.", $tester->getDisplay());
        $this->assertFileDoesNotExist($viewPath);
    }

    /**
     * Create a tester for a notification generator command.
     */
    protected function commandTester(NotificationMakeCommand $command): CommandTester
    {
        $command->setHypervel($this->app);
        $application = new ConsoleApplication;
        $application->addCommand($command);

        return new CommandTester($command);
    }
}
