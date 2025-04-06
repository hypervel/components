<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console\Commands;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Hypervel\Support\Composer;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;

class AboutCommand extends Command
{
    protected ?string $signature = 'about {--only= : The section to display}
                {--json : Output the information as JSON}';

    protected string $description = 'Display basic information about your application';

    public function __construct(
        protected ConfigInterface $config,
        protected Composer $composer,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $only = $this->sections();
        $information = $this->gatherApplicationInformation();

        if ($only = $this->sections()) {
            $information = array_filter($information, function ($section) use ($only) {
                return in_array($this->toSearchKeyword($section), $only);
            }, ARRAY_FILTER_USE_KEY);
        }

        $this->display($information);
    }

    /**
     * Display the application information.
     */
    protected function display(array $information): void
    {
        $this->option('json')
            ? $this->displayJson($information)
            : $this->displayDetail($information);
    }

    /**
     * Display the application information as a detail view.
     */
    protected function displayDetail(array $information): void
    {
        foreach ($information as $section => $data) {
            $this->newLine();

            $this->components->twoColumnDetail('  <fg=green;options=bold>' . $section . '</>');

            foreach ($data as $key => $value) {
                $this->components->twoColumnDetail($key, value($value, false));
            }
        }
    }

    /**
     * Display the application information as JSON.
     */
    protected function displayJson(array $information): void
    {
        $output = [];
        foreach ($information as $section => $data) {
            $section = $this->toSearchKeyword($section);
            $output[$section] = array_map(function ($value, $key) {
                return [
                    $this->toSearchKeyword($key) => value($value, true),
                ];
            }, $data, array_keys($data));
        }

        $this->output->writeln(strip_tags(json_encode($output)));
    }

    /**
     * Gather information about the application.
     */
    protected function gatherApplicationInformation(): array
    {
        $data = [];

        $formatEnabledStatus = fn ($value) => $value ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF';
        $formatCachedStatus = fn ($value) => $value ? '<fg=green;options=bold>CACHED</>' : '<fg=yellow;options=bold>NOT CACHED</>';

        $data['Environment'] = [
            'Application Name' => $this->config->get('app.name'),
            'Hypervel Version' => $this->app->version(), /* @phpstan-ignore-line */
            'PHP Version' => phpversion(),
            'Swoole Version' => swoole_version(),
            'Composer Version' => $this->composer->getVersion() ?? '<fg=yellow;options=bold>-</>',
            'Environment' => $this->app->environment(), /* @phpstan-ignore-line */
            'Debug Mode' => $this->format($this->config->get('app.debug'), console: $formatEnabledStatus),
            'URL' => Str::of($this->config->get('app.url'))->replace(['http://', 'https://'], ''),
            'Timezone' => $this->config->get('app.timezone'),
            'Locale' => $this->config->get('app.locale'),
        ];

        $data['Cache'] = [
            'Runtime Proxy' => static::format($this->hasPhpFiles($this->app->basePath('runtime/container'), 'cache'), console: $formatCachedStatus), /* @phpstan-ignore-line */
            'Views' => static::format($this->hasPhpFiles($this->app->storagePath('framework/views')), console: $formatCachedStatus), /* @phpstan-ignore-line */
        ];

        $data['Drivers'] = array_filter([
            'Broadcasting' => $this->config->get('broadcasting.default'),
            'Cache' => $this->config->get('cache.default'),
            'Database' => $this->config->get('database.default'),
            'Logs' => function ($json) {
                $logChannel = $this->config->get('logging.default');

                if ($this->config->get('logging.channels.' . $logChannel . '.driver') === 'stack') {
                    $secondary = new Collection($this->config->get('logging.channels.' . $logChannel . '.channels'));

                    return value(static::format(
                        value: $logChannel,
                        console: fn ($value) => '<fg=yellow;options=bold>' . $value . '</> <fg=gray;options=bold>/</> ' . $secondary->implode(', '),
                        json: fn () => $secondary->all(),
                    ), $json);
                }
                $logs = $logChannel;

                return $logs;
            },
            'Mail' => $this->config->get('mail.default'),
            'Queue' => $this->config->get('queue.default'),
            'Session' => $this->config->get('session.driver'),
        ]);

        return $data;
    }

    /**
     * Determine whether the given directory has PHP files.
     */
    protected function hasPhpFiles(string $path, string $extension = 'php'): bool
    {
        return count(glob($path . "/*.{$extension}")) > 0;
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
     * @param mixed $value
     * @param null|(Closure(mixed):(mixed)) $console
     * @param null|(Closure(mixed):(mixed)) $json
     * @return Closure(bool):mixed
     */
    protected function format($value, ?Closure $console = null, ?Closure $json = null): mixed
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
}
