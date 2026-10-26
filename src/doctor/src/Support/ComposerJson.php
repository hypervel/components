<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Support;

use Hypervel\Support\Arr;
use Hypervel\Support\Facades\File;

class ComposerJson
{
    /**
     * The decoded composer.json payload.
     *
     * @var null|array<string, mixed>
     */
    private ?array $contents = null;

    /**
     * Determine whether the application has a composer.json file.
     */
    public function exists(): bool
    {
        return File::isFile($this->path());
    }

    /**
     * Get the application's PHP version constraint.
     */
    public function phpConstraint(): ?string
    {
        $constraint = Arr::get($this->contents() ?? [], 'require.php');

        return is_string($constraint) && $constraint !== '' ? $constraint : null;
    }

    /**
     * Get the Composer-required PHP extensions.
     *
     * @return list<string>
     */
    public function requiredExtensions(): array
    {
        return $this->extensionsFrom([
            ...$this->packageNames(Arr::get($this->contents() ?? [], 'require', [])),
            ...$this->packageNames(Arr::get($this->contents() ?? [], 'require-dev', [])),
        ]);
    }

    /**
     * Get the Composer-suggested PHP extensions.
     *
     * @return list<string>
     */
    public function suggestedExtensions(): array
    {
        return $this->extensionsFrom(
            $this->packageNames(Arr::get($this->contents() ?? [], 'suggest', [])),
        );
    }

    /**
     * Get the decoded composer.json contents.
     *
     * @return null|array<string, mixed>
     */
    public function contents(): ?array
    {
        if ($this->contents !== null) {
            return $this->contents;
        }

        if (! $this->exists()) {
            return null;
        }

        $decoded = json_decode(File::get($this->path()), true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $this->contents = $decoded;
    }

    /**
     * Get package names from a Composer object.
     *
     * @return list<string>
     */
    private function packageNames(mixed $packages): array
    {
        if (! is_array($packages)) {
            return [];
        }

        return array_values(array_filter(array_keys($packages), is_string(...)));
    }

    /**
     * Get extension names from Composer package names.
     *
     * @param list<string> $packages
     * @return list<string>
     */
    private function extensionsFrom(array $packages): array
    {
        return array_values(array_unique(array_map(
            static fn (string $package): string => substr($package, 4),
            array_filter(
                $packages,
                static fn (string $package): bool => str_starts_with($package, 'ext-'),
            ),
        )));
    }

    /**
     * Get the path to composer.json.
     */
    private function path(): string
    {
        return base_path('composer.json');
    }
}
