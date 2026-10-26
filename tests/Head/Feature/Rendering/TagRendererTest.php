<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Rendering;

use Hypervel\Head\Rendering\TagRenderer;
use Hypervel\Tests\Head\TestCase;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;

class TagRendererTest extends TestCase
{
    public function testRendersValidHtmlAttributeNamesWithoutChangingThem(): void
    {
        $tags = new TagRenderer;

        $this->assertSame('data-inertia="title"', $tags->attributes(['data-inertia' => 'title']));
        $this->assertSame('crossorigin', $tags->attributes(['crossorigin' => true]));
        $this->assertSame('aria-label="Close" tabindex="0"', $tags->attributes(['aria-label' => 'Close', 'tabindex' => '0']));
        $this->assertSame('@click="close" -custom="value"', $tags->attributes(['@click' => 'close', '-custom' => 'value']));
        $this->assertSame('1custom="value" café="yes"', $tags->attributes(['1custom' => 'value', 'café' => 'yes']));
        $this->assertSame('foo&bar="value"', $tags->attributes(['foo&bar' => 'value']));
        $this->assertSame('123="value"', $tags->attributes(['123' => 'value']));
    }

    #[DataProvider('invalidAttributeNames')]
    public function testRejectsInvalidHtmlAttributeNames(string $name): void
    {
        $tags = new TagRenderer;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTML attribute name.');
        $tags->attributes([$name => 'value']);
    }

    /**
     * Supply invalid HTML attribute names.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidAttributeNames(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['onerror x'];
        yield 'equals' => ['foo=bar'];
        yield 'double quote' => ['foo"bar'];
        yield 'single quote' => ["foo'bar"];
        yield 'slash' => ['foo/bar'];
        yield 'greater than' => ['foo>bar'];
        yield 'control' => ["foo\nbar"];
        yield 'invalid utf-8' => ["foo\xC3\x28bar"];
    }

    public function testValidatesAttributeNamesSuppliedDirectlyToTagMethods(): void
    {
        $tags = new TagRenderer;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTML attribute name.');
        $tags->metaWithAttributes('name onerror', 'description', ['content' => 'Value']);
    }

    public function testRejectsCaseInsensitiveDuplicateAttributes(): void
    {
        $tags = new TagRenderer;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate HTML attribute name [REL].');
        $tags->linkWithAttributes('stylesheet', [
            'REL' => 'alternate',
            'href' => '/theme.css',
        ]);
    }

    public function testJsonLdKeysAreStableAndExplicitKeysArePreserved(): void
    {
        $schema = ['@type' => 'WebPage', 'name' => 'Café & "News"'];
        $first = (new TagRenderer)->withInertiaAttributes()->jsonLd($schema);
        $second = (new TagRenderer)->withInertiaAttributes()->jsonLd($schema);

        $this->assertSame($first, $second);
        $this->assertStringContainsString('data-inertia="schema:', $first);
        $this->assertStringContainsString('Café \u0026 \u0022News\u0022', $first);
        $this->assertStringContainsString('data-inertia="custom-schema"', (new TagRenderer(true))->jsonLd($schema, 'custom-schema'));
    }

    public function testJsonLdRejectsInvalidUtf8(): void
    {
        $this->expectException(JsonException::class);

        (new TagRenderer)->jsonLd(['name' => "\xC3\x28"]);
    }
}
