<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Contracts;

use ArrayAccess;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Foundation\Config as FoundationConfig;

/**
 * @phpstan-import-type TExtraConfig from FoundationConfig
 * @phpstan-import-type TPurgeConfig from FoundationConfig
 * @phpstan-import-type TWorkbenchConfig from FoundationConfig
 * @phpstan-import-type TWorkbenchDiscoversConfig from FoundationConfig
 */
interface Config extends ArrayAccess
{
    /**
     * Add additional service providers.
     *
     * @param array<int, class-string<ServiceProvider>> $providers
     */
    public function addProviders(array $providers): static;

    /**
     * Get extra attributes.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return TExtraConfig
     */
    public function getExtraAttributes(): array;

    /**
     * Get purge attributes.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return TPurgeConfig
     */
    public function getPurgeAttributes(): array;

    /**
     * Get workbench attributes.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return TWorkbenchConfig
     */
    public function getWorkbenchAttributes(): array;

    /**
     * Get workbench discovers attributes.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return TWorkbenchDiscoversConfig
     */
    public function getWorkbenchDiscoversAttributes(): array;
}
