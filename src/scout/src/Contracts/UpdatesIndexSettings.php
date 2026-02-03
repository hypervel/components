<?php

declare(strict_types=1);

namespace Hypervel\Scout\Contracts;

/**
 * Contract for engines that support updating index settings.
 */
interface UpdatesIndexSettings
{
    /**
     * Update the index settings for the given index.
     *
     * @param array<string, mixed> $settings
     */
    public function updateIndexSettings(string $name, array $settings = []): void;

    /**
     * Configure the soft delete filter within the given settings.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function configureSoftDeleteFilter(array $settings = []): array;
}
