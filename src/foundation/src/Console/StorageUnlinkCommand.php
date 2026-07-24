<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'storage:unlink')]
class StorageUnlinkCommand extends Command
{
    protected ?string $signature = 'storage:unlink';

    protected string $description = 'Delete existing symbolic links configured for the application';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $files = $this->hypervel->make('files');

        foreach ($this->links() as $link => $target) {
            if (! is_link($link)) {
                continue;
            }

            if (! $files->delete($link)) {
                if (is_link($link)) { // @phpstan-ignore if.alwaysTrue (delete may have changed the link state)
                    throw new RuntimeException("Unable to delete the link [{$link}].");
                }
            }

            $this->components->info("The [{$link}] link has been deleted.");
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
}
