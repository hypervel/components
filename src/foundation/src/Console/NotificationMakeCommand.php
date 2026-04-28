<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Concerns\CreatesMatchingTest;
use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\text;

#[AsCommand(name: 'make:notification')]
class NotificationMakeCommand extends GeneratorCommand
{
    use CreatesMatchingTest;

    /**
     * The console command name.
     */
    protected ?string $name = 'make:notification';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new notification class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Notification';

    /**
     * Execute the console command.
     */
    public function handle(): bool|int
    {
        if (parent::handle() === false && ! $this->option('force')) {
            return static::SUCCESS;
        }

        if ($this->option('markdown')) {
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
            str_replace('.', $separator, $this->option('markdown')) . '.blade.php'
        );

        if (! $this->files->isDirectory(dirname($path))) {
            $this->files->makeDirectory(dirname($path), 0755, true);
        }

        $this->files->put($path, file_get_contents(__DIR__ . '/stubs/markdown.stub'));

        $this->components->info(sprintf('%s [%s] created successfully.', 'Markdown', $path));
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass(string $name): string
    {
        $class = parent::buildClass($name);

        if ($this->option('markdown')) {
            $class = str_replace(['DummyView', '{{ view }}'], $this->option('markdown'), $class);
        }

        return $class;
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->option('markdown')
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
            $defaultMarkdownView = (new Collection(explode('/', str_replace('\\', '/', $this->argument('name')))))
                ->map(fn ($path) => Str::kebab($path))
                ->prepend('mail')
                ->implode('.');

            $markdownView = text('What should the markdown view be named?', default: $defaultMarkdownView);

            $input->setOption('markdown', $markdownView);
        }
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the notification already exists'],
            ['markdown', 'm', InputOption::VALUE_OPTIONAL, 'Create a new Markdown template for the notification'],
        ];
    }
}
