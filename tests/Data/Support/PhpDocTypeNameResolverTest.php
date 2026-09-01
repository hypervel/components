<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Tests\Data\Fixtures\First\MultiNamespaceFirst;
use Hypervel\Tests\Data\Fixtures\ImportedType as SameNamespaceImportedType;
use Hypervel\Tests\Data\Fixtures\PhpDocTypeContext;
use Hypervel\Tests\Data\Fixtures\Second\MultiNamespaceSecond;
use Hypervel\Tests\Data\Fixtures\SiblingType;
use Hypervel\Tests\Data\Fixtures\Types\GroupedType;
use Hypervel\Tests\Data\Fixtures\Types\ImportedType;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use ReflectionProperty;

class PhpDocTypeNameResolverTest extends TestCase
{
    /**
     * Test built-in, fully qualified, and declaration-relative names.
     */
    public function testLexicalNamesAreResolvedDeterministically(): void
    {
        $resolver = new PhpDocTypeNameResolver;
        $class = new ReflectionClass(PhpDocTypeContext::class);

        $this->assertSame('string', $resolver->resolve('string', $class));
        $this->assertSame(ImportedType::class, $resolver->resolve('\\' . ImportedType::class, $class));
        $this->assertSame([], $this->imports($resolver));
        $this->assertSame(SiblingType::class, $resolver->resolve('SiblingType', $class));
        $this->assertCount(1, $this->imports($resolver));
    }

    /**
     * Test direct, grouped, and aliased imports.
     */
    public function testImportedNamesAreResolvedFromOneCachedSourceMap(): void
    {
        $resolver = new PhpDocTypeNameResolver;
        $class = new ReflectionClass(PhpDocTypeContext::class);

        $this->assertTrue(class_exists(SameNamespaceImportedType::class));
        $this->assertSame(ImportedType::class, $resolver->resolve('ImportedType', $class));
        $this->assertSame(GroupedType::class, $resolver->resolve('GroupAlias', $class));
        $this->assertSame(GroupedType::class . '\\Nested', $resolver->resolve('GroupAlias\\Nested', $class));
        $this->assertCount(1, $this->imports($resolver));
    }

    /**
     * Test one source parse caches imports for every namespace in the file.
     */
    public function testOneSourceMapContainsEveryNamespace(): void
    {
        require_once __DIR__ . '/../Fixtures/MultiNamespacePhpDocTypes.php';

        $resolver = new PhpDocTypeNameResolver;

        $this->assertSame(
            ImportedType::class,
            $resolver->resolve('SharedAlias', new ReflectionClass(MultiNamespaceFirst::class)),
        );
        $this->assertSame(
            GroupedType::class,
            $resolver->resolve('SharedAlias', new ReflectionClass(MultiNamespaceSecond::class)),
        );
        $this->assertCount(1, $this->imports($resolver));
    }

    /**
     * Get the resolver's bounded source import cache.
     *
     * @return array<string, array<string, array<string, class-string>>>
     */
    protected function imports(PhpDocTypeNameResolver $resolver): array
    {
        return (new ReflectionProperty($resolver, 'imports'))->getValue($resolver);
    }
}
