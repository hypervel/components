<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Creation;

use ArrayIterator;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\BaseDataCollectable;
use Hypervel\Data\Data;
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Data\Support\Creation\AutoLazyReplayMode;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Pagination\Paginator;
use Hypervel\Tests\TestCase;
use stdClass;
use Traversable;

class ConstructionStateTest extends TestCase
{
    /**
     * Test payload values are written and read at the current wire path.
     */
    public function testReadsAndWritesNestedPayloadValues(): void
    {
        $state = $this->state();
        $state->writePropertyValue(['title'], 'Hello');
        $state->enterProperty('author', ['writer']);
        $state->writePropertyValue(['name'], 'Ruben');

        $this->assertTrue($state->hasValue(['name']));
        $this->assertSame('Ruben', $state->getValue(['name']));
        $this->assertSame(['name' => 'Ruben'], $state->currentPayload());
        $this->assertFalse($state->hasValue(['missing']));
        $this->assertNull($state->getValue(['missing']));

        $state->leave();

        $this->assertSame([
            'title' => 'Hello',
            'writer' => ['name' => 'Ruben'],
        ], $state->payload());
        $this->assertFalse($state->hasValue(['name']));

        $state->replacePayload(['validated' => true]);

        $this->assertSame(['validated' => true], $state->payload());
    }

    /**
     * Test collection items retain concrete payload indices and paths.
     */
    public function testWritesCollectionItemsAndBuildsWirePaths(): void
    {
        $state = $this->state();
        $state->enterProperty('posts', [0]);
        $state->enterItem(3);
        $state->writePropertyValue(['title'], 'Fourth');

        $this->assertSame([0, 3], $state->path());
        $this->assertSame(2, $state->depth());

        $state->leave();
        $state->leave();

        $this->assertSame([0 => [3 => ['title' => 'Fourth']]], $state->payload());
    }

    /**
     * Test raw item keys never acquire mapped property path semantics.
     */
    public function testWritesRawCollectionItemKeysWithoutFlatteningOrCollisions(): void
    {
        $state = $this->state();
        $state->enterProperty('tenants', ['tenants']);

        foreach ([
            'tenant.eu' => 'Europe',
            'tenant' => 'Global',
            '*' => 'Wildcard',
        ] as $key => $name) {
            $state->writeItemValue($key, []);
            $state->enterItem($key);
            $state->writePropertyValue(['name'], $name);
            $state->leave();
        }

        $state->leave();

        $this->assertSame([
            'tenants' => [
                'tenant.eu' => ['name' => 'Europe'],
                'tenant' => ['name' => 'Global'],
                '*' => ['name' => 'Wildcard'],
            ],
        ], $state->payload());
        $this->assertSame(
            ['tenant.eu', 'tenant', '*'],
            array_keys($state->payload()['tenants']),
        );
        $this->assertArrayNotHasKey('eu', $state->payload()['tenants']['tenant']);
    }

    /**
     * Test mapped dot paths address nested payload values.
     */
    public function testReadsAndWritesMappedDotPaths(): void
    {
        $state = $this->state();
        $state->writePropertyValue(['profile', 'name'], 'Taylor');

        $this->assertTrue($state->hasValue(['profile', 'name']));
        $this->assertSame('Taylor', $state->getValue(['profile', 'name']));

        $state->enterProperty('author', ['people', '0']);
        $state->writePropertyValue(['contact', 'email'], 'taylor@example.com');

        $this->assertSame(['people', '0'], $state->path());
        $this->assertSame('taylor@example.com', $state->getValue(['contact', 'email']));

        $this->assertSame([
            'profile' => ['name' => 'Taylor'],
            'people' => [
                0 => [
                    'contact' => ['email' => 'taylor@example.com'],
                ],
            ],
        ], $state->payload());
    }

    /**
     * Test mappings and node classes are recorded in property structure space.
     */
    public function testRecordsStructureWithoutCollectionIndices(): void
    {
        $state = $this->state();
        $state->recordMapping('author', 'writer');

        $this->assertSame('writer', $state->originalKey('author'));
        $this->assertSame('title', $state->originalKey('title'));
        $this->assertSame(ConstructionStateDataFixture::class, $state->nodeClass());

        $state->enterProperty('posts', ['posts']);
        $state->enterItem(3);
        $state->recordMapping('title', 'post_title');
        $state->setNodeClass(ConstructionStateDataFixture::class);

        $this->assertSame('post_title', $state->originalKey('title'));
        $this->assertSame(ConstructionStateDataFixture::class, $state->nodeClass());

        $state->leave();
        $state->leave();

        $this->assertSame([
            'class' => ConstructionStateDataFixture::class,
            'mappings' => ['author' => 'writer'],
            'children' => [
                'posts' => [
                    'class' => ConstructionStateDataFixture::class,
                    'mappings' => ['title' => 'post_title'],
                    'children' => [],
                ],
            ],
        ], $state->structure());
    }

