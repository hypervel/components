<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:mail')]
class MailCommand extends GeneratorCommand
{
    protected ?string $name = 'make:mail';

    protected string $description = 'Create a new email class';

    protected string $type = 'Mailable';

    protected function getStub(): string
    {
        if ($stub = $this->getConfig()['stub'] ?? null) {
            return $stub;
        }

        if ($markdown = $this->option('markdown')) {
            $this->writeMarkdownTemplate($markdown);
            return __DIR__ . '/stubs/markdown-mail.stub';
        }

        if ($view = $this->option('view')) {
            $this->writeView($view);
            return __DIR__ . '/stubs/view-mail.stub';
        }

        return __DIR__ . '/stubs/mail.stub';
    }

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $stub = parent::replaceClass($stub, $name);
        $subject = Str::headline(str_replace($this->getNamespace($name) . '\\', '', $name));
        $view = $this->getView();

        return str_replace(
            ['%SUBJECT%', '%VIEW%'],
            [$subject, $view],
            $stub
        );
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Mail';
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['markdown', 'm', InputOption::VALUE_OPTIONAL, 'Create a new Markdown template for the notification.', false],
            ['view', null, InputOption::VALUE_OPTIONAL, 'Create a new Blade template for the mailable.', false],
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

    /**
     * Write the Blade template for the mailable.
     */
    protected function writeView(string $filename): void
    {
        $path = BASE_PATH
            . '/resources/views/'
            . str_replace('.', '/', $this->getView())
            . '.blade.php';

        if (! is_dir(dirname($path))) {
            $this->makeDirectory($path);
        }

        $stub = str_replace(
            '{{ quote }}',
            'Hypervel is a Laravel-style framework with native coroutine support for ultra-high performance.',
            file_get_contents(__DIR__ . '/stubs/view.stub')
        );

        file_put_contents($path, $stub);

        $this->components->info(sprintf('%s [%s] created successfully.', 'View', $path));
    }

    /**
     * Get the view name.
     */
    protected function getView(): string
    {
        $view = $this->option('markdown') ?: $this->option('view');

        if (! $view) {
            $name = str_replace('\\', '/', $this->argument('name'));

            $view = 'mail.' . (new Collection(explode('/', $name)))
                ->map(fn ($part) => Str::kebab($part))
                ->implode('.');
        }

        return $view;
    }
}
