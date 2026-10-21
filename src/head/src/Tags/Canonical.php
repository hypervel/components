<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Closure;
use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;
use Hypervel\Http\Request;

/**
 * @phpstan-consistent-constructor
 */
class Canonical extends TagBuilder
{
    /** @var null|(Closure(): ?string) */
    protected static ?Closure $originResolver = null;

    /**
     * Create a canonical URL definition.
     *
     * @param string $mode either "url" for an explicit URL or "auto" to resolve from the request
     */
    public function __construct(
        protected string $mode,
        protected ?string $url = null,
        protected ?bool $forceHttps = null,
        protected ?bool $trailingSlash = null,
    ) {
    }

    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'canonical';
    }

    /**
     * Use a null $url to resolve the canonical URL from the current request.
     */
    public static function make(?string $url = null, ?bool $forceHttps = null, ?bool $trailingSlash = null): self
    {
        return new self(is_null($url) ? 'auto' : 'url', $url, $forceHttps, $trailingSlash);
    }

    /**
     * Build a canonical URL from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        if ($key !== 'canonical') {
            return null;
        }

        return match (true) {
            is_array($value) => self::fromRouteAttributeArray($value),
            is_string($value) => self::make($value),
            $value === true || is_null($value) => self::make(),
            default => null,
        };
    }

    /**
     * Overlay this URL while inheriting unspecified normalization options.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        return new static(
            $this->mode,
            $this->url ?? $base->url,
            $this->forceHttps ?? $base->forceHttps,
            $this->trailingSlash ?? $base->trailingSlash,
        );
    }

    /**
     * Canonical builders always carry a resolution mode, so they are never empty.
     */
    public function isEmpty(): bool
    {
        return false;
    }

    /**
     * Resolve and normalize the canonical URL.
     */
    public function render(?Request $request): ?string
    {
        $url = match ($this->mode) {
            'auto' => $request?->url(),
            'url' => $this->url,
            default => null,
        };

        if (is_null($url)) {
            return null;
        }

        return $this->normalizeUrl($url, $request, $this->forceHttps ?? true, $this->trailingSlash ?? false);
    }

    /**
     * Get the resolved canonical URL for the head array.
     */
    public function toHeadArray(ResolvedHead $head): ?string
    {
        return $this->render($head->request());
    }

    /**
     * Render the canonical link tag.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        return ($canonical = $this->render($head->request())) ? [$tags->link('canonical', $canonical, 'canonical')] : [];
    }

    /**
     * Build a canonical URL from named route options.
     *
     * @param array<mixed, mixed> $attributes
     */
    private static function fromRouteAttributeArray(array $attributes): ?self
    {
        if (array_key_exists('none', $attributes) || ($attributes['value'] ?? null) === false) {
            return null;
        }

        $value = self::routeAttributeUrl($attributes);

        return is_string($value) || is_null($value)
            ? self::make(
                $value,
                forceHttps: self::bool($attributes['forceHttps'] ?? null),
                trailingSlash: self::bool($attributes['trailingSlash'] ?? null),
            )
            : null;
    }

    /**
     * Get the explicit URL or automatic mode from route options.
     *
     * @param array<mixed, mixed> $attributes
     */
    private static function routeAttributeUrl(array $attributes): mixed
    {
        $value = $attributes['value'] ?? null;

        return $value === true || self::bool($attributes['auto'] ?? null) === true
            ? null
            : $value;
    }

    /**
     * Register a resolver for automatic and relative canonical origins.
     *
     * Boot-only. The callback persists in a static property and affects every request in the worker.
     *
     * @param null|(Closure(): ?string) $resolver
     */
    public static function resolveOriginUsing(?Closure $resolver): void
    {
        static::$originResolver = $resolver;
    }

    /**
     * Normalize the origin and path without changing the query or fragment.
     */
    protected function normalizeUrl(string $url, ?Request $request, bool $forceHttps, bool $trailingSlash): string
    {
        $relative = ! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://');

        if ($relative || $this->mode === 'auto') {
            $origin = static::$originResolver !== null ? (static::$originResolver)() : null;

            if ($relative) {
                $url = rtrim($origin ?? $request?->getSchemeAndHttpHost() ?? '', '/') . '/' . ltrim($url, '/');
            } elseif ($origin !== null && $request !== null) {
                $url = rtrim($origin, '/') . substr($url, strlen($request->getSchemeAndHttpHost()));
            }
        }

        if ($forceHttps) {
            $url = preg_replace('/^http:\/\//', 'https://', $url) ?? $url;
        }

        // Slashes belong to the path, never after a query string or fragment.
        $suffixOffset = strcspn($url, '?#');
        $suffix = substr($url, $suffixOffset);
        $url = substr($url, 0, $suffixOffset);
        $path = parse_url($url, PHP_URL_PATH);

        if ($trailingSlash || $path === null || $path === false || $path === '' || $path === '/') {
            return rtrim($url, '/') . '/' . $suffix;
        }

        return rtrim($url, '/') . $suffix;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$originResolver = null;
    }
}
