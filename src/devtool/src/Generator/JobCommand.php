<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class JobCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:job');
    }

    public function configure()
    {
        $this->setDescription('Create a new job class');

        parent::configure();
    }

    protected function getStub(): string
    {
        if ($stub = $this->getConfig()['stub'] ?? null) {
            return $stub;
        }

        $stubName = $this->input->getOption('sync') ? 'job' : 'job.queued';

        return __DIR__ . "/stubs/{$stubName}.stub";
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Jobs';
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['sync', null, InputOption::VALUE_NONE, 'Indicates that job should be synchronous'],
        ]);
    }
}
