<?php

declare(strict_types=1);

namespace Hypervel\Server\Commands;

use Hypervel\Console\Command;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Server\Exceptions\InvalidArgumentException;
use Hypervel\Server\Exceptions\ServerException;
use Hypervel\Server\ServerReloader;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'server:reload')]
class ServerReloadCommand extends Command
{
    protected ?string $signature = 'server:reload';

    protected string $description = 'Reload the server event and task workers gracefully.';

    public function __construct(
        protected ServerReloader $reloader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Reloading workers...');

        try {
            $this->reloader->reload();
        } catch (FileNotFoundException|InvalidArgumentException|ServerException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