    /**
     * Test collection structure stores only values that differ from its template.
     */
    public function testRecordsSparseRawKeyItemOverrides(): void
    {
        $state = $this->state();
        $state->enterProperty('posts', ['posts']);
        $state->enterItem('first');
        $state->setNodeClass(ConstructionStateDataFixture::class);
        $state->recordMapping('title', 'post_title');
        $state->leave();
        $state->enterItem('same.item');
        $state->setNodeClass(ConstructionStateDataFixture::class);
        $state->recordMapping('title', 'post_title');

        $this->assertSame(ConstructionStateDataFixture::class, $state->nodeClass());
        $this->assertSame('post_title', $state->originalKey('title'));
        $this->assertTrue($state->isCurrentCollectionUniform());

        $state->leave();
        $state->enterItem('different.item');
        $state->setNodeClass(AlternateConstructionStateDataFixture::class);
        $state->recordMapping('title', 'title');

        $this->assertSame(AlternateConstructionStateDataFixture::class, $state->nodeClass());
        $this->assertSame('title', $state->originalKey('title'));
        $this->assertFalse($state->isCurrentCollectionUniform());

        $state->leave();
        $state->enterItem('first');

        $this->assertSame(ConstructionStateDataFixture::class, $state->nodeClass());
        $this->assertSame('post_title', $state->originalKey('title'));

        $state->leave();
        $state->leave();

        $posts = $state->structure()['children']['posts'];

        $this->assertFalse($posts['uniform']);
        $this->assertSame(
            AlternateConstructionStateDataFixture::class,
            $posts['items']['different.item']['class'],
        );
        $this->assertSame('title', $posts['items']['different.item']['mappings']['title']);
        $this->assertArrayNotHasKey('same.item', $posts['items']);
    }

    /**
     * Test a nested difference latches every enclosing collection.
     */
    public function testNestedOverridesLatchEveryEnclosingCollection(): void
    {
        $state = $this->state();
        $state->enterProperty('posts', ['posts']);
        $state->enterItem(0);
        $state->enterProperty('comments', ['comments']);
        $state->enterItem(0);
        $state->recordMapping('label', 'label');
        $state->leave();
        $state->leave();
        $state->leave();
        $state->enterItem(1);
        $state->enterProperty('comments', ['comments']);
        $state->enterItem(0);
        $state->recordMapping('label', 'comment_label');

        $this->assertSame('comment_label', $state->originalKey('label'));
        $this->assertFalse($state->isCurrentCollectionUniform());

        $state->leave();
        $state->leave();
        $state->leave();

        $this->assertFalse($state->isCurrentCollectionUniform());

        $state->leave();

        $posts = $state->structure()['children']['posts'];

        $this->assertFalse($posts['uniform']);
        $this->assertFalse($posts['children']['comments']['uniform']);
        $this->assertSame(
            'comment_label',
            $posts['items'][1]['children']['comments']['items'][0]['mappings']['label'],
        );
    }

    /**
     * Test finished data values make the containing collection non-uniform.
     */
    public function testFinishedDataValuesLatchContainingCollection(): void
    {
        $state = $this->state();
        $state->enterProperty('posts', ['posts']);
        $state->enterItem(0);
        $state->writeFinishedPropertyValue(['author'], new ConstructionStateFinishedDataFixture);

        $this->assertFalse($state->isCurrentCollectionUniform());

        $state->leave();
        $state->leave();

        $this->assertFalse($state->structure()['children']['posts']['uniform']);
    }

    /**
     * Test finished data values create their structural path before latching.
     */
    public function testFinishedDataValuesCreateCurrentStructurePath(): void
    {
        $state = $this->state();
        $state->enterProperty('posts', ['posts']);
        $state->enterItem(0);
        $state->enterProperty('author', ['author']);
        $state->writeFinishedPropertyValue(['profile'], new ConstructionStateFinishedDataFixture);

        $state->leave();
        $state->leave();
        $state->leave();

        $posts = $state->structure()['children']['posts'];

        $this->assertFalse($posts['uniform']);
        $this->assertArrayHasKey('author', $posts['children']);
    }

    /**
     * Test finished data collectables make the containing collection non-uniform.
     */
    public function testFinishedDataCollectablesLatchContainingCollection(): void
    {
        $state = $this->state();
        $state->enterProperty('posts', ['posts']);
        $state->enterItem(0);
        $state->writeFinishedPropertyValue(['comments'], new ConstructionStateFinishedDataCollectableFixture);

        $this->assertFalse($state->isCurrentCollectionUniform());

        $state->leave();
        $state->leave();

        $this->assertFalse($state->structure()['children']['posts']['uniform']);
    }

