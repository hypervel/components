<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'storage:link')]
class StorageLinkCommand extends Command
{
    protected ?string $signature = 'storage:link
                {--relative : Create the symbolic link using relative paths}
                {--force : Recreate existing symbolic links}';

    protected string $description = 'Create the symbolic links configured for the application';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $relative = $this->option('relative');

        foreach ($this->links() as $link => $target) {
            if (file_exists($link) && ! $this->isRemovableSymlink($link, $this->option('force'))) {
                $this->components->error("The [{$link}] link already exists.");
                continue;
            }

            if (is_link($link)) {
                $this->hypervel->make('files')->delete($link);
            }

            if ($relative) {
                $this->hypervel->make('files')->relativeLink($target, $link);
            } else {
                $this->hypervel->make('files')->link($target, $link);
            }

            $this->components->info("The [{$link}] link has been connected to [{$target}].");
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

    /**
     * Determine if the provided path is a symlink that can be removed.
     */
    protected function isRemovableSymlink(string $link, bool $force): bool
    {
        return is_link($link) && $force;
    }
}
