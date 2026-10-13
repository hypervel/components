<?php

declare(strict_types=1);

namespace Hypervel\Passport\Contracts;

use Hypervel\Passport\ServerSettings;

interface ServerSettingsResolver
{
    /**
     * Return the current settings identifier, or null for the default settings.
     *
     * This runs on every OAuth request and must not query the database. The
     * identifier must change whenever the settings or their keys change.
     */
    public function current(): ?string;

    /**
     * Load the settings for an identifier returned by current().
     */
    public function resolve(string $identifier): ServerSettings;
}
