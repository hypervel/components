<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class JobCommand extends GeneratorCommand
{
    protected ?string $name = 'make:job';

    protected string $description = 'Create a new job class';

    protected string $type = 'Job';

    protected function getStub(): string
    {
        if ($stub = $this->getConfig()['stub'] ?? null) {
            return $stub;
        }

        $stubName = $this->option('sync') ? 'job' : 'job.queued';

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
