<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Mcp\Server\Registrar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(
    name: 'mcp:start',
    description: 'Start the MCP Server for a given handle'
)]
class StartCommand extends Command
{
    /**
     * Start the registered local server.
     */
    public function handle(Registrar $registrar): int
    {
        $handle = $this->argument('handle');

        assert(is_string($handle));

        $server = $registrar->getLocalServer($handle);

        if ($server === null) {
            $this->components->error("MCP Server with name [{$handle}] not found. Did you register it using [Mcp::local()]?");

            return static::FAILURE;
        }

        $server();

        return static::SUCCESS;
    }

    /**
     * Get the required server handle argument.
     *
     * @return array<int, array<int, int|string>>
     */
    protected function getArguments(): array
    {
        return [
            ['handle', InputArgument::REQUIRED, 'The handle of the MCP server to start.'],
        ];
    }
}
