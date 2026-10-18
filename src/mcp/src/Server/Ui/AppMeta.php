<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Ui;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Mcp\Server\Ui\Enums\Library;

/**
 * @implements Arrayable<array<string, mixed>>
 */
class AppMeta implements Arrayable
{
    /**
     * Create application metadata.
     *
     * @param array<int, Library> $libraries
     */
    public function __construct(
        protected ?Csp $csp = null,
        protected ?Permissions $permissions = null,
        protected ?string $domain = null,
        protected ?bool $prefersBorder = true,
        protected array $libraries = [],
    ) {
    }

    /**
     * Create new application metadata.
     */
    public static function make(): static
    {
        return new static;
    }

    /**
     * Set the content security policy.
     */
    public function csp(Csp $csp): static
    {
        $this->csp = $csp;

        return $this;
    }

    /**
     * Set the requested application permissions.
     */
    public function permissions(Permissions $permissions): static
    {
        $this->permissions = $permissions;

        return $this;
    }

    /**
     * Set the application domain.
     */
    public function domain(string $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    /**
     * Set whether the application prefers a visible border.
     */
    public function prefersBorder(bool $prefersBorder = true): static
    {
        $this->prefersBorder = $prefersBorder;

        return $this;
    }

    /**
     * Set the application libraries.
     */
    public function libraries(Library ...$libraries): static
    {
        $this->libraries = array_values($libraries);

        return $this;
    }

    /**
     * Get the application libraries.
     *
     * @return array<int, Library>
     */
    public function getLibraries(): array
    {
        return $this->libraries;
    }

    /**
     * Convert the application metadata to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $cspArray = $this->csp?->toArray() ?: [];

        if ($this->libraries !== []) {
            $libraryDomains = collect($this->libraries)
                ->map(fn (Library $lib): array => $lib->domains())
                ->flatten();

            /** @var array<int, string> $existingDomains */
            $existingDomains = $cspArray['resourceDomains'] ?? [];

            $cspArray['resourceDomains'] = collect($existingDomains)
                ->merge($libraryDomains)
                ->unique()
                ->values()
                ->all();
        }

        return array_filter([
            'csp' => $cspArray ?: null,
            'permissions' => $this->permissions?->toArray() ?: null,
            'domain' => $this->domain === '' ? null : $this->domain,
            'prefersBorder' => $this->prefersBorder,
        ], fn (mixed $value): bool => $value !== null);
    }
}
