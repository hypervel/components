<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Hypervel\Support\Composer;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'about')]
class AboutCommand extends Command
{
    protected ?string $signature = 'about {--only= : The section to display}
                {--json : Output the information as JSON}';

    protected string $description = 'Display basic information about your application';

    /**
     * The data to display.
     */
    protected static array $data = [];

    /**
     * The registered callables that add custom data to the command output.
     */
    protected static array $customDataResolvers = [];

    /**
     * Create a new command instance.
     */
    public function __construct(
        protected Composer $composer,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->gatherApplicationInformation();

        (new Collection(static::$data))
            ->map(
                fn ($items) => (new Collection($items))
                    ->map(function ($value) {
                        if (is_array($value)) {
                            return [$value];
                        }

                        if (is_string($value)) {
                            $value = $this->hypervel->make($value);
                        }

                        return (new Collection($this->hypervel->call($value)))
                            ->map(fn ($value, $key) => [$key, $value])
                            ->values()
                            ->all();
                    })->flatten(1)
            )
            ->sortBy(function ($data, $key) {
                $index = array_search($key, ['Environment', 'Cache', 'Drivers']);

                return $index === false ? 99 : $index;
            })
            ->filter(function ($data, $key) {
                return $this->option('only') ? in_array($this->toSearchKeyword($key), $this->sections()) : true;
            })
            ->pipe(fn ($data) => $this->display($data));

        $this->newLine();

        return 0;
    }

    /**
     * Display the application information.
     */
    protected function display(Collection $data): void
    {
        $this->option('json') ? $this->displayJson($data) : $this->displayDetail($data);
    }

    /**
     * Display the application information as a detail view.
     */
    protected function displayDetail(Collection $data): void
    {
        $data->each(function ($data, $section) {
            $this->newLine();

            $this->components->twoColumnDetail('  <fg=green;options=bold>' . $section . '</>');

            $data->pipe(fn ($data) => $section !== 'Environment' ? $data->sort() : $data)->each(function ($detail) {
                [$label, $value] = $detail;

                $this->components->twoColumnDetail($label, value($value, false));
            });
        });
    }

    /**
     * Display the application information as JSON.
     */
    protected function displayJson(Collection $data): void
    {
        $output = $data->flatMap(function ($data, $section) {
            return [
                (new Stringable($section))->snake()->value() => $data->mapWithKeys(fn ($item, $key) => [
                    $this->toSearchKeyword($item[0]) => value($item[1], true),
                ]),
            ];
        });

        $this->output->writeln(strip_tags(json_encode($output)));
    }

