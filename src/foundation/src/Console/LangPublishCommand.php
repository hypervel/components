<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'lang:publish')]
class LangPublishCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'lang:publish
                    {--existing : Publish and overwrite only the files that have already been published}
                    {--force : Overwrite any existing files}';

    /**
     * The console command description.
     */
    protected string $description = 'Publish all language files that are available for customization';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        if (! is_dir($langPath = $this->hypervel->basePath('lang/en'))) {
            (new Filesystem)->makeDirectory($langPath, recursive: true);
        }

        $stubs = [
            realpath(__DIR__ . '/../../../translation/lang/en/auth.php') => 'auth.php',
            realpath(__DIR__ . '/../../../translation/lang/en/pagination.php') => 'pagination.php',
            realpath(__DIR__ . '/../../../translation/lang/en/passwords.php') => 'passwords.php',
            realpath(__DIR__ . '/../../../translation/lang/en/validation.php') => 'validation.php',
        ];

        foreach ($stubs as $from => $to) {
            $to = $langPath . DIRECTORY_SEPARATOR . ltrim($to, DIRECTORY_SEPARATOR);

            if ((! $this->option('existing') && (! file_exists($to) || $this->option('force')))
                || ($this->option('existing') && file_exists($to))) {
                file_put_contents($to, file_get_contents($from));
            }
        }

        $this->components->info('Language files published successfully.');
    }
}
