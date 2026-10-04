<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Transformation;

use Hypervel\Data\Exceptions\CannotPerformPartialOnDataField;
use Hypervel\Data\Support\Transformation\PartialTree;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PartialTreeTest extends TestCase
{
    #[DataProvider('partialsProvider')]
    public function testCanParsePartials(string $partial, array $expected): void
    {
        $this->assertSame($expected, self::paths(PartialTree::compile([$partial])));
    }

    /**
     * Provide partial paths and the selections they compile to.
     *
     * Spatie's empty and invalid rows are rejected instead; see invalidPathProvider().
     */
    public static function partialsProvider(): array
    {
        return [
            'root property' => ['name', ['name']],
            'root multi-property' => ['{name, age}', ['name', 'age']],
            'root star' => ['*', ['*']],
            'nested property' => ['struct.name', ['struct.name']],
            'nested multi-property' => ['struct.{name, age}', ['struct.name', 'struct.age']],
            'nested star' => ['struct.*', ['struct.*']],
        ];
    }

    // REMOVED: Spatie's pointer system tests. Paths compile into an immutable tree navigated with child(), so there is no pointer to advance or roll back.

    /**
     * Test paths compile into one reusable nested selection.
     */
    public function testCompilesNestedGroupedAndWildcardPaths(): void
    {
        $tree = PartialTree::compile([
            'artist.name',
            'artist.{email,role}',
            'songs.*',
        ]);

        $this->assertNotNull($tree);
        $this->assertTrue($tree->contains('artist'));
        $this->assertFalse($tree->selects('artist'));
        $this->assertTrue($tree->contains('songs'));
        $this->assertFalse($tree->selects('songs'));
        $this->assertFalse($tree->selects('year'));
        $this->assertSame(['artist', 'songs'], $tree->nestedProperties);

        $artist = $tree->child('artist');

        $this->assertNotNull($artist);
        $this->assertTrue($artist->selects('name'));
        $this->assertTrue($artist->selects('email'));
        $this->assertTrue($artist->selects('role'));
        $this->assertFalse($artist->selects('id'));
        $this->assertSame([], $artist->nestedProperties);
        $this->assertNull($artist->child('name')->child('first'));

        $songs = $tree->child('songs');

        $this->assertNotNull($songs);
        $this->assertTrue($songs->selects('title'));
        $this->assertSame($songs, $songs->child('title'));
    }

    /**
     * Test duplicate paths merge without mutable traversal state.
     */
    public function testMergesDuplicatePrefixes(): void
    {
        $tree = PartialTree::compile([
            'artist.name',
            'artist.name',
            'artist.email',
        ]);

        $this->assertNotNull($tree);
        $this->assertSame(['name', 'email'], array_keys($tree->child('artist')->children));
    }

    /**
     * Test exact endpoints remain distinct from traversal prefixes.
     */
    public function testRetainsExactSelectionAndPropagatingWildcardProvenance(): void
    {
        $prefix = PartialTree::compile(['artist.name']);

        $this->assertNotNull($prefix);
        $this->assertTrue($prefix->contains('artist'));
        $this->assertFalse($prefix->selects('artist'));
        $this->assertTrue($prefix->child('artist')->selects('name'));

        $exactAndNested = PartialTree::compile(['artist', 'artist.name']);

        $this->assertNotNull($exactAndNested);
        $this->assertTrue($exactAndNested->selects('artist'));
        $this->assertTrue($exactAndNested->child('artist')->selects('name'));

        $all = PartialTree::compile(['*', 'artist.name']);

        $this->assertNotNull($all);
        $this->assertTrue($all->selects('anything'));
        $this->assertTrue($all->child('artist')->all);
        $this->assertTrue($all->child('artist')->selects('name'));
        $this->assertSame(['artist'], $all->nestedProperties);

        // An inherited * ends the selection at the child; only the child's own * continues it.
        $this->assertSame([], PartialTree::compile(['*', 'artist'])->nestedProperties);

        $unlisted = $all->child('unlisted');

        $this->assertNotNull($unlisted);
        $this->assertTrue($unlisted->all);
        $this->assertSame($unlisted, $unlisted->child('nested'));
    }

    /**
     * Test compiled selections compose without losing endpoints or descendants.
     */
    public function testMergesCompiledSelections(): void
    {
        $tree = PartialTree::compile(['artist', 'artist.name', 'songs.*']);
        $other = PartialTree::compile(['artist.email', 'profile.name']);

        $this->assertNotNull($tree);
        $this->assertNotNull($other);

        $merged = $tree->merge($other);

        $this->assertTrue($merged->selects('artist'));
        $this->assertSame(['name', 'email'], array_keys($merged->child('artist')->children));
        $this->assertTrue($merged->child('songs')->all);
        $this->assertTrue($merged->child('profile')->selects('name'));
        $this->assertSame(['artist', 'songs', 'profile'], $merged->nestedProperties);
        $this->assertSame($tree, $tree->merge(null));
    }

    /**
     * Test wildcard inheritance is symmetric when selections compose.
     */
    public function testMergesWildcardAndExplicitSelectionsInEitherOrder(): void
    {
        $all = PartialTree::compile(['*']);
        $explicit = PartialTree::compile(['artist.name']);

        $this->assertNotNull($all);
        $this->assertNotNull($explicit);

        foreach ([$all->merge($explicit), $explicit->merge($all)] as $merged) {
            $this->assertTrue($merged->all);
            $this->assertSame(['artist'], array_keys($merged->children));
            $this->assertTrue($merged->child('artist')->all);
            $this->assertSame(['name'], array_keys($merged->child('artist')->children));
            $this->assertTrue($merged->child('unlisted')->all);
        }
    }

    /**
     * Test an empty definition avoids allocating a tree.
     */
    public function testEmptyDefinitionsReturnNull(): void
    {
        $this->assertNull(PartialTree::compile([]));
    }

    /**
     * Test malformed paths fail instead of being partially applied.
     */
    #[DataProvider('invalidPathProvider')]
    public function testRejectsInvalidPaths(string $path): void
    {
        $this->expectException(CannotPerformPartialOnDataField::class);

        PartialTree::compile([$path]);
    }

    /**
     * Provide malformed partial paths.
     */
    public static function invalidPathProvider(): array
    {
        return [
            'empty' => [''],
            'nested property on all' => ['*.name'],
            'nested property on multi-property' => ['{name, age}.name'],
            'empty segment' => ['artist..name'],
            'partial wildcard' => ['artist.na*'],
            'unclosed group' => ['artist.{name,email'],
            'empty group field' => ['artist.{name,}'],
        ];
    }

    /**
     * Get the selected paths of a compiled tree.
     *
     * @return list<string>
     */
    private static function paths(PartialTree $tree, string $prefix = ''): array
    {
        $paths = $tree->all ? [$prefix . '*'] : [];

        foreach ($tree->children as $property => $child) {
            if ($child->selected) {
                $paths[] = $prefix . $property;
            }

            array_push($paths, ...self::paths($child, $prefix . $property . '.'));
        }

        return $paths;
    }
}
