<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Passport\Console\InstallCommand;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class InstallCommandTest extends TestCase
{
    /**
     * Supply the package configuration without invoking child installers.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('passport', require __DIR__ . '/../../../src/passport/config/passport.php');
    }

    #[TestWith(['files'])]
    #[TestWith(['database'])]
    public function testKeysAreCreatedAtTheCorrectStageForTheirStore(string $store): void
    {
        config(['passport.key_store' => $store]);
        $command = new RecordingPassportInstallCommand;
        $tester = $this->tester($command);
        $tester->setInputs(['yes', 'yes']);
        $tester->execute(['--length' => '2048', '--force' => true]);
        $tester->assertCommandIsSuccessful();

        $this->assertSame(
            $store === 'files'
                ? ['passport:keys', 'vendor:publish', 'vendor:publish', 'migrate', 'passport:client']
                : ['vendor:publish', 'vendor:publish', 'migrate', 'passport:keys', 'passport:client'],
            array_column($command->calls, 'command'),
        );
        $keys = array_values(array_filter($command->calls, static fn (array $call): bool => $call['command'] === 'passport:keys'));
        $this->assertSame(['--force' => true, '--length' => '2048'], $keys[0]['arguments']);
    }

    public function testSkippingDatabaseMigrationsExplainsHowToCreateKeysAfterwards(): void
    {
        config(['passport.key_store' => 'database']);
        $command = new RecordingPassportInstallCommand;
        $tester = $this->tester($command);
        $tester->setInputs(['no']);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertSame(['vendor:publish', 'vendor:publish'], array_column($command->calls, 'command'));
        $this->assertStringContainsString('Run passport:keys after migrating', $tester->getDisplay());
        $this->assertStringContainsString('If you need personal access tokens, run passport:client --personal after migrating.', $tester->getDisplay());
    }

    #[TestWith(['files'])]
    #[TestWith(['database'])]
    public function testWithoutMigrationPromptPublishesWithoutMigratingOrCreatingClients(string $store): void
    {
        config(['passport.key_store' => $store]);
        $command = new RecordingPassportInstallCommand;
        $tester = $this->tester($command);
        $tester->execute(['--without-migration-prompt' => true], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();

        $this->assertSame(
            $store === 'files'
                ? ['passport:keys', 'vendor:publish', 'vendor:publish']
                : ['vendor:publish', 'vendor:publish'],
            array_column($command->calls, 'command'),
        );
        $this->assertStringNotContainsString('Would you like', $tester->getDisplay());
        $this->assertStringContainsString('If you need personal access tokens, run passport:client --personal after migrating.', $tester->getDisplay());

        if ($store === 'database') {
            $this->assertStringContainsString('Run passport:keys after migrating', $tester->getDisplay());
        }
    }

    public function testFailedMigrationsStopInstallationAndReturnFailure(): void
    {
        config(['passport.key_store' => 'database']);
        $command = new RecordingPassportInstallCommand;
        $command->migrationStatus = Command::FAILURE;
        $tester = $this->tester($command);
        $tester->setInputs(['yes']);
        $status = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame(['vendor:publish', 'vendor:publish', 'migrate'], array_column($command->calls, 'command'));
    }

    /**
     * Run the real installer interaction while recording its child commands.
     */
    protected function tester(RecordingPassportInstallCommand $command): CommandTester
    {
        $command->setHypervel($this->app);
        $application = new ConsoleApplication;
        $application->addCommand($command);

        return new CommandTester($command);
    }
}

class RecordingPassportInstallCommand extends InstallCommand
{
    /** @var list<array{command: string, arguments: array}> */
    public array $calls = [];

    public int $migrationStatus = Command::SUCCESS;

    /**
     * Record child commands without modifying the application's published files.
     */
    public function call(Command|string $command, array $arguments = []): int
    {
        $this->calls[] = ['command' => $command, 'arguments' => $arguments];

        return $command === 'migrate' ? $this->migrationStatus : Command::SUCCESS;
    }
}
