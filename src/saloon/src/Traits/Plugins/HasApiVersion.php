<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Plugins;

use Hypervel\Saloon\Enums\VersionMode;
use Hypervel\Saloon\Http\PendingRequest;
use InvalidArgumentException;

trait HasApiVersion
{
    /**
     * API Version.
     */
    protected ?string $apiVersion = null;

    /**
     * Where the API version should be applied to the request.
     */
    protected VersionMode $versionMode = VersionMode::Header;

    /**
     * The header or query parameter name used for the API version.
     */
    protected string $versionKey = 'api-version';

    // REMOVED: setApiVersion(). Connectors may be shared by concurrent requests, so a class assigns $apiVersion in
    // its constructor or overrides getApiVersion().

    /**
     * Get the API version.
     */
    public function getApiVersion(): ?string
    {
        return $this->apiVersion;
    }

    /**
     * Boot HasApiVersion plugin.
     */
    public function bootHasApiVersion(PendingRequest $pendingRequest): void
    {
        $version = $this->getApiVersion();

        if ($version === null || $version === '') {
            return;
        }

        // Replacing the header lets a request's version override its connector's, since added headers append.
        match ($this->versionMode) {
            VersionMode::Header => $pendingRequest->replaceHeaders([$this->versionKey => $version]),
            VersionMode::QueryParam => $pendingRequest->withQueryParameters([$this->versionKey => $version]),
            VersionMode::Url => $pendingRequest->withUrlParameters(['version' => $this->ensureVersionIsSafeForUrl($version)]),
        };
    }

    /**
     * Ensure the version can only ever form a single subdomain label or path segment.
     *
     * @throws InvalidArgumentException
     */
    protected function ensureVersionIsSafeForUrl(string $version): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $version) !== 1) {
            throw new InvalidArgumentException(sprintf('The API version "%s" is not safe to use in a URL. Versions may only contain letters, numbers, dashes, underscores and single dots.', $version));
        }

        return $version;
    }
}
