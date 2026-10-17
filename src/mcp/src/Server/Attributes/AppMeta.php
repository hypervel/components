<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Attributes;

use Attribute;
use Hypervel\Mcp\Server\Ui\AppMeta as AppMetaData;
use Hypervel\Mcp\Server\Ui\Csp;
use Hypervel\Mcp\Server\Ui\Enums\Library;
use Hypervel\Mcp\Server\Ui\Enums\Permission;
use Hypervel\Mcp\Server\Ui\Permissions;

#[Attribute(Attribute::TARGET_CLASS)]
class AppMeta
{
    /**
     * Create application metadata attributes.
     *
     * @param null|array<int, string> $connectDomains domains the app may connect to via fetch, XHR, or WebSocket (CSP connect-src)
     * @param null|array<int, string> $resourceDomains domains the app may load images, scripts, styles, and fonts from (CSP img-src, script-src, style-src, and font-src)
     * @param null|array<int, string> $frameDomains domains the app may embed as nested iframes (CSP frame-src)
     * @param null|array<int, string> $baseUriDomains allowed URLs for the document's base element (CSP base-uri)
     * @param null|array<int, Permission> $permissions
     * @param array<int, Library> $libraries
     */
    public function __construct(
        public readonly ?array $connectDomains = null,
        public readonly ?array $resourceDomains = null,
        public readonly ?array $frameDomains = null,
        public readonly ?array $baseUriDomains = null,
        public readonly ?array $permissions = null,
        public readonly ?bool $prefersBorder = null,
        public readonly ?string $domain = null,
        public readonly array $libraries = [],
    ) {
    }

    /**
     * Convert the attribute to application metadata.
     */
    public function toAppMeta(): AppMetaData
    {
        $meta = AppMetaData::make();

        if (($csp = $this->getCsp()) instanceof Csp) {
            $meta->csp($csp);
        }

        if ($this->permissions !== null) {
            $meta->permissions(Permissions::make()->allow(...$this->permissions));
        }

        if ($this->prefersBorder !== null) {
            $meta->prefersBorder($this->prefersBorder);
        }

        if ($this->domain !== null) {
            $meta->domain($this->domain);
        }

        if ($this->libraries !== []) {
            $meta->libraries(...$this->libraries);
        }

        return $meta;
    }

    /**
     * Build the application's content security policy.
     */
    protected function getCsp(): ?Csp
    {
        if (
            $this->connectDomains === null
            && $this->resourceDomains === null
            && $this->frameDomains === null
            && $this->baseUriDomains === null
        ) {
            return null;
        }

        return Csp::make()
            ->connectDomains($this->connectDomains ?? [])
            ->resourceDomains($this->resourceDomains ?? [])
            ->frameDomains($this->frameDomains ?? [])
            ->baseUriDomains($this->baseUriDomains ?? []);
    }
}
