<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Console\Commands;

use Exception;
use Hypervel\Console\Command;
use Hypervel\Mcp\Server\Registrar;
use Hypervel\Routing\Exceptions\UrlGenerationException;
use Hypervel\Routing\Route;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Process;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\PhpExecutableFinder;

#[AsCommand(
    name: 'mcp:inspector',
    description: 'Open the MCP Inspector tool to debug and test MCP Servers'
)]
class InspectorCommand extends Command
{
    /**
     * Start the Inspector for a registered server.
     */
    public function handle(Registrar $registrar): int
    {
        $handle = $this->argument('handle');

        if (! is_string($handle)) {
            $this->components->error('Please pass a valid MCP server handle');

            return static::FAILURE;
        }

        $this->components->info("Starting the MCP Inspector for server [{$handle}]");

        $localServer = $registrar->getLocalServer($handle);
        $route = $registrar->getWebServer($handle);

        $servers = $registrar->servers();
        if ($servers === []) {
            $this->components->error('No MCP servers found. Please run `php artisan make:mcp-server [name]`');

            return static::FAILURE;
        }

        // Only one server, we should just run it for them
        if (count($servers) === 1) {
            $server = reset($servers);
            [$localServer, $route] = match (true) {
                is_callable($server) => [$server, null],
                $server::class === Route::class => [null, $server],
                default => [null, null],
            };
        }

        if (is_null($localServer) && is_null($route)) {
            $availableServers = Arr::map(array_keys($servers), fn ($server): string => "[{$server}]");
            $this->components->error('MCP Server with name [' . $handle . '] not found. Available servers: ' . Arr::join($availableServers, ', '));

            return static::FAILURE;
        }

        $env = [];

        if (count($servers) === 1) {
            $handle = (string) array_key_first($servers);
        }

        if (is_string($host = $this->option('host'))) {
            $env['HOST'] = $host;
        }

        if (is_string($port = $this->option('port'))) {
            $env['CLIENT_PORT'] = $port;
        }

        if ($localServer !== null) {
            $artisanPath = base_path('artisan');

            $serverConfig = [
                'type' => 'stdio',
                'command' => $this->phpBinary(),
                'args' => [$artisanPath, 'mcp:start', $handle],
            ];

            $guidance = [
                'Transport Type' => 'STDIO',
                'Command' => $this->phpBinary(),
                'Arguments' => implode(' ', [
                    str_replace('\\', '/', $artisanPath),
                    'mcp:start',
                    $handle,
                ]),
            ];
        } else {
            try {
                $serverUrl = $this->serverUrl($route);
            } catch (UrlGenerationException) {
                $this->components->error('Every route parameter needs a value to inspect this server');

                return static::FAILURE;
            }

            $serverConfig = [
                'type' => 'http',
                'url' => $serverUrl,
            ];

            $guidance = [
                'Transport Type' => 'Streamable HTTP',
                'URL' => $serverUrl,
            ];

            if (parse_url($serverUrl, PHP_URL_SCHEME) === 'https') {
                $guidance['Secure'] = 'Node must trust the server certificate. Use NODE_EXTRA_CA_CERTS for a locally issued certificate.';
            }
        }

        $serverConfig['protocolEra'] = 'modern';

        $configPath = tempnam(sys_get_temp_dir(), 'mcp-inspector-');

        if ($configPath === false) {
            $this->components->error('Unable to write the MCP Inspector configuration file.');

            return static::FAILURE;
        }

        try {
            if (file_put_contents($configPath, json_encode([
                'mcpServers' => [$handle => $serverConfig],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
                $this->components->error('Unable to write the MCP Inspector configuration file.');

                return static::FAILURE;
            }

            $command = [
                'npx',
                '@modelcontextprotocol/inspector',
                '--config',
                $configPath,
            ];

            $guidance['Protocol Era'] = 'modern';
            $guidance['Config'] = $configPath;

            foreach ($guidance as $guidanceKey => $guidanceValue) {
                $this->info(sprintf('%s => %s', $guidanceKey, $guidanceValue));
            }

            $this->newLine();

            Process::env($env)->forever()->run($command, function (string $type, string $buffer): void {
                echo $buffer;
            })->throw();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            $this->components->error('Failed to start MCP Inspector: ' . $exception->getMessage());

            return static::FAILURE;
        } finally {
            unlink($configPath);
        }

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
            ['handle', InputArgument::REQUIRED, 'The handle or route of the MCP server to inspect.'],
        ];
    }

    /**
     * Get the Inspector listener options.
     *
     * @return array<int, array<int, null|int|string>>
     */
    protected function getOptions(): array
    {
        return [
            ['host', null, InputOption::VALUE_OPTIONAL, 'The host the inspector should bind to'],
            ['port', null, InputOption::VALUE_OPTIONAL, 'The port the inspector should bind to'],
        ];
    }

    /**
     * Resolve the server URL with prompted route parameters.
     */
    protected function serverUrl(Route $route): string
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $value = trim((string) $this->components->ask("What is the value for the [{$parameter}] route parameter?"));

            if ($value !== '') {
                $parameters[$parameter] = $value;
            }
        }

        return $this->getHypervel()->make(UrlGenerator::class)->toRoute($route, $parameters, true);
    }

    /**
     * Get the PHP executable for local servers.
     */
    protected function phpBinary(): string
    {
        return (new PhpExecutableFinder)->find(false) ?: 'php';
    }
}
