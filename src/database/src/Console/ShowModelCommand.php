<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Closure;
use Hypervel\Console\Concerns\FindsAvailableModels;
use Hypervel\Contracts\Console\PromptsForMissingInput;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Database\Eloquent\ModelInfo;
use Hypervel\Database\Eloquent\ModelInspector;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\suggest;

#[AsCommand(name: 'model:show')]
class ShowModelCommand extends DatabaseInspectionCommand implements PromptsForMissingInput
{
    use FindsAvailableModels;

    /**
     * The console command name.
     */
    protected ?string $name = 'model:show {model}';

    /**
     * The console command description.
     */
    protected string $description = 'Show information about an Eloquent model';

    /**
     * The console command signature.
     */
    protected ?string $signature = 'model:show {model : The model to show}
                {--database= : The database connection to use}
                {--json : Output the model as JSON}';

    /**
     * Execute the console command.
     */
    public function handle(ModelInspector $modelInspector): int
    {
        try {
            $info = $modelInspector->inspect(
                $this->argument('model'),
                $this->option('database')
            );
        } catch (BindingResolutionException $e) {
            $this->components->error($e->getMessage());

            return 1;
        }

        $this->display($info);

        return 0;
    }

    /**
     * Render the model information.
     */
    protected function display(ModelInfo $modelData): void
    {
        $this->option('json')
            ? $this->displayJson($modelData)
            : $this->displayCli($modelData);
    }

    /**
     * Render the model information as JSON.
     */
    protected function displayJson(ModelInfo $modelData): void
    {
        $this->output->writeln(
            (new Collection($modelData))->toJson()
        );
    }

    /**
     * Render the model information for the CLI.
     */
    protected function displayCli(ModelInfo $modelData): void
    {
        $this->newLine();

        $this->components->twoColumnDetail('<fg=green;options=bold>' . $modelData->class . '</>');
        $this->components->twoColumnDetail('Database', $modelData->database);
        $this->components->twoColumnDetail('Table', $modelData->table);

        if ($policy = $modelData->policy ?? false) {
            $this->components->twoColumnDetail('Policy', $policy);
        }

        $this->newLine();

        $this->components->twoColumnDetail(
            '<fg=green;options=bold>Attributes</>',
            'type <fg=gray>/</> <fg=yellow;options=bold>cast</>',
        );

        foreach ($modelData->attributes as $attribute) {
            $first = trim(sprintf(
                '%s %s',
                $attribute['name'],
                (new Collection(['increments', 'unique', 'nullable', 'fillable', 'hidden', 'appended']))
                    ->filter(fn ($property) => $attribute[$property])
                    ->map(fn ($property) => sprintf('<fg=gray>%s</>', $property))
                    ->implode('<fg=gray>,</> ')
            ));

            $second = (new Collection([
                $attribute['type'],
                $attribute['cast'] ? '<fg=yellow;options=bold>' . $attribute['cast'] . '</>' : null,
            ]))->filter()->implode(' <fg=gray>/</> ');

            $this->components->twoColumnDetail($first, $second);

            if ($attribute['default'] !== null) {
                $this->components->bulletList(
                    [sprintf('default: %s', $attribute['default'])],
                    OutputInterface::VERBOSITY_VERBOSE
                );
            }
        }

        $this->newLine();

        $this->components->twoColumnDetail('<fg=green;options=bold>Relations</>');

        foreach ($modelData->relations as $relation) {
            $this->components->twoColumnDetail(
                sprintf('%s <fg=gray>%s</>', $relation['name'], $relation['type']),
                $relation['related']
            );
        }

        $this->newLine();

        $this->components->twoColumnDetail('<fg=green;options=bold>Events</>');

        if ($modelData->events->count()) {
            foreach ($modelData->events as $event) {
                $this->components->twoColumnDetail(
                    sprintf('%s', $event['event']),
                    sprintf('%s', $event['class']),
                );
            }
        }

        $this->newLine();

        $this->components->twoColumnDetail('<fg=green;options=bold>Observers</>');

        if ($modelData->observers->count()) {
            foreach ($modelData->observers as $observer) {
                $this->components->twoColumnDetail(
                    sprintf('%s', $observer['event']),
                    implode(', ', $observer['observer'])
                );
            }
        }

        $this->newLine();
    }

    /**
     * Prompt for missing input arguments using the returned questions.
     *
     * @return array<string, Closure(): string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'model' => fn (): string => suggest('Which model would you like to show?', $this->findAvailableModels()),
        ];
    }
}
