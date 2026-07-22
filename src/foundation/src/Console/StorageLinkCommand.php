<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use RuntimeException;
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
    public function handle(): void
    {
        $relative = $this->option('relative');
        $force = (bool) $this->option('force');
        $files = $this->hypervel->make('files');

        foreach ($this->links() as $link => $target) {
            if ((file_exists($link) || is_link($link)) && ! $this->isRemovableSymlink($link, $force)) {
                $this->components->error("The [{$link}] link already exists.");
                continue;
            }

            if (is_link($link) && ! $files->delete($link)) {
                // Filesystem clears this at runtime; repeat it so static analysis
                // re-evaluates the native postcondition below.
                clearstatcache(false, $link);

                if (file_exists($link) || is_link($link)) {
                    throw new RuntimeException("Unable to delete the existing link [{$link}].");
                }
            }

            if ($relative) {
                $files->relativeLink($target, $link);
            } elseif ($files->link($target, $link) === false) {
                throw new RuntimeException("Unable to create a link from [{$link}] to [{$target}].");
            }

            if (! file_exists($link) && ! is_link($link)) {
                throw new RuntimeException("Unable to create a link from [{$link}] to [{$target}].");
            }

            $this->components->info("The [{$link}] link has been connected to [{$target}].");
        }
    }

    /**
     * Get the symbolic links that are configured for the application.
     */
    protected function links(): array
    {
        return $this->hypervel->make('config')->array(
            'filesystems.links',
            [public_path('storage') => storage_path('app/public')],
        );
    }

    /**
     * Determine if the provided path is a symlink that can be removed.
     */
    protected function isRemovableSymlink(string $link, bool $force): bool
    {
        return is_link($link) && $force;
    }
}
