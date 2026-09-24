<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Concerns\CreatesMatchingTest;
use Hypervel\Console\GeneratorCommand;
use Hypervel\Foundation\Inspiring;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\select;

#[AsCommand(name: 'make:mail')]
class MailMakeCommand extends GeneratorCommand
{
    use CreatesMatchingTest;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:mail
                    {name : The name of the mailable}
                    {--f|force : Create the class even if the mailable already exists}
                    {--m|markdown= : Create a new Markdown template for the mailable}
                    {--view= : Create a new Blade template for the mailable}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new email class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Mailable';

    /**
     * Configure the command's default values.
     */
    #[Override]
    protected function configureDefaults(): void
    {
        // These default to false (not null) so that handle() can distinguish "not
        // passed at all" from "passed with no value", which can't be expressed as a
        // literal boolean default in the signature above.
        $this->getDefinition()->getOption('markdown')->setDefault(false);
        $this->getDefinition()->getOption('view')->setDefault(false);
    }

    /**
     * Execute the console command.
     */
    public function handle(): bool|int
    {
        if (parent::handle() === false && ! $this->option('force')) {
            return static::SUCCESS;
        }

        if ($this->option('markdown') !== false) {
            $this->writeMarkdownTemplate();
        }

        if ($this->option('view') !== false) {
            $this->writeView();
        }

        return static::SUCCESS;
    }

    /**
     * Write the Markdown template for the mailable.
     */
    protected function writeMarkdownTemplate(): void
    {
        $separator = '/';

        if (windows_os()) {
            $separator = '\\';
        }

        $path = $this->viewPath(
            str_replace('.', $separator, $this->getView()) . '.blade.php'
        );

        if ($this->files->exists($path)) {
            $this->components->error(sprintf('%s [%s] already exists.', 'Markdown view', $path));

            return;
        }

        $this->files->ensureDirectoryExists(dirname($path));

        $this->replaceFile($path, $this->files->get(__DIR__ . '/stubs/markdown.stub'));

        $this->components->info(sprintf('%s [%s] created successfully.', 'Markdown view', $path));
    }

    /**
     * Write the Blade template for the mailable.
     */
    protected function writeView(): void
    {
        $separator = '/';

        if (windows_os()) {
            $separator = '\\';
        }

        $path = $this->viewPath(
            str_replace('.', $separator, $this->getView()) . '.blade.php'
        );

        if ($this->files->exists($path)) {
            $this->components->error(sprintf('%s [%s] already exists.', 'View', $path));

            return;
        }

        $this->files->ensureDirectoryExists(dirname($path));

        $stub = str_replace(
            '{{ quote }}',
            Inspiring::quotes()->random(),
            $this->files->get(__DIR__ . '/stubs/view.stub')
        );

        $this->replaceFile($path, $stub);

        $this->components->info(sprintf('%s [%s] created successfully.', 'View', $path));
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass(string $name): string
    {
        $class = str_replace(
            '{{ subject }}',
            Str::headline(str_replace($this->getNamespace($name) . '\\', '', $name)),
            parent::buildClass($name)
        );

        if ($this->option('markdown') !== false || $this->option('view') !== false) {
            $class = str_replace(['DummyView', '{{ view }}'], $this->getView(), $class);
        }

        return $class;
    }

    /**
     * Get the view name.
     */
    protected function getView(): string
    {
        $view = $this->option('markdown') ?: $this->option('view');

        if (! $view) {
            $name = str_replace('\\', '/', $this->argument('name'));

            $view = 'mail.' . (new Stringable($name))->explode('/')
                ->map(fn ($part) => Str::kebab($part))
                ->implode('.');
        }

        return $view;
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        if ($this->option('markdown') !== false) {
            return $this->resolveStubPath('/stubs/markdown-mail.stub');
        }

        if ($this->option('view') !== false) {
            return $this->resolveStubPath('/stubs/view-mail.stub');
        }

        return $this->resolveStubPath('/stubs/mail.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->hypervel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__ . $stub;
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Mail';
    }

    /**
     * Interact further with the user if they were prompted for missing arguments.
     */
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        if ($this->didReceiveOptions($input)) {
            return;
        }

        $type = select('Would you like to create a view?', [
            'markdown' => 'Markdown View',
            'view' => 'Empty View',
            'none' => 'No View',
        ]);

        if ($type !== 'none') {
            $input->setOption($type, null);
        }
    }
}
