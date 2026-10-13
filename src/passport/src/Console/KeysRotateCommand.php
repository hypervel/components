<?php

declare(strict_types=1);

namespace Hypervel\Passport\Console;

use Hypervel\Console\Command;
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
    public function handle(SigningKeyRepository $keys): int
    {
        if ($this->option('due')) {
            $keys->rotateDueKeys((int) $this->option('length'));
            $this->components->info('Signing key rotation check completed.');
        } else {
            $key = $keys->rotate((int) $this->option('length'));
            $this->components->info('Signing key published. It activates at ' . $key->activates_at->toDateTimeString() . ' UTC.');
        }

        return Command::SUCCESS;
    }
}
