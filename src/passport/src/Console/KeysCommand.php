<?php

declare(strict_types=1);

namespace Hypervel\Passport\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Passport\Enums\KeyStore;
use Hypervel\Passport\KeyGenerator;
use Hypervel\Passport\Passport;
use Hypervel\Passport\SigningKeyRepository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'passport:keys')]
class KeysCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'passport:keys
                                      {--force : Overwrite keys if they already exist}
                                      {--length=' . KeyGenerator::DEFAULT_LENGTH . ' : The length of the private key}';

    /**
     * The console command description.
     */
    protected string $description = 'Create the encryption keys for API authentication';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (KeyStore::from(config()->string('passport.key_store')) === KeyStore::Database) {
            if (app(SigningKeyRepository::class)->create((int) $this->option('length')) === null) {
                $this->components->error('Signing keys already exist. Use passport:keys:rotate to rotate them.');

                return Command::FAILURE;
            }

            $this->components->info('Encryption keys generated successfully.');

            return Command::SUCCESS;
        }

        [$publicKey, $privateKey] = [
            Passport::keyPath('oauth-public.key'),
            Passport::keyPath('oauth-private.key'),
        ];

        if ((file_exists($publicKey) || file_exists($privateKey)) && ! $this->option('force')) {
            $this->components->error('Encryption keys already exist. Use the --force option to overwrite them.');

            return Command::FAILURE;
        }

        $keys = app(KeyGenerator::class)->generate((int) $this->option('length'));

        $files = app(Filesystem::class);
        $files->replace($privateKey, $keys['private'], 0600);
        $files->replace($publicKey, $keys['public'], 0660);

        $this->components->info('Encryption keys generated successfully.');
        $this->components->warn(
            'Restart the server and every other long-running application process, including queue workers and custom server processes, '
            . 'before issuing tokens with the new key pair. The [php artisan server:reload] command only replaces server workers and is not sufficient.'
        );

        return Command::SUCCESS;
    }
}
