<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Filesystem\Filesystem;
use Hypervel\NestedSet\HasNode;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;

class NestedSetReadWriteTest extends TestCase
{
    private string $temporaryDirectory;

    private string $databasePath;

    protected function setUp(): void
    {
        $this->temporaryDirectory = ParallelTesting::tempDir('NestedSetReadWriteTest');
        (new Filesystem)->deleteDirectory($this->temporaryDirectory);
        mkdir($this->temporaryDirectory, 0777, true);
        $this->databasePath = $this->temporaryDirectory . '/database.sqlite';
        touch($this->databasePath);

        parent::setUp();

        Schema::connection('nested_set_split')->create(
            'nested_set_split_nodes',
            static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                NestedSet::columns($table);
            },
        );

        DB::connection('nested_set_split')
            ->table('nested_set_split_nodes')
            ->insert([
                ['id' => 1, 'name' => 'first root', '_lft' => 1, '_rgt' => 6, 'parent_id' => null, 'depth' => 0],
                ['id' => 2, 'name' => 'first child', '_lft' => 2, '_rgt' => 3, 'parent_id' => 1, 'depth' => 1],
                ['id' => 4, 'name' => 'second child', '_lft' => 4, '_rgt' => 5, 'parent_id' => 1, 'depth' => 1],
                ['id' => 3, 'name' => 'second root', '_lft' => 7, '_rgt' => 8, 'parent_id' => null, 'depth' => 0],
            ]);
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.connections.nested_set_split', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'read' => ['database' => $this->databasePath],
            'write' => ['database' => $this->databasePath],
            'sticky' => false,
            'foreign_key_constraints' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('nested_set_split');
        (new Filesystem)->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testOrdinaryLookupsUseTheReplicaAndMutationReadsUseTheWriter(): void
    {
        $connection = DB::connection('nested_set_split');
        $connection->enableQueryLog();

        NestedSetReadWriteNode::on('nested_set_split')->findOrFail(1);

        $this->assertSame('read', $connection->getQueryLog()[0]['readWriteType']);

        $connection->flushQueryLog();

        $this->assertSame(
            1,
            NestedSetReadWriteNode::on('nested_set_split')->depthForPosition(2),
        );

        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'read');

        $connection->flushQueryLog();

        $this->assertSame(
            1,
            NestedSetReadWriteNode::on('nested_set_split')
                ->useWritePdo()
                ->depthForPosition(2),
        );

        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');

        $connection->flushQueryLog();

        NestedSetReadWriteNode::on('nested_set_split')->moveNode(3, 2);

        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');

        $child = NestedSetReadWriteNode::on('nested_set_split')->findOrFail(2);
        $parent = NestedSetReadWriteNode::on('nested_set_split')->findOrFail(3);
        $connection->flushQueryLog();

        $child->appendToNode($parent)->save();

        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');

        $connection->table('nested_set_split_nodes')
            ->where('id', '=', 2)
            ->update(['parent_id' => null]);
        $connection->flushQueryLog();

        NestedSetReadWriteNode::on('nested_set_split')->fixTree();

        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');
    }

    public function testModelMutationSelectionsUseTheWriter(): void
    {
        $connection = DB::connection('nested_set_split');
        $connection->enableQueryLog();

        $parent = (new NestedSetReadWriteNode)->setConnection('nested_set_split');
        $parent->setRawAttributes([
            'id' => 1,
            '_lft' => 1,
            '_rgt' => 6,
            'parent_id' => null,
        ]);
        $newNode = (new NestedSetReadWriteNode)->setConnection('nested_set_split');
        $newNode->name = 'positioned child';
        $newNode->appendToNode($parent)->save();

        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');

        $connection->flushQueryLog();

        $newRoot = (new NestedSetReadWriteNode)->setConnection('nested_set_split');
        $newRoot->name = 'third root';
        $newRoot->saveAsRoot();

        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');

        $connection->flushQueryLog();

        $newChild = (new NestedSetReadWriteNode)->setConnection('nested_set_split');
        $newChild->name = 'new child';
        $newChild->parent_id = 1;
        $newChild->save();

        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');

        $node = NestedSetReadWriteNode::on('nested_set_split')->findOrFail(4);
        $connection->flushQueryLog();

        $this->assertTrue($node->up());
        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');

        $connection->flushQueryLog();

        $this->assertTrue($node->down());
        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');

        $root = NestedSetReadWriteEventedNode::on('nested_set_split')->findOrFail(1);
        $connection->flushQueryLog();

        $this->assertTrue($root->delete());
        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');
    }

    public function testRepairAndRebuildSelectionsUseTheWriter(): void
    {
        $connection = DB::connection('nested_set_split');
        $connection->enableQueryLog();
        $root = NestedSetReadWriteNode::on('nested_set_split')->findOrFail(1);
        $connection->flushQueryLog();

        NestedSetReadWriteNode::on('nested_set_split')->fixSubtree($root);

        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');

        $connection->flushQueryLog();

        NestedSetReadWriteNode::on('nested_set_split')->rebuildTree([]);

        $this->assertSelectQueriesUseConnection($connection->getQueryLog(), 'write');
    }

    private function assertSelectQueriesUseConnection(array $queries, string $readWriteType): void
    {
        $selects = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_starts_with($query['query'], 'select '),
        ));

        $this->assertNotEmpty($selects);

        foreach ($selects as $query) {
            $this->assertSame($readWriteType, $query['readWriteType']);
        }
    }
}

class NestedSetReadWriteNode extends Model
{
    use HasNode;

    public bool $timestamps = false;

    protected ?string $table = 'nested_set_split_nodes';
}

class NestedSetReadWriteEventedNode extends NestedSetReadWriteNode
{
    protected function shouldFireDescendantEvents(): bool
    {
        return true;
    }
}
