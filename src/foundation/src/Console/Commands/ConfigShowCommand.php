<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console\Commands;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Console\Command;
use Hypervel\Support\Arr;

class ConfigShowCommand extends Command
{
    protected ?string $signature = 'config:show {config : The configuration file or key to show}';

    protected string $description = 'Display all of the values for a given configuration file or key';

    public function __construct(
        protected ConfigInterface $config
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $config = $this->argument('config');

        if (! $this->config->has($config)) {
            $this->fail("Configuration file or key <comment>{$config}</comment> does not exist.");
        }

        $this->newLine();
        $this->render($config);
        $this->newLine();

        return Command::SUCCESS;
    }

    /**
     * Render the configuration values.
     */
    public function render(string $name): void
    {
        $data = $this->config->get($name);

        if (! is_array($data)) {
            $this->title($name, $this->formatValue($data));

            return;
        }

        $this->title($name);

        foreach (Arr::dot($data) as $key => $value) {
            $this->components->twoColumnDetail(
                $this->formatKey($key),
                $this->formatValue($value)
            );
        }
    }

    /**
     * Render the title.
     */
    public function title(string $title, ?string $subtitle = null): void
    {
        $this->components->twoColumnDetail(
            "<fg=green;options=bold>{$title}</>",
            $subtitle,
        );
    }

    /**
     * Format the given configuration key.
     */
    protected function formatKey(string $key): string
    {
        return preg_replace_callback(
            '/(.*)\.(.*)$/',
            fn ($matches) => sprintf(
                '<fg=gray>%s ⇁</> %s',
                str_replace('.', ' ⇁ ', $matches[1]),
                $matches[2]
            ),
            $key
        );
    }

    /**
     * Format the given configuration value.
     */
    protected function formatValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => sprintf('<fg=#ef8414;options=bold>%s</>', $value ? 'true' : 'false'),
            is_null($value) => '<fg=#ef8414;options=bold>null</>',
            is_numeric($value) => "<fg=#ef8414;options=bold>{$value}</>",
            is_array($value) => '[]',
            is_object($value) => get_class($value),
            is_string($value) => $value,
            default => print_r($value, true),
        };
    }
}
