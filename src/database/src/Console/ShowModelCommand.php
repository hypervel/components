<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Hypervel\Console\Command;
use Hypervel\Database\Eloquent\ModelInspector;
use Hypervel\Support\Collection;
use Psr\Container\ContainerExceptionInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Display information about an Eloquent model.
 */
class ShowModelCommand extends Command
{
    protected ?string $signature = 'model:show
        {model : The model to show}
        {--database= : The database connection to use}
        {--json : Output the model as JSON}';

    protected string $description = 'Show information about an Eloquent model';

    public function __construct(
        protected ModelInspector $modelInspector
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $info = $this->modelInspector->inspect(
                $this->argument('model'),
                $this->option('database')
            );
        } catch (ContainerExceptionInterface $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->display($info);

        return self::SUCCESS;
    }

    /**
     * Render the model information.
     */
    protected function display(array $modelData): void
    {
        $this->option('json')
            ? $this->displayJson($modelData)
            : $this->displayCli($modelData);
    }

    /**
     * Render the model information as JSON.
     */
    protected function displayJson(array $modelData): void
    {
        $this->output->writeln(
            (new Collection($modelData))->toJson()
        );
    }

    /**
     * Render the model information for the CLI.
     */
    protected function displayCli(array $modelData): void
    {
        $this->newLine();

        $this->components->twoColumnDetail('<fg=green;options=bold>' . $modelData['class'] . '</>');
        $this->components->twoColumnDetail('Database', $modelData['database']);
        $this->components->twoColumnDetail('Table', $modelData['table']);

        if ($policy = $modelData['policy'] ?? false) {
            $this->components->twoColumnDetail('Policy', $policy);
        }

        $this->newLine();

        $this->components->twoColumnDetail(
            '<fg=green;options=bold>Attributes</>',
            'type <fg=gray>/</> <fg=yellow;options=bold>cast</>',
        );

        foreach ($modelData['attributes'] as $attribute) {
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

        foreach ($modelData['relations'] as $relation) {
            $this->components->twoColumnDetail(
                sprintf('%s <fg=gray>%s</>', $relation['name'], $relation['type']),
                $relation['related']
            );
        }

        $this->newLine();

        $this->components->twoColumnDetail('<fg=green;options=bold>Events</>');

        if (count($modelData['events']) > 0) {
            foreach ($modelData['events'] as $event) {
                $this->components->twoColumnDetail(
                    sprintf('%s', $event['event']),
                    sprintf('%s', $event['class']),
                );
            }
        }

        $this->newLine();

        $this->components->twoColumnDetail('<fg=green;options=bold>Observers</>');

        if (count($modelData['observers']) > 0) {
            foreach ($modelData['observers'] as $observer) {
                $this->components->twoColumnDetail(
                    sprintf('%s', $observer['event']),
                    implode(', ', $observer['observer'])
                );
            }
        }

        $this->newLine();
    }
}
