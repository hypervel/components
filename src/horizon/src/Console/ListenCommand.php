<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Watcher\Driver\ScanFileDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\Watcher;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:listen')]
class ListenCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'horizon:listen
        {--environment= : The environment name}
        {--poll : Use polling for file watching}';

    /**
     * The console command description.
     */
    protected string $description = 'Run Horizon and automatically restart workers on file changes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $config = config('watcher', []);

        if ($paths = config('horizon.watch')) {
            $config['watch'] = $paths;
        }

        if (empty($config['watch'] ?? null)) {
            throw new InvalidArgumentException(
                'List of directories / files to watch not found. Please update your "config/horizon.php" configuration file.',
            );
        }

        if ($this->option('poll')) {
            $config['driver'] = ScanFileDriver::class;
        }

        $this->components->info('Starting Horizon and watching for file changes...');

        $option = Option::fromConfig($config, base_path());
        $driver = $this->hypervel->make($option->getDriver(), ['option' => $option]);
        $strategy = $this->hypervel->make(HorizonRestartStrategy::class, [
            'output' => $this->output,
            'environment' => $this->option('environment'),
        ]);

        $watcher = new Watcher($driver, $this->output, $strategy);
        $watcher->run();

        return self::SUCCESS;
    }
}
