<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Hypervel\Mcp\Server\Attributes\AppMeta as AppMetaAttribute;
use Hypervel\Mcp\Server\Ui\AppMeta;
use Hypervel\Mcp\Server\Ui\Enums\Library;

abstract class AppResource extends Resource
{
    public const string CLAUDE_DOMAIN_SUFFIX = '.claudemcpcontent.com';

    protected string $mimeType = 'text/html;profile=mcp-app';

    protected string $defaultUriScheme = 'ui';

    /**
     * Get the application metadata.
     */
    public function appMeta(): AppMeta
    {
        $attribute = $this->resolveAttribute(AppMetaAttribute::class);

        return $attribute?->toAppMeta() ?? new AppMeta;
    }

    /**
     * Resolve application metadata for the current server URL.
     *
     * @return array<string, mixed>
     */
    public function resolvedAppMeta(): array
    {
        $appMeta = $this->appMeta()->toArray();

        $appMeta['domain'] ??= $this->toClaudeDomain(url()->current());

        return $appMeta;
    }

    /**
     * Get the application library script tags.
     */
    public function libraryScripts(): string
    {
        return implode("\n", array_map(
            fn (Library $lib): string => implode("\n", $lib->scriptTags()),
            $this->appMeta()->getLibraries(),
        ));
    }

    /**
     * Convert the application resource to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $appMeta = $this->resolvedAppMeta();

        if ($appMeta !== []) {
            $data['_meta'] = array_merge($data['_meta'] ?? [], ['ui' => $appMeta]);
        }

        return $data;
    }

    /**
     * Derive the application's isolated Claude domain.
     */
    private function toClaudeDomain(string $serverRoute): string
    {
        return str(hash('sha256', $serverRoute))
            ->limit(32, '')
            ->append(self::CLAUDE_DOMAIN_SUFFIX)
            ->value();
    }
}
