<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Concerns\CreatesMatchingTest;
use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\text;

#[AsCommand(name: 'make:notification')]
class NotificationMakeCommand extends GeneratorCommand
{
    use CreatesMatchingTest;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:notification
                    {name : The name of the notification}
                    {--f|force : Create the class even if the notification already exists}
                    {--m|markdown= : Create a new Markdown template for the notification}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new notification class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Notification';

    /**
     * Configure the command's default values.
     */
    #[Override]
    protected function configureDefaults(): void
    {
        // Default to false to distinguish "not passed" from "passed with no value"...
        $this->getDefinition()->getOption('markdown')->setDefault(false);
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

        return static::SUCCESS;
    }

    /**
     * Write the Markdown template for the notification.
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

        $this->components->info(sprintf('%s [%s] created successfully.', 'Markdown', $path));
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass(string $name): string
    {
        $class = parent::buildClass($name);

        if ($this->option('markdown') !== false) {
            $class = str_replace(['DummyView', '{{ view }}'], $this->getView(), $class);
        }

        return $class;
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->option('markdown') !== false
            ? $this->resolveStubPath('/stubs/markdown-notification.stub')
            : $this->resolveStubPath('/stubs/notification.stub');
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
        return $rootNamespace . '\Notifications';
    }

    /**
     * Interact further with the user if they were prompted for missing arguments.
     */
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        if ($this->didReceiveOptions($input)) {
            return;
        }

        $wantsMarkdownView = confirm('Would you like to create a markdown view?');

        if ($wantsMarkdownView) {
            $markdownView = text('What should the markdown view be named?', default: $this->getView());

            $input->setOption('markdown', $markdownView);
        }
    }

    /**
     * Get the view name.
     */
    protected function getView(): string
    {
        if ($view = $this->option('markdown')) {
            return $view;
        }

        return (new Stringable($this->argument('name')))->replace('\\', '/')->explode('/')
            ->map(fn (string $path): string => Str::kebab($path))
            ->prepend('mail')
            ->implode('.');
    }
}