    /**
     * Test paginator sources are isolated from validation structure and sibling items.
     */
    public function testRecordsPaginatorSourcesWithoutChangingCollectionUniformity(): void
    {
        $first = new Paginator([1], 10, 1);
        $second = new Paginator([2], 10, 1);
        $state = $this->state();
        $state->enterProperty('posts', ['posts']);
        $state->enterItem(0);
        $state->enterProperty('comments', ['comments']);
        $state->recordPaginatorSource($first);

        $this->assertSame($first, $state->paginatorSource());

        $state->leave();

        $this->assertTrue($state->isCurrentCollectionUniform());

        $state->leave();
        $state->enterItem(1);
        $state->enterProperty('comments', ['comments']);

        $this->assertNull($state->paginatorSource());

        $state->recordPaginatorSource($second);

        $this->assertSame($second, $state->paginatorSource());

        $state->leave();

        $this->assertTrue($state->isCurrentCollectionUniform());

        $state->leave();
        $state->leave();

        $comments = $state->structure()['children']['posts']['items'];

        $this->assertSame($first, $comments[0]['children']['comments']['paginatorSource']);
        $this->assertSame($second, $comments[1]['children']['comments']['paginatorSource']);
        $this->assertArrayNotHasKey('uniform', $state->structure()['children']['posts']);
    }

    /**
     * Test paginator sources clear with their owning structure node.
     */
    public function testClearsPaginatorSourcesWithoutAllocatingMissingOverrides(): void
    {
        $state = $this->state();
        $state->enterProperty('comments', ['comments']);
        $state->recordPaginatorSource(new Paginator([1], 10, 1));

        $this->assertNotNull($state->paginatorSource());

        $state->clearPaginatorSource();

        $this->assertNull($state->paginatorSource());

        $state->leave();
        $state->enterProperty('posts', ['posts']);
        $state->enterItem(5);
        $state->enterProperty('comments', ['comments']);
        $state->clearPaginatorSource();

        $this->assertNull($state->paginatorSource());

        $state->leave();
        $state->leave();
        $state->leave();

        $this->assertArrayNotHasKey('posts', $state->structure()['children']);
    }

    /**
     * Test automatic lazy recipes retain exact node ownership and explicit null.
     */
    public function testRecordsAutomaticLazyRecipesWithoutTemplateFallback(): void
    {
        $first = new stdClass;
        $second = new stdClass;
        $state = $this->state();
        $state->recordAutoLazy('nullable', null);

        $this->assertSame(['source' => null], $state->autoLazy('nullable'));
        $this->assertInstanceOf(UnknownProperty::class, $state->autoLazy('missing'));

        $state->enterProperty('posts', ['posts']);
        $state->enterItem(0);
        $state->recordAutoLazy('title', $first, AutoLazyReplayMode::Normal);

        $this->assertSame([
            'source' => $first,
            'replay' => AutoLazyReplayMode::Normal,
        ], $state->autoLazy('title'));
        $this->assertTrue($state->isCurrentCollectionUniform());

        $state->leave();
        $state->enterItem(1);

        $this->assertInstanceOf(UnknownProperty::class, $state->autoLazy('title'));

        $state->recordAutoLazy('title', $second, AutoLazyReplayMode::Hook);
        $state->resetNodeStructure();

        $this->assertInstanceOf(UnknownProperty::class, $state->autoLazy('title'));
        $this->assertFalse($state->isCurrentCollectionUniform());

        $state->leave();
        $state->enterItem(0);

        $this->assertSame([
            'source' => $first,
            'replay' => AutoLazyReplayMode::Normal,
        ], $state->autoLazy('title'));
    }

