<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Console;

use Hypervel\Console\Command;
use Hypervel\Passkeys\Actions\PruneOrphanedPasskeys as PruneOrphanedPasskeysAction;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'passkeys:prune-orphans')]
class PruneOrphanedPasskeys extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'passkeys:prune-orphans {--dry-run : Count orphaned passkeys without deleting them} {--chunk=1000 : The number of passkeys to inspect per chunk}';

    /**
     * The console command description.
     */
    protected string $description = 'Prune passkeys whose polymorphic owner no longer exists';

    /**
     * Execute the console command.
     */
    public function handle(PruneOrphanedPasskeysAction $prune): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $counts = $prune($dryRun, $chunkSize, function (string $message): void {
            $this->warn($message);
        });
        $total = array_sum($counts);

        foreach ($counts as $userType => $count) {
            $this->line("{$userType}: {$count}");
        }

        $verb = $dryRun ? 'Found' : 'Pruned';
        $this->info("{$verb} {$total} orphaned passkeys.");

        return self::SUCCESS;
    }
}
