<?php

declare(strict_types=1);

namespace Hypervel\Passport\Console;

use Hypervel\Console\Command;
use Hypervel\Passport\Enums\KeyStore;
use Hypervel\Passport\KeyGenerator;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'passport:install')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'passport:install
                            {--force : Overwrite keys if they already exist}
                            {--length=' . KeyGenerator::DEFAULT_LENGTH . ' : The length of the private key}';

    /**
     * The console command description.
     */
    protected string $description = 'Run the commands necessary to prepare Passport for use';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $keyStore = KeyStore::from(config()->string('passport.key_store'));

        if ($keyStore === KeyStore::Files) {
            $this->createKeys();
        }

        $this->call('vendor:publish', ['--tag' => 'passport-config']);
        $this->call('vendor:publish', ['--tag' => 'passport-migrations']);

        if ($this->components->confirm('Would you like to run all pending database migrations?', true)) {
            if ($this->call('migrate') !== self::SUCCESS) {
                $this->fail('Passport installation stopped because migrations did not complete.');
            }

            if ($keyStore === KeyStore::Database) {
                $this->createKeys();
            }

            if ($this->components->confirm('Would you like to create the "personal access" grant client?', true)) {
                $this->call('passport:client', [
                    '--personal' => true,
                    '--name' => config()->string('app.name'),
                ]);
            }
        } elseif ($keyStore === KeyStore::Database) {
            $this->components->info('Run passport:keys after migrating to create the signing keys.');
        }
    }

    /**
     * Generate the signing keys using the selected command options.
     */
    protected function createKeys(): void
    {
        $this->call('passport:keys', [
            '--force' => $this->option('force'),
            '--length' => $this->option('length'),
        ]);
    }
}
