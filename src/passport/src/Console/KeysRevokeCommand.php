<?php

declare(strict_types=1);

namespace Hypervel\Passport\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Passport\Enums\KeyStore;
use Hypervel\Passport\SigningKeyRepository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'passport:keys:revoke')]
class KeysRevokeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'passport:keys:revoke {kid : The public signing key identifier}';

    /**
     * The console command description.
     */
    protected string $description = 'Revoke a database signing key immediately';

    /**
     * Execute the console command.
     */
    public function handle(SigningKeyRepository $keys, Repository $config): int
    {
        if (KeyStore::from($config->string('passport.key_store')) !== KeyStore::Database) {
            $this->components->error('This command requires the database key store. Rotate file keys manually using passport.verification_keys.');

            return Command::FAILURE;
        }

        if (! $keys->revoke($this->argument('kid'))) {
            $this->components->error('Signing key not found.');

            return Command::FAILURE;
        }

        $this->components->info('Signing key revoked.');
        $this->components->warn('Tokens signed by this key will fail verification. Other workers receive the change when their keys refresh.');

        return Command::SUCCESS;
    }
}
