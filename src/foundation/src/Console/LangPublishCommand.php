<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use RuntimeException;
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
        $files = $this->hypervel->make(Filesystem::class);
        $langPath = $this->hypervel->basePath('lang/en');
        $files->ensureDirectoryExists($langPath);

        $stubs = [
            __DIR__ . '/../../../translation/lang/en/auth.php' => 'auth.php',
            __DIR__ . '/../../../translation/lang/en/pagination.php' => 'pagination.php',
            __DIR__ . '/../../../translation/lang/en/passwords.php' => 'passwords.php',
            __DIR__ . '/../../../translation/lang/en/validation.php' => 'validation.php',
        ];

        foreach ($stubs as $from => $to) {
            $to = $langPath . DIRECTORY_SEPARATOR . ltrim($to, DIRECTORY_SEPARATOR);
            $exists = $files->exists($to);

            if ((! $this->option('existing') && (! $exists || $this->option('force')))
                || ($this->option('existing') && $exists)) {
                $mode = null;

                if ($exists) {
                    $permissions = $files->chmod($to);

                    if ($permissions === false) {
                        throw new RuntimeException("Unable to determine permissions for [{$to}].");
                    }

                    $mode = octdec($permissions);
                }

                $files->replace($to, $files->get($from), $mode);
            }
        }

        $this->components->info('Language files published successfully.');
    }
}