    /**
     * Gather information about the application.
     */
    protected function gatherApplicationInformation(): void
    {
        self::$data = [];

        $formatEnabledStatus = fn ($value) => $value ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF';
        $formatCachedStatus = fn ($value) => $value ? '<fg=green;options=bold>CACHED</>' : '<fg=yellow;options=bold>NOT CACHED</>';
        $formatStorageLinkedStatus = fn ($value) => $value ? '<fg=green;options=bold>LINKED</>' : '<fg=yellow;options=bold>NOT LINKED</>';

        static::addToSection('Environment', fn () => [
            'Application Name' => config('app.name'),
            'Hypervel Version' => $this->hypervel->version(),
            'PHP Version' => phpversion(),
            'Swoole Version' => swoole_version(),
            'Composer Version' => $this->composer->getVersion() ?? '<fg=yellow;options=bold>-</>',
            'Environment' => $this->hypervel->environment(),
            'Debug Mode' => static::format(config('app.debug'), console: $formatEnabledStatus),
            'URL' => Str::of(config('app.url'))->replace(['http://', 'https://'], ''),
            'Maintenance Mode' => static::format($this->hypervel->isDownForMaintenance(), console: $formatEnabledStatus),
            'Timezone' => config('app.timezone'),
            'Locale' => config('app.locale'),
        ]);

        static::addToSection('Cache', fn () => [
            'Config' => static::format($this->hypervel->configurationIsCached(), console: $formatCachedStatus),
            'Events' => static::format($this->hypervel->eventsAreCached(), console: $formatCachedStatus),
            'Routes' => static::format($this->hypervel->routesAreCached(), console: $formatCachedStatus),
            'AOP Proxies' => static::format($this->hasPhpFiles($this->hypervel->storagePath('framework/aop'), 'cache'), console: $formatCachedStatus),
            'Views' => static::format($this->hasPhpFiles(config('view.compiled')), console: $formatCachedStatus),
        ]);

        static::addToSection('Drivers', fn () => array_filter([
            'Broadcasting' => config('broadcasting.default'),
            'Cache' => function ($json) {
                $cacheStore = config('cache.default');

                if (config('cache.stores.' . $cacheStore . '.driver') === 'failover') {
                    $secondary = new Collection(config('cache.stores.' . $cacheStore . '.stores'));

                    return value(static::format(
                        value: $cacheStore,
                        console: fn ($value) => '<fg=yellow;options=bold>' . $value . '</> <fg=gray;options=bold>/</> ' . $secondary->implode(', '),
                        json: fn () => $secondary->all(),
                    ), $json);
                }

                return $cacheStore;
            },
            'Database' => config('database.default'),
            'Logs' => function ($json) {
                $logChannel = config('logging.default');

                if (config('logging.channels.' . $logChannel . '.driver') === 'stack') {
                    $secondary = new Collection(config('logging.channels.' . $logChannel . '.channels'));

                    return value(static::format(
                        value: $logChannel,
                        console: fn ($value) => '<fg=yellow;options=bold>' . $value . '</> <fg=gray;options=bold>/</> ' . $secondary->implode(', '),
                        json: fn () => $secondary->all(),
                    ), $json);
                }

                return $logChannel;
            },
            'Mail' => function ($json) {
                $mailMailer = config('mail.default');

                if (in_array(config('mail.mailers.' . $mailMailer . '.transport'), ['failover', 'roundrobin'])) {
                    $secondary = new Collection(config('mail.mailers.' . $mailMailer . '.mailers'));

                    return value(static::format(
                        value: $mailMailer,
                        console: fn ($value) => '<fg=yellow;options=bold>' . $value . '</> <fg=gray;options=bold>/</> ' . $secondary->implode(', '),
                        json: fn () => $secondary->all(),
                    ), $json);
                }

                return $mailMailer;
            },
            'Queue' => function ($json) {
                $queueConnection = config('queue.default');

                if (config('queue.connections.' . $queueConnection . '.driver') === 'failover') {
                    $secondary = new Collection(config('queue.connections.' . $queueConnection . '.connections'));

                    return value(static::format(
                        value: $queueConnection,
                        console: fn ($value) => '<fg=yellow;options=bold>' . $value . '</> <fg=gray;options=bold>/</> ' . $secondary->implode(', '),
                        json: fn () => $secondary->all(),
                    ), $json);
                }

                return $queueConnection;
            },
            'Scout' => config('scout.driver'),
            'Session' => config('session.driver'),
        ]));

        static::addToSection('Storage', fn () => [
            ...$this->determineStoragePathLinkStatus($formatStorageLinkedStatus),
        ]);

        (new Collection(static::$customDataResolvers))->each->__invoke();
    }

    /**
     * Determine storage symbolic link status.
     *
     * @return array<string, mixed>
     */
    protected function determineStoragePathLinkStatus(callable $formatStorageLinkedStatus): array
    {
        return (new Collection(config('filesystems.links', [])))
            ->mapWithKeys(function ($target, $link) use ($formatStorageLinkedStatus) {
                $path = Str::replace(public_path(), '', $link);

                return [public_path($path) => static::format(is_link($link), console: $formatStorageLinkedStatus)];
            })
            ->toArray();
    }

    /**
     * Determine whether the given directory has PHP files.
     */
    protected function hasPhpFiles(string $path, string $extension = 'php'): bool
    {
        return count(glob($path . "/*.{$extension}")) > 0;
    }

    /**
     * Add additional data to the output of the "about" command.
     */
    public static function add(string $section, callable|string|array $data, ?string $value = null): void
    {
        static::$customDataResolvers[] = fn () => static::addToSection($section, $data, $value);
    }

    /**
     * Add additional data to the output of the "about" command.
     */
    protected static function addToSection(string $section, callable|string|array $data, ?string $value = null): void
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                self::$data[$section][] = [$key, $value];
            }
        } elseif (is_callable($data) || ($value === null && class_exists($data))) {
            self::$data[$section][] = $data;
        } else {
            self::$data[$section][] = [$data, $value];
        }
    }

    /**
     * Get the sections provided to the command.
     */
    protected function sections(): array
    {
        return (new Collection(explode(',', $this->option('only') ?? '')))
            ->filter()
            ->map(fn ($only) => $this->toSearchKeyword($only))
            ->all();
    }

    /**
     * Materialize a function that formats a given value for CLI or JSON output.
     *
     * @param null|(Closure(mixed): mixed) $console
     * @param null|(Closure(mixed): mixed) $json
     * @return Closure(bool): mixed
     */
    public static function format(mixed $value, ?Closure $console = null, ?Closure $json = null): Closure
    {
        return function ($isJson) use ($value, $console, $json) {
            if ($isJson === true && $json instanceof Closure) {
                return value($json, $value);
            }
            if ($isJson === false && $console instanceof Closure) {
                return value($console, $value);
            }

            return value($value);
        };
    }

    /**
     * Format the given string for searching.
     */
    protected function toSearchKeyword(string $value): string
    {
        return (new Stringable($value))->lower()->snake()->value();
    }

    /**
     * Flush the registered about data.
     */
    public static function flushState(): void
    {
        static::$data = [];

        static::$customDataResolvers = [];
    }
}
