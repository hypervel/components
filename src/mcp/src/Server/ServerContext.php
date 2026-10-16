<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Hypervel\Container\Container;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Resource as McpResource;
use Hypervel\Mcp\Server\Tools\ToolSearch;
use Hypervel\Support\Collection;
use InvalidArgumentException;

class ServerContext
{
    /**
     * The selected resource reused when adding response cache hints.
     */
    public ?McpResource $resolvedResource = null;

    /**
     * The invocation request used to resolve that resource's cache hints.
     */
    public ?Request $resolvedResourceRequest = null;

    /**
     * Create the context for a server message.
     *
     * @param array<int, string> $supportedProtocolVersions
     * @param array<string, mixed> $serverCapabilities
     * @param array<int|string, array<int, string|Tool>|string|Tool> $tools
     * @param array<int, McpResource|string> $resources
     * @param array<int, Prompt|string> $prompts
     */
    public function __construct(
        public array $supportedProtocolVersions,
        public array $serverCapabilities,
        public Implementation $implementation,
        public string $instructions,
        public int $maxPaginationLength,
        public int $defaultPaginationLength,
        protected array $tools,
        protected array $resources,
        protected array $prompts,
    ) {
    }

    /**
     * Resolve the tools eligible for registration.
     *
     * @return Collection<int, Tool>
     */
    public function tools(): Collection
    {
        $configuredTools = collect($this->tools)->map(function (Tool|string|array $tool, int|string $key): Tool|ToolSearch|string {
            if ($key !== ToolSearch::class) {
                if (is_array($tool)) {
                    throw new InvalidArgumentException('Tool groups must use ToolSearch::class as their key.');
                }

                return $tool;
            }

            if (! is_array($tool)) {
                throw new InvalidArgumentException('The ToolSearch::class entry must contain an array of tools.');
            }

            return new ToolSearch($tool);
        });

        $hasToolSearch = $configuredTools->contains(fn (Tool|ToolSearch|string $tool): bool => $tool instanceof ToolSearch);

        /** @var Collection<int, string|Tool> $tools */
        $tools = $configuredTools->flatMap(fn (Tool|ToolSearch|string $tool): array => $tool instanceof ToolSearch
            ? $tool->tools()
            : [$tool]);

        $tools = $this->resolvePrimitives($tools);

        if ($hasToolSearch) {
            $duplicate = $tools->map(fn (Tool $tool): string => $tool->name())->duplicates()->first();

            if (is_string($duplicate)) {
                throw new InvalidArgumentException("Duplicate server tool name [{$duplicate}].");
            }
        }

        return $tools;
    }

    /**
     * Resolve the resources without URI templates.
     *
     * @return Collection<int, McpResource>
     */
    public function resources(): Collection
    {
        /** @var Collection<int,McpResource> $resourceTemplates */
        $resourceTemplates = collect($this->resources)
            ->filter(fn (McpResource|string $resource): bool => ! $this->isResourceTemplate($resource));

        return $this->resolvePrimitives($resourceTemplates);
    }

    /**
     * Resolve the resources with URI templates.
     *
     * @return Collection<int, HasUriTemplate&McpResource>
     */
    public function resourceTemplates(): Collection
    {
        /** @var Collection<int,HasUriTemplate&McpResource> $resourceTemplates */
        $resourceTemplates = collect($this->resources)
            ->filter(fn (McpResource|string $resource): bool => $this->isResourceTemplate($resource));

        return $this->resolvePrimitives($resourceTemplates);
    }

    /**
     * Resolve the prompts eligible for registration.
     *
     * @return Collection<int, Prompt>
     */
    public function prompts(): Collection
    {
        /** @var Collection<int,Prompt> $prompts */
        $prompts = collect($this->prompts);

        return $this->resolvePrimitives($prompts);
    }

    /**
     * Get the page size within the configured limit.
     */
    public function perPage(?int $requestedPerPage = null): int
    {
        return min($requestedPerPage ?? $this->defaultPaginationLength, $this->maxPaginationLength);
    }

    /**
     * Determine whether the server advertises a capability.
     */
    public function hasCapability(string $capability): bool
    {
        return array_key_exists($capability, $this->serverCapabilities);
    }

    /**
     * Resolve primitives and filter ineligible registrations.
     *
     * @template T of Primitive
     *
     * @param Collection<int, string|T> $primitive
     * @return Collection<int, T>
     */
    private function resolvePrimitives(Collection $primitive): Collection
    {
        return $primitive->map(fn (Primitive|string $primitiveClass): Primitive => is_string($primitiveClass)
                ? Container::getInstance()->make($primitiveClass)
                : $primitiveClass)
            ->filter(fn (Primitive $primitive): bool => $primitive->eligibleForRegistration());
    }

    /**
     * Determine whether a resource uses a URI template.
     */
    private function isResourceTemplate(McpResource|string $resource): bool
    {
        return $resource instanceof HasUriTemplate || (is_string($resource) && is_subclass_of($resource, HasUriTemplate::class));
    }
}
