<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Carbon\CarbonInterface;
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
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Finder\SplFileInfo;

use function Hypervel\Prompts\search;
use function Hypervel\Prompts\select;

#[AsCommand(name: 'vendor:publish')]
class VendorPublishCommand extends Command
{
    /**
     * The migration filename pattern.
     */
    protected const MIGRATION_NAME_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/';

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
     * @param null|CarbonInterface $publishedAt the time the command started
     */
    public function __construct(
        protected Filesystem $files,
        protected ?string $provider = null,
        protected array $tags = [],
        protected ?CarbonInterface $publishedAt = null,
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
            $this->hypervel->make('events')->dispatch(new VendorTagPublished($tag, $pathsToPublish));

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
        $isMigration = $this->isPublishableMigrationPath($from)
            && $this->isMigrationFilename(basename($to));

        if ($isMigration) {
            $directory = dirname($to);
            $published = $this->files->isDirectory($directory)
                ? array_map(
                    static fn (SplFileInfo $file) => $file->getPathname(),
                    $this->files->files($directory),
                )
                : [];

            $to = $this->existingMigrationPath(basename($to), $published) ?? $to;
        }

        $exists = $this->files->exists($to);

        if ((! $this->option('existing') && (! $exists || $this->option('force')))
            || ($this->option('existing') && $exists)) {
            if (! $exists && $isMigration) {
                $to = $this->ensureMigrationNameIsUpToDate($to);
            }

            $this->createParentDirectory(dirname($to));

            if (! $this->files->copy($from, $to)) {
                throw new RuntimeException("Unable to copy [{$from}] to [{$to}].");
            }

            $this->status($from, $to, 'file');
        } else {
            if ($this->option('existing')) {
                $this->components->twoColumnDetail(sprintf(
                    'File [%s] does not exist',
                    str_replace(base_path() . '/', '', $to),
                ), '<fg=yellow;options=bold>SKIPPED</>');
            } else {
                $resolvedTo = realpath($to);

                $this->components->twoColumnDetail(sprintf(
                    'File [%s] already exists',
                    str_replace(base_path() . '/', '', is_string($resolvedTo) ? $resolvedTo : $to),
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
        $updatesMigrationDates = $this->isPublishableMigrationPath($from);
        $publishedByDirectory = [];
        $indexedPublishedMigrations = false;

        foreach ($manager->listContents('from://', true)->sortByPath() as $file) {
            $path = Str::after($file['path'], 'from://');

            if ($file['type'] !== 'file') {
                continue;
            }

            $isMigration = $updatesMigrationDates && $this->isMigrationFilename(basename($path));

            if ($isMigration) {
                if (! $indexedPublishedMigrations) {
                    foreach ($manager->listContents('to://', true) as $published) {
                        if ($published['type'] !== 'file') {
                            continue;
                        }

                        $publishedPath = Str::after($published['path'], 'to://');

                        if ($this->isMigrationFilename(basename($publishedPath))) {
                            $publishedByDirectory[dirname($publishedPath)][] = $publishedPath;
                        }
                    }

                    $indexedPublishedMigrations = true;
                }

                $path = $this->existingMigrationPath(
                    basename($path),
                    $publishedByDirectory[dirname($path)] ?? [],
                ) ?? $path;
            }

            $exists = $manager->fileExists('to://' . $path);

            if (
                (! $this->option('existing') && (! $exists || $this->option('force')))
                || ($this->option('existing') && $exists)
            ) {
                if (! $exists && $isMigration) {
                    $path = $this->ensureMigrationNameIsUpToDate($path);
                }

                $manager->write('to://' . $path, $manager->read($file['path']));

                if ($isMigration && ! $exists) {
                    $publishedByDirectory[dirname($path)][] = $path;
                }
            }
        }
    }

    /**
     * Create the directory to house the published files if needed.
     */
    protected function createParentDirectory(string $directory): void
    {
        $this->files->ensureDirectoryExists($directory);
    }

    /**
     * Ensure the given migration name is up-to-date.
     *
     * Callers must first confirm the source is registered for migration publishing.
     */
    protected function ensureMigrationNameIsUpToDate(string $to): string
    {
        $filename = basename($to);

        if (! $this->isMigrationFilename($filename)) {
            return $to;
        }

        $this->publishedAt = $this->publishedAt->addSecond();

        $updated = preg_replace(
            self::MIGRATION_NAME_PATTERN,
            $this->publishedAt->format('Y_m_d_His') . '_$1',
            $filename,
        );

        return substr($to, 0, strlen($to) - strlen($filename)) . $updated;
    }

    /**
     * Determine if the path is registered for migration publishing.
     */
    protected function isPublishableMigrationPath(string $from): bool
    {
        if (
            static::$updateMigrationDates === false
            || ! is_string($source = realpath($from))
        ) {
            return false;
        }

        foreach (ServiceProvider::publishableMigrationPaths() as $path) {
            if (is_string($path = realpath($path)) && $source === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the filename is a date-prefixed migration.
     */
    protected function isMigrationFilename(string $filename): bool
    {
        return preg_match(self::MIGRATION_NAME_PATTERN, $filename) === 1;
    }

    /**
     * Find the previously published path for a migration filename.
     *
     * @param list<string> $paths
     */
    protected function existingMigrationPath(string $filename, array $paths): ?string
    {
        if (preg_match(self::MIGRATION_NAME_PATTERN, $filename, $expected) !== 1) {
            return null;
        }

        $matches = array_values(array_unique(array_filter(
            $paths,
            static function (string $path) use ($expected): bool {
                return preg_match(self::MIGRATION_NAME_PATTERN, basename($path), $actual) === 1
                    && $actual[1] === $expected[1];
            },
        )));

        sort($matches, SORT_STRING);

        if (count($matches) > 1) {
            throw new RuntimeException(sprintf(
                'Multiple published migrations match [%s]: [%s]. Remove the duplicate migrations and retry.',
                $expected[1],
                implode('], [', $matches),
            ));
        }

        // Reusing the first published filename avoids creating a migration the application may already have run.
        return $matches[0] ?? null;
    }

    /**
     * Write a status message to the console.
     */
    protected function status(string $from, string $to, string $type): void
    {
        $resolvedFrom = realpath($from);
        $resolvedTo = realpath($to);

        $from = str_replace(base_path() . '/', '', is_string($resolvedFrom) ? $resolvedFrom : $from);
        $to = str_replace(base_path() . '/', '', is_string($resolvedTo) ? $resolvedTo : $to);

        $this->components->task(sprintf(
            'Copying %s [%s] to [%s]',
            $type,
            $from,
            $to,
        ));
    }

    /**
     * Instruct the command to not update the dates on migrations when publishing.
     *
     * Boot-only. The publishing mode persists in a static property for the
     * worker lifetime and affects every subsequent vendor:publish run.
     */
    public static function dontUpdateMigrationDates(): void
    {
        static::$updateMigrationDates = false;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$updateMigrationDates = true;
    }
}
