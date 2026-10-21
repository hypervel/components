<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Ui;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Mcp\Server\Ui\AppMeta;
use Hypervel\Mcp\Server\Ui\Csp;
use Hypervel\Mcp\Server\Ui\Enums\Library;
use Hypervel\Mcp\Server\Ui\Enums\Permission;
use Hypervel\Mcp\Server\Ui\Permissions;
use Hypervel\Tests\TestCase;

class AppMetaTest extends TestCase
{
    public function testSerializesAsEmptyWhenNoFieldsSet(): void
    {
        $meta = new AppMeta;

        $this->assertSame(['prefersBorder' => true], $meta->toArray());
    }

    public function testSerializesAllFieldsWhenProvided(): void
    {
        $meta = new AppMeta(
            csp: new Csp(
                connectDomains: ['https://api.example.com'],
                resourceDomains: ['https://cdn.example.com'],
            ),
            permissions: Permissions::make()->allow(Permission::Camera, Permission::ClipboardWrite),
            domain: 'a904794854a047f6.claudemcpcontent.com',
            prefersBorder: false,
        );

        $array = $meta->toArray();

        $this->assertSame([
            'connectDomains' => ['https://api.example.com'],
            'resourceDomains' => ['https://cdn.example.com'],
        ], $array['csp']);
        $this->assertArrayHasKey('clipboardWrite', $array['permissions']);
        $this->assertArrayHasKey('camera', $array['permissions']);
        $this->assertSame('a904794854a047f6.claudemcpcontent.com', $array['domain']);
        $this->assertFalse($array['prefersBorder']);
    }

    public function testOmitsNullValues(): void
    {
        $meta = new AppMeta(
            prefersBorder: true,
        );

        $array = $meta->toArray();

        $this->assertArrayHasKey('prefersBorder', $array);
        $this->assertArrayNotHasKey('csp', $array);
        $this->assertArrayNotHasKey('permissions', $array);
        $this->assertArrayNotHasKey('domain', $array);
    }

    public function testImplementsArrayable(): void
    {
        $meta = new AppMeta;

        $this->assertInstanceOf(Arrayable::class, $meta);
    }

    public function testSupportsFluentBuilderViaMake(): void
    {
        $meta = AppMeta::make()
            ->csp(Csp::make()->connectDomains(['https://api.example.com']))
            ->permissions(Permissions::make()->clipboardWrite())
            ->domain('sandbox.example.com')
            ->prefersBorder(false);

        $array = $meta->toArray();

        $this->assertSame(['connectDomains' => ['https://api.example.com']], $array['csp']);
        $this->assertArrayHasKey('clipboardWrite', $array['permissions']);
        $this->assertSame('sandbox.example.com', $array['domain']);
        $this->assertFalse($array['prefersBorder']);
    }

    public function testOmitsEmptyCspAndPermissionsObjects(): void
    {
        $meta = AppMeta::make()
            ->csp(Csp::make())
            ->permissions(Permissions::make());

        $this->assertSame(['prefersBorder' => true], $meta->toArray());
    }

    public function testMergesLibraryDomainsIntoCspResourceDomains(): void
    {
        $meta = AppMeta::make()
            ->libraries(Library::Tailwind, Library::Alpine);

        $array = $meta->toArray();

        $this->assertContains('https://cdn.tailwindcss.com', $array['csp']['resourceDomains']);
        $this->assertContains('https://cdn.jsdelivr.net', $array['csp']['resourceDomains']);
    }

    public function testMergesLibraryDomainsWithExistingCspResourceDomains(): void
    {
        $meta = AppMeta::make()
            ->csp(Csp::make()->resourceDomains(['https://existing.com']))
            ->libraries(Library::Tailwind);

        $array = $meta->toArray();

        $this->assertContains('https://existing.com', $array['csp']['resourceDomains']);
        $this->assertContains('https://cdn.tailwindcss.com', $array['csp']['resourceDomains']);
    }

    public function testDeduplicatesLibraryDomainsInCsp(): void
    {
        $meta = AppMeta::make()
            ->csp(Csp::make()->resourceDomains(['https://cdn.tailwindcss.com']))
            ->libraries(Library::Tailwind);

        $array = $meta->toArray();

        $count = count(array_filter(
            $array['csp']['resourceDomains'],
            fn (string $d): bool => $d === 'https://cdn.tailwindcss.com',
        ));

        $this->assertSame(1, $count);
    }

    public function testReturnsLibrariesViaGetLibraries(): void
    {
        $meta = AppMeta::make()
            ->libraries(Library::Tailwind, Library::Alpine);

        $this->assertSame([Library::Tailwind, Library::Alpine], $meta->getLibraries());
    }
}
