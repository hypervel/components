<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Plugins;

use Hypervel\Saloon\Enums\VersionMode;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
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
     * Get where the API version is applied to the request.
     */
    public function getVersionMode(): VersionMode
    {
        return $this->versionMode;
    }

    /**
     * Boot HasApiVersion plugin.
     */
    public function bootHasApiVersion(PendingRequest $pendingRequest): void
    {
        // The connector boots first, and its resolveUrlApiVersion() has already chosen the URL version for the request
        if ($this->versionMode === VersionMode::Url
            && $pendingRequest->connector() !== $this
            && $this->getUrlApiVersionOf($pendingRequest->connector()) !== null) {
            return;
        }

        $version = $this->getApiVersion();

        if ($version === null || $version === '') {
            return;
        }

        // Replacing the header lets a request's version override its connector's, since added headers append.
        match ($this->versionMode) {
            VersionMode::Header => $pendingRequest->replaceHeaders([$this->versionKey => $version]),
            VersionMode::QueryParam => $pendingRequest->withQueryParameters([$this->versionKey => $version]),
            VersionMode::Url => $pendingRequest->withUrlParameters([
                'version' => $this->ensureVersionIsSafeForUrl($this->resolveUrlApiVersion($pendingRequest)),
            ]),
        };
    }

    /**
     * Resolve which version to substitute into `{version}` for URL mode.
     */
    protected function resolveUrlApiVersion(PendingRequest $pendingRequest): string
    {
        $requestVersion = $pendingRequest->connector() === $this
            ? $this->getUrlApiVersionOf($pendingRequest->request())
            : null;

        // @phpstan-ignore return.type (bootHasApiVersion() only resolves a URL version after finding one)
        return $requestVersion ?? $this->getApiVersion();
    }

    /**
     * Get the version a connector or request substitutes into its URL, if it uses this plugin in URL mode.
     */
    private function getUrlApiVersionOf(Connector|Request $resource): ?string
    {
        // A lookalike getApiVersion() without this plugin doesn't version the URL
        if (! in_array(HasApiVersion::class, class_uses_recursive($resource), true)
            // @phpstan-ignore method.notFound (the plugin provides it)
            || $resource->getVersionMode() !== VersionMode::Url) {
            return null;
        }

        // @phpstan-ignore method.notFound (the plugin provides it)
        $version = $resource->getApiVersion();

        return is_string($version) && $version !== '' ? $version : null;
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
