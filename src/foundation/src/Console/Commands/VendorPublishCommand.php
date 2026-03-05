<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console\Commands;

use Carbon\Carbon;
use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Events\VendorTagPublished;
use Hypervel\Support\Arr;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;
use League\Flysystem\MountManager;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Prompts\search;
use function Hypervel\Prompts\select;

#[AsCommand(name: 'vendor:publish')]
class VendorPublishCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'vendor:publish
                    {--existing : Publish and overwrite only the files that have already been published}
                    {--force : Overwrite any existing files}
                    {--all : Publish assets for all service providers without prompt}
                    {--provider= : The service provider that has assets you want to publish}
                    {--tag=* : One or many tags that have assets you want to publish}';

    /**
     * The console command description.
     */
    protected string $description = 'Publish any publishable assets from vendor packages';

    /**
     * Indicates if migration dates should be updated while publishing.
     */
    protected static bool $updateMigrationDates = true;

    /**
     * Create a new command instance.
     *
     * @param Filesystem $files the filesystem instance
     * @param null|string $provider the provider to publish
     * @param array $tags the tags to publish
     * @param null|Carbon $publishedAt the time the command started
     */
    public function __construct(
        protected Filesystem $files,
        protected ?string $provider = null,
        protected array $tags = [],
        protected ?Carbon $publishedAt = null,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->publishedAt = now();

        $this->determineWhatShouldBePublished();

        foreach ($this->tags ?: [null] as $tag) {
            $this->publishTag($tag);
        }
    }

    /**
     * Determine the provider or tag(s) to publish.
     */
    protected function determineWhatShouldBePublished(): void
    {
        if ($this->option('all')) {
            return;
        }

        [$this->provider, $this->tags] = [
            $this->option('provider'), (array) $this->option('tag'),
        ];

        if (! $this->provider && ! $this->tags) {
            $this->promptForProviderOrTag();
        }
    }

    /**
     * Prompt for which provider or tag to publish.
     */
    protected function promptForProviderOrTag(): void
    {
        $choices = $this->publishableChoices();

        $choice = windows_os()
            ? select(
                "Which provider or tag's files would you like to publish?",
                $choices,
                scroll: 15,
            )
            : search(
                label: "Which provider or tag's files would you like to publish?",
                placeholder: 'Search...',
                options: fn ($search) => array_values(array_filter(
                    $choices,
                    fn ($choice) => str_contains(strtolower($choice), strtolower($search))
                )),
                scroll: 15,
            );

        if ($choice === $choices[0]) {
            return;
        }

        $this->parseChoice($choice);
    }

    /**
     * Get the choices available via the prompt.
     */
    protected function publishableChoices(): array
    {
        return array_merge(
            ['All providers and tags'],
            preg_filter('/^/', '<fg=gray>Provider:</> ', Arr::sort(ServiceProvider::publishableProviders())),
            preg_filter('/^/', '<fg=gray>Tag:</> ', Arr::sort(ServiceProvider::publishableGroups()))
        );
    }

    /**
     * Parse the answer that was given via the prompt.
     */
    protected function parseChoice(string $choice): void
    {
        [$type, $value] = explode(': ', strip_tags($choice));

        if ($type === 'Provider') {
            $this->provider = $value;
        } elseif ($type === 'Tag') {
            $this->tags = [$value];
        }
    }

    /**
     * Publish the assets for a tag.
     */
    protected function publishTag(?string $tag): void
    {
        $pathsToPublish = $this->pathsToPublish($tag);

        if ($publishing = count($pathsToPublish) > 0) {
            $this->components->info(sprintf(
                'Publishing %sassets',
                $tag ? "[{$tag}] " : '',
            ));
        }

        foreach ($pathsToPublish as $from => $to) {
            $this->publishItem($from, $to);
        }

        if ($publishing === false) {
            $this->components->info('No publishable resources for tag [' . $tag . '].');
        } else {
            $this->app['events']->dispatch(new VendorTagPublished($tag, $pathsToPublish));

            $this->newLine();
        }
    }

    /**
     * Get all of the paths to publish.
     */
    protected function pathsToPublish(?string $tag): array
    {
        return ServiceProvider::pathsToPublish(
            $this->provider,
            $tag
        );
    }

    /**
     * Publish the given item from and to the given location.
     */
    protected function publishItem(string $from, string $to): void
    {
        if ($this->files->isFile($from)) {
            $this->publishFile($from, $to);
        } elseif ($this->files->isDirectory($from)) {
            $this->publishDirectory($from, $to);
        } else {
            $this->components->error("Can't locate path: <{$from}>");
        }
    }

    /**
     * Publish the file to the given path.
     */
    protected function publishFile(string $from, string $to): void
    {
        if ((! $this->option('existing') && (! $this->files->exists($to) || $this->option('force')))
            || ($this->option('existing') && $this->files->exists($to))) {
            $to = $this->ensureMigrationNameIsUpToDate($from, $to);

            $this->createParentDirectory(dirname($to));

            $this->files->copy($from, $to);

            $this->status($from, $to, 'file');
        } else {
            if ($this->option('existing')) {
                $this->components->twoColumnDetail(sprintf(
                    'File [%s] does not exist',
                    str_replace(base_path() . '/', '', $to),
                ), '<fg=yellow;options=bold>SKIPPED</>');
            } else {
                $this->components->twoColumnDetail(sprintf(
                    'File [%s] already exists',
                    str_replace(base_path() . '/', '', realpath($to)),
                ), '<fg=yellow;options=bold>SKIPPED</>');
            }
        }
    }

    /**
     * Publish the directory to the given directory.
     */
    protected function publishDirectory(string $from, string $to): void
    {
        $visibility = PortableVisibilityConverter::fromArray([], Visibility::PUBLIC);

        $this->moveManagedFiles($from, new MountManager([
            'from' => new Flysystem(new LocalAdapter($from)),
            'to' => new Flysystem(new LocalAdapter($to, $visibility)),
        ]));

        $this->status($from, $to, 'directory');
    }

    /**
     * Move all the files in the given MountManager.
     */
    protected function moveManagedFiles(string $from, MountManager $manager): void
    {
        foreach ($manager->listContents('from://', true)->sortByPath() as $file) {
            $path = Str::after($file['path'], 'from://');

            if (
                $file['type'] === 'file'
                && (
                    (! $this->option('existing') && (! $manager->fileExists('to://' . $path) || $this->option('force')))
                    || ($this->option('existing') && $manager->fileExists('to://' . $path))
                )
            ) {
                $path = $this->ensureMigrationNameIsUpToDate($from, $path);

                $manager->write('to://' . $path, $manager->read($file['path']));
            }
        }
    }

    /**
     * Create the directory to house the published files if needed.
     */
    protected function createParentDirectory(string $directory): void
    {
        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }
    }

    /**
     * Ensure the given migration name is up-to-date.
     */
    protected function ensureMigrationNameIsUpToDate(string $from, string $to): string
    {
        if (static::$updateMigrationDates === false) {
            return $to;
        }

        $from = realpath($from);

        foreach (ServiceProvider::publishableMigrationPaths() as $path) {
            $path = realpath($path);

            if ($from === $path && preg_match('/\d{4}_(\d{2})_(\d{2})_(\d{6})_/', $to)) {
                $this->publishedAt = $this->publishedAt->addSecond();

                return preg_replace(
                    '/\d{4}_(\d{2})_(\d{2})_(\d{6})_/',
                    $this->publishedAt->format('Y_m_d_His') . '_',
                    $to,
                );
            }
        }

        return $to;
    }

    /**
     * Write a status message to the console.
     */
    protected function status(string $from, string $to, string $type): void
    {
        $from = str_replace(base_path() . '/', '', realpath($from));

        $to = str_replace(base_path() . '/', '', realpath($to));

        $this->components->task(sprintf(
            'Copying %s [%s] to [%s]',
            $type,
            $from,
            $to,
        ));
    }

    /**
     * Instruct the command to not update the dates on migrations when publishing.
     */
    public static function dontUpdateMigrationDates(): void
    {
        static::$updateMigrationDates = false;
    }
}
