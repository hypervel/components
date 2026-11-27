<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Hypervel\Container\Container;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Resource as McpResource;
use Hypervel\Mcp\Server\Skills\DirectorySkill;
use Hypervel\Mcp\Server\Skills\SkillResource;
use Hypervel\Mcp\Server\Tools\ToolSearch;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\HeaderBag;

class ServerContext
{
    /**
     * @var null|Collection<int, Skill>
     */
    private ?Collection $resolvedSkills = null;

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
     * @param array<int, class-string<Skill>|Skill|string> $skills
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
        public ?HeaderBag $headers = null,
        protected array $skills = [],
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
     * Resolve the skills eligible for registration.
     *
     * @return Collection<int, Skill>
     */
    public function skills(): Collection
    {
        return $this->resolvedSkills ??= $this->resolveSkills();
    }

    /**
     * Resolve a skill resource matching the given URI.
     */
    public function skillResource(string $uri): ?SkillResource
    {
        foreach ($this->skills() as $skill) {
            if (($resource = $skill->resourceFor($uri)) instanceof SkillResource) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Resolve configured skills, reporting and isolating invalid entries.
     *
     * @return Collection<int, Skill>
     */
    private function resolveSkills(): Collection
    {
        $skills = collect($this->skills)
            ->map(fn (Skill|string $skill): Skill => match (true) {
                $skill instanceof Skill => clone $skill,
                // Clone auto-singletoned Skill classes so once() caches frontmatter per request, not across calls in the same coroutine.
                is_subclass_of($skill, Skill::class) => clone Container::getInstance()->make($skill),
                default => new DirectorySkill($skill),
            })
            ->filter(fn (Skill $skill): bool => $skill->eligibleForRegistration());

        $resolved = [];

        foreach ($skills as $skill) {
            try {
                $skill->files();
                $uri = $skill->uri();

                if (array_key_exists($uri, $resolved)) {
                    throw new InvalidArgumentException("The skill URI [{$uri}] is already registered.");
                }

                $resolved[$uri] = $skill;
            } catch (InvalidArgumentException $invalidArgumentException) {
                report($invalidArgumentException);
            }
        }

        return collect($resolved)->values();
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
