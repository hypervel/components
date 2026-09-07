<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;
use Symfony\Component\Finder\Finder;

class ListCommand extends Command
{
    /**
     * The signature of the command.
     */
    protected ?string $signature = 'saloon:list';

    /**
     * The description of the command.
     */
    protected string $description = 'List all Saloon authenticators, connectors, requests, plugins, and responses';

    /**
     * Create a new command instance.
     */
    public function __construct(
        protected Repository $config,
        protected Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $integrations = $this->getIntegrations();

        $this->newLine();

        $this->components->twoColumnDetail(
            '<fg=green;options=bold>General</>',
            '<fg=white>Integrations: ' . count($integrations) . '</>'
        );

        $this->newLine();

        foreach ($integrations as $integration) {
            $authenticators = $this->getIntegrationAuthenticators($integration);
            $connectors = $this->getIntegrationConnectors($integration);
            $requests = $this->getIntegrationRequests($integration);
            $plugins = $this->getIntegrationPlugins($integration);
            $responses = $this->getIntegrationResponses($integration);

            $this->getIntegrationOutput(
                $integration,
                count($authenticators),
                count($connectors),
                count($requests),
                count($plugins),
                count($responses),
            );

            foreach ($authenticators as $integrationAuthenticator) {
                $this->getIntegrationAuthenticatorOutput($integrationAuthenticator);
            }

            foreach ($connectors as $integrationConnector) {
                $this->getIntegrationConnectorOutput($integrationConnector);
            }

            foreach ($requests as $integrationRequest) {
                $this->getIntegrationRequestOutput($integrationRequest);
            }

            foreach ($plugins as $integrationPlugin) {
                $this->getIntegrationPluginOutput($integrationPlugin);
            }

            foreach ($responses as $integrationResponse) {
                $this->getIntegrationResponseOutput($integrationResponse);
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Get the integration directories.
     *
     * @return array<int, string>
     */
    protected function getIntegrations(): array
    {
        $integrationsPath = $this->config->string('saloon.integrations_path');

        if (! $this->files->isDirectory($integrationsPath)) {
            return [];
        }

        $integrations = [];

        foreach (Finder::create()->directories()->depth(0)->sortByName()->in($integrationsPath) as $integration) {
            $integrations[] = $integration->getPathname();
        }

        return $integrations;
    }

    /**
     * Get the connector files for an integration.
     *
     * @return array<int, string>
     */
    protected function getIntegrationConnectors(string $integration): array
    {
        $connectors = [];

        foreach (Finder::create()->files()->depth(0)->sortByName()->in($integration) as $connector) {
            $connectors[] = $connector->getPathname();
        }

        return $connectors;
    }

    /**
     * Get the request files for an integration.
     *
     * @return array<int, string>
     */
    protected function getIntegrationRequests(string $integration): array
    {
        $requests = [];

        if ($this->files->isDirectory($integration . '/Requests')) {
            foreach (Finder::create()->files()->sortByName()->in($integration . '/Requests') as $request) {
                $requests[] = $request->getPathname();
            }
        }

        return $requests;
    }

    /**
     * Get the plugin files for an integration.
     *
     * @return array<int, string>
     */
    protected function getIntegrationPlugins(string $integration): array
    {
        $plugins = [];

        if ($this->files->isDirectory($integration . '/Plugins')) {
            foreach (Finder::create()->files()->sortByName()->in($integration . '/Plugins') as $plugin) {
                $plugins[] = $plugin->getPathname();
            }
        }

        return $plugins;
    }

    /**
     * Get the response files for an integration.
     *
     * @return array<int, string>
     */
    protected function getIntegrationResponses(string $integration): array
    {
        $responses = [];

        if ($this->files->isDirectory($integration . '/Responses')) {
            foreach (Finder::create()->files()->sortByName()->in($integration . '/Responses') as $response) {
                $responses[] = $response->getPathname();
            }
        }

        return $responses;
    }

    /**
     * Get the authenticator files for an integration.
     *
     * @return array<int, string>
     */
    protected function getIntegrationAuthenticators(string $integration): array
    {
        $authenticators = [];

        if ($this->files->isDirectory($integration . '/Auth')) {
            foreach (Finder::create()->files()->sortByName()->in($integration . '/Auth') as $authenticator) {
                $authenticators[] = $authenticator->getPathname();
            }
        }

        return $authenticators;
    }

    /**
     * Render an integration summary.
     */
    protected function getIntegrationOutput(
        string $integration,
        int $authenticatorCount,
        int $connectorCount,
        int $requestCount,
        int $pluginCount,
        int $responseCount,
    ): void {
        $this->components->twoColumnDetail(
            '<fg=green;options=bold>' . basename($integration) . '</>',
            sprintf(
                '<fg=white>Authenticators: %d / Connectors: %d / Requests: %d / Plugins: %d / Responses: %d</>',
                $authenticatorCount,
                $connectorCount,
                $requestCount,
                $pluginCount,
                $responseCount,
            )
        );
    }

    /**
     * Render an authenticator.
     */
    protected function getIntegrationAuthenticatorOutput(string $authenticator): void
    {
        $this->components->twoColumnDetail(
            '<fg=red>Authenticator</> <fg=gray>...</> ' . $authenticator
        );
    }

    /**
     * Render a connector.
     */
    protected function getIntegrationConnectorOutput(string $connector): void
    {
        $this->components->twoColumnDetail(
            '<fg=blue>Connector</> <fg=gray>.......</> ' . $connector,
            '<fg=gray>' . $this->getIntegrationConnectorBaseUrl($connector) . '</>'
        );
    }

    /**
     * Render a request.
     */
    protected function getIntegrationRequestOutput(string $request): void
    {
        $requestMethod = Str::afterLast($this->getIntegrationRequestMethod($request), ':');

        $requestMethodOutputColor = match ($requestMethod) {
            'GET' => 'blue',
            'PATCH', 'POST', 'PUT' => 'green',
            'DELETE' => 'red',
            default => 'magenta'
        };

        $this->components->twoColumnDetail(
            '<fg=magenta>Request</> <fg=gray>.........</> '
            . $request,
            ' <fg=gray>' . $this->getIntegrationRequestEndpoint($request) . '</>'
            . ' <fg=' . $requestMethodOutputColor . '>'
            . $requestMethod . '</> '
        );
    }

    /**
     * Render a plugin.
     */
    protected function getIntegrationPluginOutput(string $plugin): void
    {
        $this->components->twoColumnDetail(
            '<fg=cyan>Plugin</> <fg=gray>..........</> ' . $plugin
        );
    }

    /**
     * Render a response.
     */
    protected function getIntegrationResponseOutput(string $response): void
    {
        $this->components->twoColumnDetail(
            '<fg=yellow>Response</> <fg=gray>........</> ' . $response
        );
    }

    /**
     * Read the method from a generated request.
     */
    protected function getIntegrationRequestMethod(string $request): string
    {
        $contents = $this->files->get($request);

        return Str::match('/\$method\s*=\s*(.*?);/', $contents);
    }

    /**
     * Read the endpoint from a generated request.
     */
    protected function getIntegrationRequestEndpoint(string $request): string
    {
        $contents = $this->files->get($request);

        $regex = '/public\s+function\s+resolveEndpoint\(\):\s+string\s*\{\s*return\s+(.*?);/s';

        $match = Str::match($regex, $contents);
        $matchSegments = explode('/', $match);

        foreach ($matchSegments as $key => $matchSegment) {
            if (Str::contains($matchSegment, '$this->')) {
                $matchSegments[$key] = '{' . Str::before(
                    Str::after(str_replace(' ', '', $matchSegment), '>'),
                    '.\''
                ) . '}';
            }
        }

        return str_replace('\'', '', implode('/', $matchSegments));
    }

    /**
     * Read the base URL from a generated connector.
     */
    protected function getIntegrationConnectorBaseUrl(string $connector): string
    {
        $contents = $this->files->get($connector);

        $regex = '/public\s+function\s+resolveBaseUrl\(\):\s+string\s*\{\s*return\s+\'(.*?)\';\s*/s';
        $matches = Str::match($regex, $contents);

        return Str::after($matches, '://');
    }
}
