<?php

declare(strict_types=1);

namespace Hypervel\Tinker\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Env;
use Hypervel\Tinker\ClassAliasAutoloader;
use Psy\Configuration;
use Psy\Shell;
use Psy\VersionUpdater\Checker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

#[AsCommand(name: 'tinker')]
class TinkerCommand extends Command
{
    /**
     * Artisan commands to include in the tinker shell.
     */
    protected array $commandWhitelist = [
        'clear-compiled', 'down', 'env', 'inspire', 'migrate', 'migrate:install', 'optimize', 'up',
    ];

    /**
     * The console command name.
     */
    protected ?string $name = 'tinker';

    /**
     * The console command description.
     */
    protected string $description = 'Interact with your application';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->getApplication()->setCatchExceptions(false);

        $config = Configuration::fromInput($this->input);
        $config->setUpdateCheck(Checker::NEVER);

        // Disable pcntl — pcntl_fork is fundamentally incompatible with Swoole's
        // coroutine scheduler, event loop, and shared memory. Forking a Swoole
        // process duplicates all coroutine state and causes undefined behavior.
        $config->setUsePcntl(false);

        $appConfig = $this->getHypervel()->make('config');
        $config->setTrustProject($appConfig->get('tinker.trust_project'));

        $config->getPresenter()->addCasters(
            $this->getCasters()
        );

        if ($this->option('execute')) {
            $config->setRawOutput(true);
        }

        $shell = new Shell($config);
        $shell->addCommands($this->getCommands());
        $shell->setIncludes($this->argument('include'));

        $path = Env::get('COMPOSER_VENDOR_DIR', $this->getHypervel()->basePath() . DIRECTORY_SEPARATOR . 'vendor');

        $path .= '/composer/autoload_classmap.php';

        $loader = ClassAliasAutoloader::register(
            $shell,
            $path,
            $appConfig->get('tinker.alias', []),
            $appConfig->get('tinker.dont_alias', [])
        );

        if ($code = $this->option('execute')) {
            try {
                $shell->setOutput($this->output);
                $shell->execute($code, true);
            } catch (Throwable $e) {
                $shell->writeException($e);

                return 1;
            } finally {
                $loader->unregister();
            }

            return 0;
        }

        try {
            return $shell->run();
        } finally {
            $loader->unregister();
        }
    }

    /**
     * Get artisan commands to pass through to PsySH.
     */
    protected function getCommands(): array
    {
        $commands = [];

        foreach ($this->getApplication()->all() as $name => $command) {
            if (in_array($name, $this->commandWhitelist, true)) {
                $commands[] = $command;
            }
        }

        $config = $this->getHypervel()->make('config');

        foreach ($config->get('tinker.commands', []) as $command) {
            $commands[] = $this->getApplication()->addCommand(
                $this->getHypervel()->make($command)
            );
        }

        return $commands;
    }

    /**
     * Get an array of Hypervel tailored casters.
     */
    protected function getCasters(): array
    {
        $casters = [
            'Hypervel\Support\Collection' => 'Hypervel\Tinker\TinkerCaster::castCollection',
            'Hypervel\Support\HtmlString' => 'Hypervel\Tinker\TinkerCaster::castHtmlString',
            'Hypervel\Support\Stringable' => 'Hypervel\Tinker\TinkerCaster::castStringable',
        ];

        if (class_exists('Hypervel\Database\Eloquent\Model')) {
            $casters['Hypervel\Database\Eloquent\Model'] = 'Hypervel\Tinker\TinkerCaster::castModel';
        }

        if (class_exists('Hypervel\Process\ProcessResult')) {
            $casters['Hypervel\Process\ProcessResult'] = 'Hypervel\Tinker\TinkerCaster::castProcessResult';
        }

        if (class_exists('Hypervel\Foundation\Application')) {
            $casters['Hypervel\Foundation\Application'] = 'Hypervel\Tinker\TinkerCaster::castApplication';
        }

        $config = $this->getHypervel()->make('config');

        return array_merge($casters, (array) $config->get('tinker.casters', []));
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['include', InputArgument::IS_ARRAY, 'Include file(s) before starting tinker'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['execute', null, InputOption::VALUE_OPTIONAL, 'Execute the given code using Tinker'],
        ];
    }
}
