<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Carbon\Carbon;
use Hyperf\Devtool\Generator\GeneratorCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NotificationTableCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:notifications-table');
    }

    public function configure()
    {
        $this->setDescription('Create a migration for the notifications table');
        $this->setAliases(['notifications:table']);

        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $filename = Carbon::now()->format('Y_m_d_000000') . '_create_notifications_table.php';
        $path = $this->input->getOption('path') ?: "database/migrations/{$filename}";

        // First we will check to see if the class already exists. If it does, we don't want
        // to create the class and overwrite the user's code. So, we will bail out so the
        // code is untouched. Otherwise, we will continue generating this class' files.
        if (($input->getOption('force') === false) && $this->alreadyExists($path)) {
            $output->writeln(sprintf('<fg=red>%s</>', $path . ' already exists!'));
            return 0;
        }

        // Next, we will generate the path to the location where this class' file should get
        // written. Then, we will build the class and make the proper replacements on the
        // stub files so that it gets the correctly formatted namespace and class name.
        $this->makeDirectory($path);

        file_put_contents($path, file_get_contents($this->getStub()));

        $output->writeln(sprintf('<info>%s</info>', "Migration {$filename} created successfully."));

        $this->openWithIde($path);

        return 0;
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/notifications-table.stub';
    }

    protected function alreadyExists(string $rawName): bool
    {
        return is_file(BASE_PATH . "/{$rawName}");
    }

    protected function getArguments(): array
    {
        return [];
    }

    protected function getOptions(): array
    {
        return parent::getOptions();
    }

    protected function getDefaultNamespace(): string
    {
        return '';
    }
}
