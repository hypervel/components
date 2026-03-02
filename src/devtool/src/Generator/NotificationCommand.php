<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:notification')]
class NotificationCommand extends DevtoolGeneratorCommand
{
    protected ?string $name = 'make:notification';

    protected string $description = 'Create a new notification class';

    protected string $type = 'Notification';

    protected function getStub(): string
    {
        if ($stub = $this->getConfig()['stub'] ?? null) {
            return $stub;
        }

        if ($markdown = $this->option('markdown')) {
            $this->writeMarkdownTemplate($markdown);
            return __DIR__ . '/stubs/markdown-notification.stub';
        }

        return __DIR__ . '/stubs/notification.stub';
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Notifications';
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['markdown', 'm', InputOption::VALUE_OPTIONAL, 'Create a new Markdown template for the notification.', false],
        ]);
    }

    /**
     * Write the Markdown template for the mailable.
     */
    protected function writeMarkdownTemplate(string $filename): void
    {
        $path = BASE_PATH
            . '/resources/views/'
            . str_replace('.', '/', $filename)
            . '.blade.php';

        if (! is_dir(dirname($path))) {
            $this->makeDirectory($path);
        }
        file_put_contents($path, file_get_contents(__DIR__ . '/stubs/markdown.stub'));

        $this->components->info(sprintf('%s [%s] created successfully.', 'Markdown', $path));
    }
}
