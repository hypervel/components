<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console\Commands;

use Hyperf\Collection\Arr;
use Hyperf\Collection\Collection;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Stringable\Str;
use Hyperf\Support\Composer;
use Hyperf\Support\Filesystem\Filesystem;
use Hypervel\Foundation\Console\Command;
use Hypervel\Support\ServiceProvider;

class VendorPublishCommand extends Command
{
    protected ?string $signature = 'vendor:publish {package?}
                                    {--force : Overwrite any existing files}
                                    {--tag= : Tag name that have assets you want to publish}
                                    {--all : Publish assets for all service providers without prompt}
    ';

    protected string $description = 'Publish any publishable assets from vendor packages';

    public function __construct(
        protected ContainerInterface $container,
        protected Filesystem $filesystem
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $publishes = Collection::make($this->getPackagePublishes());

        if ($package = trim($this->argument('package') ?: '')) {
            if ($files = $publishes[$package] ?? []) {
                $this->publishFiles($files);
            }
            return 0;
        }

        $choice = $this->promptForProvider($publishes);
        if (is_null($choice)) {
            $this->publishAll($publishes);
            return 0;
        }
        if ($files = $publishes[$choice] ?? []) {
            $this->publishFiles($files);
            return 0;
        }

        if ($this->option('all')) {
            $this->publishAll($publishes);
            return 0;
        }

        return 1;
    }

    protected function publishAll(Collection $publishes): void
    {
        foreach ($publishes as $files) {
            $this->publishFiles($files);
        }

        $this->info('Publishing complete.');
    }

    protected function publishFiles(array $files): void
    {
        foreach ($files as $file) {
            $source = $file['source'];
            $destination = $file['destination'];

            if ($this->filesystem->exists($destination) && ! $this->option('force')) {
                $this->output->writeln("[<comment>{$destination}</comment>] already exists!");
                continue;
            }

            if (! $this->filesystem->exists($dirname = dirname($destination))) {
                $this->filesystem->makeDirectory($dirname, 0755, true);
            }

            if ($this->filesystem->isDirectory($source)) {
                $this->filesystem->copyDirectory($source, $destination);
            } else {
                $this->filesystem->copy($source, $destination);
            }

            $this->output->writeln("Copied [<comment>{$source}</comment>] to [<comment>{$destination}</comment>]");
        }

        $this->info('Publishing complete.');
    }

    protected function promptForProvider(Collection $publishes): ?string
    {
        $choice = $this->choice(
            "Which package/provider's files would you like to publish?",
            $publishes->keys()
                ->prepend($all = 'All providers')
                ->all()
        );
        if ($choice == $all || is_null($choice)) {
            return null;
        }

        return $choice;
    }

    protected function getPackagePublishes(): array
    {
        $extra = Composer::getMergedExtra();
        $packages = array_map(
            fn (array $package) => array_merge(
                Arr::wrap(($package['hyperf'] ?? []) ?? []),
                Arr::wrap(($package['hypervel'] ?? []) ?? []),
            ),
            $extra
        );
        $packages = array_filter($packages, fn ($provider) => count($provider));

        $publishes = [];
        foreach ($packages as $packageName => $extra) {
            $hyperfPublishes = array_map(
                fn ($provider) => (new $provider())()['publish'] ?? [],
                Arr::wrap($extra['config'])
            );
            if (! $hyperfPublishes = Arr::flatten($hyperfPublishes, 1)) {
                continue;
            }

            $publishes[$packageName] = array_map(function (array $config) {
                return [
                    'source' => $config['source'],
                    'destination' => $this->replaceDestination($config['destination']),
                ];
            }, $hyperfPublishes);
        }

        $providers = ServiceProvider::publishableProviders();
        foreach ($providers as $provider) {
            $group = $this->option('tag') ?? null;
            if (! $laravelPublishes = ServiceProvider::pathsToPublish($provider, $group)) {
                continue;
            }

            foreach ($laravelPublishes as $key => $value) {
                $publishes[$provider][] = [
                    'source' => $key,
                    'destination' => $this->replaceDestination($value),
                ];
            }
        }

        return $publishes;
    }

    protected function replaceDestination(string $destination): string
    {
        $result = Str::replace('/config/autoload/', '/config/', $destination);
        return Str::replace('/storage/view/', '/resources/views/vendor/', $result);
    }
}
