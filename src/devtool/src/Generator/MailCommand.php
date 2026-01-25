<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Support\Collection;
use Hyperf\Devtool\Generator\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class MailCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:mail');
    }

    public function configure()
    {
        $this->setDescription('Create a new email class');

        parent::configure();
    }

    protected function getStub(): string
    {
        if ($stub = $this->getConfig()['stub'] ?? null) {
            return $stub;
        }

        if ($markdown = $this->input->getOption('markdown')) {
            $this->writeMarkdownTemplate($markdown);
            return __DIR__ . '/stubs/markdown-mail.stub';
        }

        if ($view = $this->input->getOption('view')) {
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

        $this->output->writeln(sprintf('<info>%s [%s] created successfully.</info>', 'Markdown', $path));
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

        $this->output->writeln(sprintf('<info>%s [%s] created successfully.</info>', 'View', $path));
    }

    /**
     * Get the view name.
     */
    protected function getView(): string
    {
        $view = $this->input->getOption('markdown') ?: $this->input->getOption('view');

        if (! $view) {
            $name = str_replace('\\', '/', $this->input->getArgument('name'));

            $view = 'mail.' . (new Collection(explode('/', $name)))
                ->map(fn ($part) => Str::kebab($part))
                ->implode('.');
        }

        return $view;
    }
}
