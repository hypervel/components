<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'storage:unlink')]
class StorageUnlinkCommand extends Command
{
    protected ?string $signature = 'storage:unlink';

    protected string $description = 'Delete existing symbolic links configured for the application';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        foreach ($this->links() as $link => $target) {
            if (! file_exists($link) || ! is_link($link)) {
                continue;
            }

            $this->hypervel->make('files')->delete($link);

            $this->components->info("The [{$link}] link has been deleted.");
        }
    }

    /**
     * Get the symbolic links that are configured for the application.
     */
    protected function links(): array
    {
        return $this->hypervel['config']['filesystems.links']
            ?? [public_path('storage') => storage_path('app/public')];
    }
}
