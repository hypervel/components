<?php

declare(strict_types=1);

namespace Hypervel\Passport\Console;

use Hypervel\Console\Command;
use Hypervel\Passport\SigningKeyRepository;
use Hypervel\Support\CarbonImmutable;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'passport:keys:import')]
class KeysImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'passport:keys:import
                                      {--retain-until= : Keep imported keys until at least this date}';

    /**
     * The console command description.
     */
    protected string $description = 'Import file signing keys into the database';

    /**
     * Execute the console command.
     */
    public function handle(SigningKeyRepository $keys): int
    {
        $retainUntil = $this->option('retain-until');
        $keys->importFiles($retainUntil === null ? null : CarbonImmutable::parse($retainUntil));
        $this->components->info('Signing keys imported. Set PASSPORT_KEY_STORE=database and restart long-running application processes to use them.');

        return Command::SUCCESS;
    }
}
