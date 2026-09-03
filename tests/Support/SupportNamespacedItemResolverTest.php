<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\NamespacedItemResolver;
use Hypervel\Tests\TestCase;

class SupportNamespacedItemResolverTest extends TestCase
{
    public function testResolution(): void
    {
        $resolver = new NamespacedItemResolver;

        $this->assertSame(['foo', 'bar', 'baz'], $resolver->parseKey('foo::bar.baz'));
        $this->assertSame(['foo', 'bar', null], $resolver->parseKey('foo::bar'));
        $this->assertSame([null, 'bar', 'baz'], $resolver->parseKey('bar.baz'));
        $this->assertSame([null, 'bar', null], $resolver->parseKey('bar'));
    }

    public function testParsedItemsAreNotAutomaticallyCached(): void
    {
        $resolver = new SupportNamespacedItemResolverStub;

        $this->assertSame([null, 'foo', 'bar'], $resolver->parseKey('foo.bar'));
        $this->assertSame([null, 'foo', 'bar'], $resolver->parseKey('foo.bar'));
        $this->assertSame(['vendor', 'foo', 'bar'], $resolver->parseKey('vendor::foo.bar'));
        $this->assertSame(['vendor', 'foo', 'bar'], $resolver->parseKey('vendor::foo.bar'));
        $this->assertSame(4, $resolver->basicParseCount);
        $this->assertSame(2, $resolver->namespacedParseCount);
        $this->assertSame([], $resolver->parsedItems());
    }

    public function testArbitraryKeysDoNotAccumulate(): void
    {
        $resolver = new SupportNamespacedItemResolverStub;

        for ($index = 0; $index < 1000; ++$index) {
            $resolver->parseKey("validation.attribute.{$index}");
        }

        $this->assertSame([], $resolver->parsedItems());
    }

    public function testExplicitlyParsedItemsAreCached(): void
    {
        $resolver = $this->getMockBuilder(NamespacedItemResolver::class)->onlyMethods(['parseBasicSegments', 'parseNamespacedSegments'])->getMock();
        $resolver->setParsedKey('foo.bar', ['foo']);
        $resolver->expects($this->never())->method('parseBasicSegments');
        $resolver->expects($this->never())->method('parseNamespacedSegments');

        $this->assertSame(['foo'], $resolver->parseKey('foo.bar'));
    }

    public function testExplicitlyParsedItemsMayBeFlushed(): void
    {
        $resolver = new SupportNamespacedItemResolverStub;

        $resolver->setParsedKey('foo.bar', ['foo']);
        $resolver->setParsedKey('vendor::foo.bar', ['vendor']);
        $resolver->flushParsedKeys();

        $this->assertSame([null, 'foo', 'bar'], $resolver->parseKey('foo.bar'));
        $this->assertSame(['vendor', 'foo', 'bar'], $resolver->parseKey('vendor::foo.bar'));
        $this->assertSame([], $resolver->parsedItems());
    }
}

class SupportNamespacedItemResolverStub extends NamespacedItemResolver
{
    public int $basicParseCount = 0;

    public int $namespacedParseCount = 0;

    public function parsedItems(): array
    {
        return $this->parsed;
    }

    protected function parseBasicSegments(array $segments): array
    {
        ++$this->basicParseCount;

        return parent::parseBasicSegments($segments);
    }

    protected function parseNamespacedSegments(string $key): array
    {
        ++$this->namespacedParseCount;

        return parent::parseNamespacedSegments($key);
    }
}
