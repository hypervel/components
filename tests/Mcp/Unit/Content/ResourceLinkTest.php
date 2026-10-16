<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Content;

use Hypervel\Mcp\Enums\IconTheme;
use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server\Content\ResourceLink;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ResourceLinkTest extends TestCase
{
    public function testMayBeUsedInToolsAndPrompts(): void
    {
        $resourceLink = new ResourceLink(
            uri: 'https://example.com/data.json',
            name: 'Dataset',
            mimeType: 'application/json',
        );

        $expected = [
            'type' => 'resource_link',
            'uri' => 'https://example.com/data.json',
            'name' => 'Dataset',
            'mimeType' => 'application/json',
        ];

        $this->assertSame($expected, $resourceLink->toTool(new class extends Tool {}));
        $this->assertSame($expected, $resourceLink->toPrompt(new class extends Prompt {}));
    }

    public function testMayNotBeUsedInResources(): void
    {
        $resourceLink = new ResourceLink('https://example.com/data.json', 'Dataset');

        $resource = new class extends Resource {
            protected string $uri = 'file://store/items.json';

            protected string $mimeType = 'application/json';
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ResourceLink content may not be used in resources.');
        $resourceLink->toResource($resource);
    }

    public function testCastsToStringAsTheUri(): void
    {
        $resourceLink = new ResourceLink('https://example.com/resource', 'Resource');

        $this->assertSame('https://example.com/resource', (string) $resourceLink);
    }

    public function testConvertsToArrayWithRequiredFields(): void
    {
        $resourceLink = new ResourceLink('https://example.com/resource', 'Resource');

        $this->assertSame([
            'type' => 'resource_link',
            'uri' => 'https://example.com/resource',
            'name' => 'Resource',
        ], $resourceLink->toArray());
    }

    public function testOmitsOptionalFieldsWhenNull(): void
    {
        $resourceLink = new ResourceLink('https://example.com/resource', 'Resource');

        $array = $resourceLink->toArray();

        $this->assertArrayNotHasKey('title', $array);
        $this->assertArrayNotHasKey('description', $array);
        $this->assertArrayNotHasKey('mimeType', $array);
        $this->assertArrayNotHasKey('size', $array);
        $this->assertArrayNotHasKey('annotations', $array);
        $this->assertArrayNotHasKey('_meta', $array);
    }

    public function testIncludesAllSpecDefinedFieldsWhenProvided(): void
    {
        $resourceLink = new ResourceLink(
            uri: 'https://example.com/data.json',
            name: 'monthly-report',
            mimeType: 'application/json',
            title: 'Monthly Sales Report',
            description: 'Sales rollup by region',
            size: 2048,
        );

        $this->assertSame([
            'type' => 'resource_link',
            'uri' => 'https://example.com/data.json',
            'name' => 'monthly-report',
            'title' => 'Monthly Sales Report',
            'description' => 'Sales rollup by region',
            'mimeType' => 'application/json',
            'size' => 2048,
        ], $resourceLink->toArray());
    }

    public function testIncludesAnnotationsWhenProvided(): void
    {
        $resourceLink = new ResourceLink(
            uri: 'https://example.com/resource',
            name: 'Resource',
            annotations: [
                'audience' => ['user', 'assistant'],
                'priority' => 0.9,
                'lastModified' => '2026-05-07T12:00:00Z',
            ],
        );

        $this->assertSame([
            'type' => 'resource_link',
            'uri' => 'https://example.com/resource',
            'name' => 'Resource',
            'annotations' => [
                'audience' => ['user', 'assistant'],
                'priority' => 0.9,
                'lastModified' => '2026-05-07T12:00:00Z',
            ],
        ], $resourceLink->toArray());
    }

    public function testIncludesIconsWhenProvided(): void
    {
        $resourceLink = new ResourceLink(
            uri: 'https://example.com/resource',
            name: 'Resource',
            icons: [
                new Icon('https://example.com/icon.png', mimeType: 'image/png', sizes: ['48x48']),
                new Icon('https://example.com/icon-dark.svg', theme: IconTheme::Dark),
            ],
        );

        $this->assertSame([
            ['src' => 'https://example.com/icon.png', 'mimeType' => 'image/png', 'sizes' => ['48x48']],
            ['src' => 'https://example.com/icon-dark.svg', 'theme' => 'dark'],
        ], $resourceLink->toArray()['icons']);
    }

    public function testOmitsIconsKeyWhenNoneAreProvided(): void
    {
        $resourceLink = new ResourceLink('https://example.com/resource', 'Resource');

        $this->assertArrayNotHasKey('icons', $resourceLink->toArray());
    }

    public function testSupportsMetaViaSetMeta(): void
    {
        $resourceLink = new ResourceLink('https://example.com/resource', name: 'Resource');
        $resourceLink->setMeta(['version' => '2']);

        $this->assertSame([
            'type' => 'resource_link',
            'uri' => 'https://example.com/resource',
            'name' => 'Resource',
            '_meta' => ['version' => '2'],
        ], $resourceLink->toArray());
    }
}
