<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;

class ChannelCommand extends GeneratorCommand
{
    protected ?string $name = 'make:channel';

    protected string $description = 'Create a new channel class';

    protected string $type = 'Channel';

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $stub = parent::replaceClass($stub, $name);
        $modelNamespace = $this->getConfig()['uses'] ?? 'App\Models\User';

        $modelParts = explode('\\', $modelNamespace);
        $userModel = end($modelParts);

        return str_replace(
            ['%NAMESPACE_USER_MODEL%', '%USER_MODEL%'],
            [$modelNamespace, $userModel],
            $stub
        );
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/channel.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Broadcasting';
    }
}
