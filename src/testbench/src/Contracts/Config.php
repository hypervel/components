<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Contracts;

use ArrayAccess;

/**
 * @phpstan-import-type TExtraConfig from \Hypervel\Testbench\Foundation\Config
 * @phpstan-import-type TPurgeConfig from \Hypervel\Testbench\Foundation\Config
 * @phpstan-import-type TWorkbenchConfig from \Hypervel\Testbench\Foundation\Config
 * @phpstan-import-type TWorkbenchDiscoversConfig from \Hypervel\Testbench\Foundation\Config
 */
interface Config extends ArrayAccess
{
    /**
     * Add additional service providers.
     *
     * @param array<int, class-string<\Hypervel\Support\ServiceProvider>> $providers
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
