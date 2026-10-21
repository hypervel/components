<?php

declare(strict_types=1);

namespace Hypervel\Passport\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Passport\Enums\KeyStore;
use Hypervel\Passport\KeyGenerator;
use Hypervel\Passport\SigningKeyRepository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'passport:keys:rotate')]
class KeysRotateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'passport:keys:rotate
                                      {--due : Only rotate keys that have reached the configured age}
                                      {--length=' . KeyGenerator::DEFAULT_LENGTH . ' : The length of the private key}';

    /**
     * The console command description.
     */
    protected string $description = 'Publish a replacement database signing key';

    /**
     * Execute the console command.
     */
    public function handle(SigningKeyRepository $keys, Repository $config): int
    {
        if (KeyStore::from($config->string('passport.key_store')) !== KeyStore::Database) {
            $this->components->error('This command requires the database key store. Rotate file keys manually using passport.verification_keys.');

            return Command::FAILURE;
        }

        if ($this->option('due')) {
            $keys->rotateDueKeys((int) $this->option('length'));
            $this->components->info('Signing key rotation check completed.');
        } else {
            $key = $keys->rotate((int) $this->option('length'));
            $message = $key->wasRecentlyCreated ? 'Signing key published.' : 'Signing key is already waiting.';
            $this->components->info($message . ' It activates at ' . $key->activates_at->utc()->toDateTimeString() . ' UTC.');
        }

        return Command::SUCCESS;
    }
}
