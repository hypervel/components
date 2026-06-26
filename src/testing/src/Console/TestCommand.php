<?php

declare(strict_types=1);

namespace Hypervel\Testing\Console;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'test', description: 'Run the application tests')]
class TestCommand extends TestCommandBase
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'test
        {--without-tty : Disable output to TTY}
        {--configuration= : Read configuration from XML file}
        {--coverage : Indicates whether coverage information should be collected}
        {--min= : Indicates the minimum threshold enforcement for coverage}
        {--p|parallel : Indicates if the tests should run in parallel}
        {--profile : Lists top 10 slowest tests}
        {--recreate-databases : Indicates if the test databases should be re-created}
        {--drop-databases : Indicates if the test databases should be dropped}
        {--without-databases : Indicates if database configuration should be performed}
        {--without-cache : Indicates if cache configuration should be performed}
    ';

    /**
     * The console command description.
     */
    protected string $description = 'Run the application tests';
}
