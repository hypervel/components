<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Prompts\confirm;

#[AsCommand(name: 'reverb:install')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'reverb:install';

    /**
     * The console command description.
     */
    protected string $description = 'Install the Reverb dependencies';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->addEnvironmentVariables();
        $this->publishConfiguration();
        $this->updateBroadcastingDriver();

        $this->components->info('Reverb installed successfully.');
    }

    /**
     * Add the Reverb variables to the environment file.
     */
    protected function addEnvironmentVariables(): void
    {
        if (File::missing($env = app()->environmentFile())) {
            return;
        }

        $contents = File::get($env);
        $appId = (string) random_int(100_000, 999_999);
        $appKey = Str::lower(Str::random(20));
        $appSecret = Str::lower(Str::random(20));

        $variables = Arr::where([
            'REVERB_APP_ID' => "REVERB_APP_ID={$appId}",
            'REVERB_APP_KEY' => "REVERB_APP_KEY={$appKey}",
            'REVERB_APP_SECRET' => "REVERB_APP_SECRET={$appSecret}",
            'REVERB_HOST' => 'REVERB_HOST="localhost"',
            'REVERB_PORT' => 'REVERB_PORT=8080',
            'REVERB_SCHEME' => 'REVERB_SCHEME=http',
            'REVERB_SERVER_HOST' => 'REVERB_SERVER_HOST="0.0.0.0"',
            'REVERB_SERVER_PORT' => 'REVERB_SERVER_PORT=8080',
        ], function ($value, $key) use ($contents) {
            return ! Str::contains($contents, PHP_EOL . $key);
        });

        $variables = trim(implode(PHP_EOL, $variables));

        if ($variables === '') {
            return;
        }

        File::append(
            $env,
            Str::endsWith($contents, PHP_EOL) ? PHP_EOL . $variables . PHP_EOL : PHP_EOL . PHP_EOL . $variables . PHP_EOL,
        );
    }

    /**
     * Publish the Reverb configuration file.
     */
    protected function publishConfiguration(): void
    {
        $this->callSilently('vendor:publish', [
            '--provider' => 'Hypervel\Reverb\ReverbServiceProvider',
            '--tag' => 'reverb-config',
        ]);
    }

    /**
     * Update the configured broadcasting driver.
     */
    protected function updateBroadcastingDriver(): void
    {
        $enable = confirm('Would you like to enable the Reverb broadcasting driver?', default: true);

        if (! $enable || File::missing($env = app()->environmentFile())) {
            return;
        }

        File::put(
            $env,
            (string) Str::of(File::get($env))->replaceMatches('/(BROADCAST_(?:DRIVER|CONNECTION))=.*/', function (array $matches) {
                return $matches[1] . '=reverb';
            })
        );
    }
}