    /**
     * Test automatic lazy snapshots retain only the selected payload and structure.
     */
    public function testSnapshotsAutomaticLazyPropertyState(): void
    {
        $rootSource = new stdClass;
        $itemSource = new stdClass;
        $descendantSource = new stdClass;
        $descendantPaginator = new Paginator([1], 10, 1);
        $state = $this->state();
        $state->writePropertyValue(['outside'], 'drop');
        $state->recordMapping('posts', 'posts');
        $state->recordMapping('outside', 'outside');
        $state->recordAutoLazy('outside', $rootSource);
        $state->recordPaginatorSource(new Paginator([0], 10, 1));
        $state->recordUnknownInput(['outside' => 'drop']);
        $state->enterProperty('posts', ['posts']);
        $state->writeItemValue(0, ['lazy' => ['value' => 'template']]);
        $state->enterItem(0);
        $state->setNodeClass(ConstructionStateDataFixture::class);
        $state->recordMapping('lazy', 'lazy');
        $state->enterProperty('lazy', ['lazy']);
        $state->setNodeClass(ConstructionStateDataFixture::class);
        $state->recordMapping('value', 'template_value');
        $state->leave();
        $state->leave();
        $state->writeItemValue(5, [
            'lazy_value' => ['value' => 'selected'],
            'sibling' => 'retain',
        ]);
        $state->enterItem(5);
        $state->setNodeClass(AlternateConstructionStateDataFixture::class);
        $state->recordMapping('lazy', 'lazy_value');
        $state->recordMapping('sibling', 'sibling');
        $state->recordAutoLazy('lazy', $itemSource, AutoLazyReplayMode::Normal);
        $state->recordAutoLazy('sibling', new stdClass);
        $state->recordPaginatorSource(new Paginator([5], 10, 1));
        $state->enterProperty('lazy', ['lazy_value']);
        $state->setNodeClass(ConstructionStateDataFixture::class);
        $state->recordMapping('value', 'value');
        $state->recordAutoLazy('nested', $descendantSource, AutoLazyReplayMode::Hook);
        $state->recordPaginatorSource($descendantPaginator);
        $state->leave();

        $snapshot = $state->snapshotForProperty('lazy');

        $this->assertSame(['posts', 5], $snapshot->path());
        $this->assertSame([
            'posts' => [
                5 => [
                    'lazy_value' => ['value' => 'selected'],
                    'sibling' => 'retain',
                ],
            ],
        ], $snapshot->payload());
        $this->assertNull($snapshot->unknownInput());
        $this->assertInstanceOf(UnknownProperty::class, $snapshot->autoLazy('lazy'));
        $this->assertNull($snapshot->paginatorSource());

        $structure = $snapshot->structure();

        $this->assertSame(['posts'], array_keys($structure['children']));
        $this->assertArrayNotHasKey('autoLazy', $structure);
        $this->assertArrayNotHasKey('paginatorSource', $structure);
        $this->assertSame(['lazy'], array_keys($structure['children']['posts']['mappings']));
        $this->assertSame(
            ['lazy'],
            array_keys($structure['children']['posts']['items'][5]['mappings']),
        );

        $snapshot->enterProperty('lazy', ['lazy_value']);

        $this->assertSame([
            'source' => $descendantSource,
            'replay' => AutoLazyReplayMode::Hook,
        ], $snapshot->autoLazy('nested'));
        $this->assertSame($descendantPaginator, $snapshot->paginatorSource());
        $this->assertSame(
            ['value'],
            array_keys($snapshot->structure()['children']['posts']['items'][5]['children']['lazy']['mappings']),
        );
    }

    /**
     * Test read-only structure lookups do not allocate nodes.
     */
    public function testReadOnlyStructureLookupsDoNotCreateNodes(): void
    {
        $state = $this->state();
        $state->enterProperty('unvisited', ['unvisited']);

        $this->assertFalse($state->hasOriginalKey('name'));
        $this->assertSame('name', $state->originalKey('name'));
        $this->assertNull($state->nodeClass());

        $state->recordMapping('name', 'name');

        $this->assertTrue($state->hasOriginalKey('name'));
        $this->assertSame('name', $state->originalKey('name'));

        $state->leave();

        $this->assertSame(['unvisited'], array_keys($state->structure()['children']));
    }

    /**
     * Test strict node input snapshots merge at their observed wire paths.
     */
    public function testRecordsRootShapedUnknownInput(): void
    {
        $state = $this->state();

        $this->assertNull($state->unknownInput());

        $state->recordUnknownInput([
            'child' => ['fromParent' => true],
            'scalarChild' => 'raw',
        ]);
        $state->enterProperty('child', ['child']);
        $state->recordUnknownInput(['fromChild' => true]);
        $state->leave();
        $state->enterProperty('scalarChild', ['scalarChild']);
        $state->recordUnknownInput(['structured' => true]);
        $state->leave();

        $this->assertSame([
            'child' => [
                'fromParent' => true,
                'fromChild' => true,
            ],
            'scalarChild' => ['structured' => true],
        ], $state->unknownInput());
    }

    /**
     * Create state for one fixture operation.
     */
    protected function state(): ConstructionState
    {
        $context = new CreationContext(ConstructionStateDataFixture::class);

        return ConstructionState::create($context, ConstructionStateDataFixture::class);
    }
}

abstract class ConstructionStateDataFixture implements BaseData
{
}

abstract class AlternateConstructionStateDataFixture implements BaseData
{
}

class ConstructionStateFinishedDataFixture extends Data
{
}

/**
 * @implements BaseDataCollectable<int, ConstructionStateFinishedDataFixture>
 */
class ConstructionStateFinishedDataCollectableFixture implements BaseDataCollectable
{
    /**
     * Get the data class stored by the collection.
     */
    public function getDataClass(): string
    {
        return ConstructionStateFinishedDataFixture::class;
    }

    /**
     * Get an iterator for the data items.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator;
    }
}
