<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\FoundationInteractsWithDatabaseTest;

use Hypervel\Database\Capsule\Manager;
use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\Concerns\InteractsWithDatabase;
use Hypervel\Foundation\Testing\TestCase as TestingTestCase;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Concerns\CreatesApplication;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\ExpectationFailedException;

class FoundationInteractsWithDatabaseTest extends TestCase
{
    use InteractsWithDatabase;
    use VerifiesDoubles;

    protected string $table = 'products';

    protected array $data = [
        'title' => 'Spark',
        'name' => 'Hypervel',
    ];

    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Double::for(Connection::class)->strict();
    }

    public function testSeeInDatabaseFindsResults(): void
    {
        $this->mockCountBuilder(true);

        $this->assertDatabaseHas($this->table, $this->data);
    }

    public function testAssertDatabaseHasSupportsModelClass(): void
    {
        $this->mockCountBuilder(true);

        $this->assertDatabaseHas(ProductStub::class, $this->data);
    }

    public function testAssertDatabaseHasConstrainsToModel(): void
    {
        $data = $this->data;

        $this->data = [
            'id' => 1,
            ...$this->data,
        ];

        $this->mockCountBuilder(true);

        $this->assertDatabaseHas(new ProductStub(['id' => 1]), $data);
    }

    public function testAssertDatabaseSupportsArrays(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('where')->ordered()->with(['title' => 'Spark', 'name' => 'Hypervel'])->returns($builder);
        $builder->expects('where')->ordered()->with(['title' => 'Forge', 'name' => 'Hypervel'])->returns($builder);
        $builder->expects('exists')->times(2)->returns(true);

        $this->connection->allows('table')->with($this->table)->returns($builder);

        $this->assertDatabaseHas($this->table, [
            ['title' => 'Spark', 'name' => 'Hypervel'],
            ['title' => 'Forge', 'name' => 'Hypervel'],
        ]);
    }

    public function testSeeInDatabaseDoesNotFindResults(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The table is empty.'));

        $this->useInMemoryConnection();

        $this->assertDatabaseHas($this->table, $this->data);
    }

    public function testSeeInDatabaseFindsNotMatchingResults(): void
    {
        $row = ['title' => 'Spark', 'name' => 'Forge'];

        $this->useInMemoryConnection()->table($this->table)->insert($row);

        $this->expectExceptionObject(new ExpectationFailedException('Found similar results: ' . json_encode([$row], JSON_PRETTY_PRINT)));

        $this->assertDatabaseHas($this->table, $this->data);
    }

    public function testSeeInDatabaseFindsManyNotMatchingResults(): void
    {
        $row = ['title' => 'Spark', 'name' => 'Forge'];

        $this->useInMemoryConnection()->table($this->table)->insert(array_fill(0, 5, $row));

        $this->expectExceptionObject(new ExpectationFailedException('Found similar results: ' . json_encode(array_fill(0, 3, $row), JSON_PRETTY_PRINT) . ' and 2 others.'));

        $this->assertDatabaseHas($this->table, $this->data);
    }

    public function testAssertDatabaseMissingSupportsArrays(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('where')->ordered()->with(['title' => 'Spark', 'name' => 'Hypervel'])->returns($builder);
        $builder->expects('where')->ordered()->with(['title' => 'Forge', 'name' => 'Hypervel'])->returns($builder);
        $builder->expects('exists')->times(2)->returns(false);

        $this->connection->allows('table')->with($this->table)->returns($builder);

        $this->assertDatabaseMissing($this->table, [
            ['title' => 'Spark', 'name' => 'Hypervel'],
            ['title' => 'Forge', 'name' => 'Hypervel'],
        ]);
    }

    public function testDontSeeInDatabaseDoesNotFindResults(): void
    {
        $this->mockCountBuilder(false);

        $this->assertDatabaseMissing($this->table, $this->data);
    }

    public function testAssertDatabaseMissingSupportsModelClass(): void
    {
        $this->mockCountBuilder(false);

        $this->assertDatabaseMissing(ProductStub::class, $this->data);
    }

    public function testAssertDatabaseMissingConstrainsToModel(): void
    {
        $data = $this->data;

        $this->data = [
            'id' => 1,
            ...$this->data,
        ];

        $this->mockCountBuilder(false);

        $this->assertDatabaseMissing(new ProductStub(['id' => 1]), $data);
    }

    public function testDontSeeInDatabaseFindsResults(): void
    {
        $this->expectException(ExpectationFailedException::class);

        $this->useInMemoryConnection()->table($this->table)->insert($this->data);

        $this->assertDatabaseMissing($this->table, $this->data);
    }

    public function testAssertTableEntriesCount(): void
    {
        $this->mockCountBuilder(true);

        $this->assertDatabaseCount($this->table, 1);
    }

    public function testAssertDatabaseCountSupportModels(): void
    {
        $this->mockCountBuilder(true);

        $this->assertDatabaseCount(ProductStub::class, 1);
        $this->assertDatabaseCount(new ProductStub, 1);
    }

    public function testAssertDatabaseCountSupportsArraysOfTablesAndCounts(): void
    {
        $connection = $this->useInMemoryConnection();
        $connection->table('products')->insert([
            ['title' => 'Spark', 'name' => 'Hypervel'],
            ['title' => 'Forge', 'name' => 'Hypervel'],
        ]);
        $connection->table('orders')->insert(array_fill(0, 5, ['title' => 'Order']));

        $this->assertDatabaseCount([
            ProductStub::class => 2,
            OrderStub::class => 5,
        ]);
    }

    public function testAssertDatabaseEmpty(): void
    {
        $this->mockCountBuilder(false);

        $this->assertDatabaseEmpty(ProductStub::class);
        $this->assertDatabaseEmpty(new ProductStub);
    }

    public function testAssertDatabaseEmptySupportsArrays(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('count')->times(2)->returns(0);

        $this->connection->expects('table')->with($this->table)->returns($builder);
        $this->connection->expects('table')->with('orders')->returns($builder);

        $this->assertDatabaseEmpty([ProductStub::class, OrderStub::class]);
    }

    public function testAssertTableEntriesCountWrong(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('Failed asserting that table [products] matches expected entries count of 3. Entries found: 1.'));
        $this->mockCountBuilder(true);

        $this->assertDatabaseCount($this->table, 3);
    }

    public function testAssertSoftDeletedSupportsArrays(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('where')->ordered()->with(['title' => 'Spark', 'name' => 'Hypervel'])->returns($builder);
        $builder->expects('where')->ordered()->with(['title' => 'Forge', 'name' => 'Hypervel'])->returns($builder);
        $builder->expects('whereNotNull')->with('deleted_at')->times(2)->returns($builder);
        $builder->expects('exists')->times(2)->returns(true);

        $this->connection->allows('table')->with($this->table)->returns($builder);

        $this->assertSoftDeleted($this->table, [
            ['title' => 'Spark', 'name' => 'Hypervel'],
            ['title' => 'Forge', 'name' => 'Hypervel'],
        ]);
    }

    public function testAssertSoftDeletedSupportsModelWithArrays(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('where')->ordered()->with(['title' => 'Spark', 'id' => 1])->returns($builder);
        $builder->expects('where')->ordered()->with(['name' => 'Hypervel', 'id' => 1])->returns($builder);
        $builder->expects('whereNotNull')->with('trashed_at')->times(2)->returns($builder);
        $builder->expects('exists')->times(2)->returns(true);

        $this->connection->allows('table')->with($this->table)->returns($builder);

        $this->assertSoftDeleted(new CustomProductStub(['id' => 1]), [
            ['title' => 'Spark'],
            ['name' => 'Hypervel'],
        ]);
    }

    public function testAssertNotSoftDeletedSupportsArrays(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('where')->ordered()->with(['title' => 'Spark', 'name' => 'Hypervel'])->returns($builder);
        $builder->expects('where')->ordered()->with(['title' => 'Forge', 'name' => 'Hypervel'])->returns($builder);
        $builder->expects('whereNull')->with('deleted_at')->times(2)->returns($builder);
        $builder->expects('exists')->times(2)->returns(true);

        $this->connection->allows('table')->with($this->table)->returns($builder);

        $this->assertNotSoftDeleted($this->table, [
            ['title' => 'Spark', 'name' => 'Hypervel'],
            ['title' => 'Forge', 'name' => 'Hypervel'],
        ]);
    }

    public function testAssertNotSoftDeletedSupportsModelWithArrays(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('where')->ordered()->with(['title' => 'Spark', 'id' => 1])->returns($builder);
        $builder->expects('where')->ordered()->with(['name' => 'Hypervel', 'id' => 1])->returns($builder);
        $builder->expects('whereNull')->with('trashed_at')->times(2)->returns($builder);
        $builder->expects('exists')->times(2)->returns(true);

        $this->connection->allows('table')->with($this->table)->returns($builder);

        $this->assertNotSoftDeleted(new CustomProductStub(['id' => 1]), [
            ['title' => 'Spark'],
            ['name' => 'Hypervel'],
        ]);
    }

    public function testAssertSoftDeletedTableSupportsIterablesWithCustomDeletedAtColumn(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('where')->with($this->data)->times(2)->returns($builder);
        $builder->expects('whereNotNull')->with('removed_at')->times(2)->returns($builder);
        $builder->expects('exists')->times(2)->returns(true);

        $this->connection->expects('table')->with($this->table)->returns($builder);
        $this->connection->expects('table')->with('orders')->returns($builder);

        $this->assertSoftDeleted(['products', 'orders'], $this->data, deletedAtColumn: 'removed_at');
    }

    public function testAssertNotSoftDeletedTableSupportsIterablesWithCustomDeletedAtColumn(): void
    {
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('where')->with($this->data)->times(2)->returns($builder);
        $builder->expects('whereNull')->with('removed_at')->times(2)->returns($builder);
        $builder->expects('exists')->times(2)->returns(true);

        $this->connection->expects('table')->with($this->table)->returns($builder);
        $this->connection->expects('table')->with('orders')->returns($builder);

        $this->assertNotSoftDeleted(['products', 'orders'], $this->data, deletedAtColumn: 'removed_at');
    }

    public function testAssertDatabaseMissingPassesWhenDoesNotFindResults(): void
    {
        $this->mockCountBuilder(false);

        $this->assertDatabaseMissing($this->table, $this->data);
    }

    public function testAssertDatabaseMissingFailsWhenFindsResults(): void
    {
        $this->expectException(ExpectationFailedException::class);

        $this->useInMemoryConnection()->table($this->table)->insert($this->data);

        $this->assertDatabaseMissing($this->table, $this->data);
    }

    public function testAssertModelMissingPassesWhenDoesNotFindModelResults(): void
    {
        $this->useInMemoryConnection();

        $this->assertModelMissing(new ProductStub(['id' => 1]));
    }

    public function testAssertModelMissingFailsWhenFindsModelResults(): void
    {
        $this->expectException(ExpectationFailedException::class);

        $this->useInMemoryConnection()->table($this->table)->insert(['id' => 1, 'title' => 'Spark', 'name' => 'Hypervel']);

        $this->assertModelMissing(new ProductStub(['id' => 1]));
    }

    public function testAssertModelExistsFailsWhenDoesNotFindModelResults(): void
    {
        $this->expectException(ExpectationFailedException::class);

        $this->useInMemoryConnection();

        $this->assertModelExists(new ProductStub(['id' => 1]));
    }

    public function testAssertSoftDeletedInDatabaseFindsResults(): void
    {
        $this->mockCountBuilder(true);

        $this->assertSoftDeleted($this->table, $this->data);
    }

    public function testAssertSoftDeletedSupportModelStrings(): void
    {
        $this->mockCountBuilder(true);

        $this->assertSoftDeleted(ProductStub::class, $this->data);
        $this->assertSoftDeleted(ProductStub::class, $this->data, deletedAtColumn: null);
    }

    public function testAssertSoftDeletedInDatabaseDoesNotFindResults(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The table is empty.'));

        $this->useInMemoryConnection();

        $this->assertSoftDeleted($this->table, $this->data);
    }

    public function testAssertSoftDeletedInDatabaseDoesNotFindModelResults(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The table is empty.'));

        $this->useInMemoryConnection();

        $this->assertSoftDeleted(new ProductStub(['id' => 1]));
    }

    public function testAssertSoftDeletedInDatabaseDoesNotFindModelWithCustomColumnResults(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The table is empty.'));

        $model = new CustomProductStub(['id' => 1, 'name' => 'Hypervel']);

        $this->useInMemoryConnection();

        $this->assertSoftDeleted($model, ['name' => 'Tailwind']);
    }

    public function testAssertSoftDeletedInDatabaseDoesNotFindModePassedViaFcnWithCustomColumnResults(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The table is empty.'));

        $model = new CustomProductStub(['id' => 1, 'name' => 'Hypervel']);

        $this->useInMemoryConnection();

        $this->assertSoftDeleted(CustomProductStub::class, ['id' => $model->id]);
    }

    public function testAssertNotSoftDeletedInDatabaseFindsResults(): void
    {
        $this->mockCountBuilder(true);

        $this->assertNotSoftDeleted($this->table, $this->data);
    }

    public function testAssertNotSoftDeletedSupportModelStrings(): void
    {
        $this->mockCountBuilder(true);

        $this->assertNotSoftDeleted(ProductStub::class, $this->data);
        $this->assertNotSoftDeleted(ProductStub::class, $this->data, deletedAtColumn: null);
    }

    public function testAssertNotSoftDeletedOnlyFindsMatchingModels(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('Failed asserting that any existing row'));

        $this->useInMemoryConnection();

        $this->assertNotSoftDeleted(ProductStub::class, $this->data);
    }

    public function testAssertNotSoftDeletedInDatabaseDoesNotFindResults(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The table is empty.'));

        $this->useInMemoryConnection();

        $this->assertNotSoftDeleted($this->table, $this->data);
    }

    public function testAssertNotSoftDeletedInDatabaseDoesNotFindModelResults(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The table is empty.'));

        $this->useInMemoryConnection();

        $this->assertNotSoftDeleted(new ProductStub(['id' => 1]));
    }

    public function testAssertNotSoftDeletedInDatabaseDoesNotFindModelWithCustomColumnResults(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The table is empty.'));

        $model = new CustomProductStub(['id' => 1, 'name' => 'Hypervel']);

        $this->useInMemoryConnection();

        $this->assertNotSoftDeleted($model, ['name' => 'Tailwind']);
    }

    public function testAssertNotSoftDeletedInDatabaseDoesNotFindModelPassedViaFcnWithCustomColumnResults(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The table is empty.'));

        $model = new CustomProductStub(['id' => 1, 'name' => 'Hypervel']);

        $this->useInMemoryConnection();

        $this->assertNotSoftDeleted(CustomProductStub::class, ['id' => $model->id]);
    }

    public function testAssertExistsPassesWhenFindsResults(): void
    {
        $this->useInMemoryConnection()->table($this->table)->insert(['id' => 1, 'title' => 'Spark', 'name' => 'Hypervel']);

        $this->assertModelExists(new ProductStub(['id' => 1]));
    }

    public function testGetTableNameFromModel(): void
    {
        $this->assertEquals($this->table, $this->getTable(ProductStub::class));
        $this->assertEquals($this->table, $this->getTable(new ProductStub));
        $this->assertEquals($this->table, $this->getTable($this->table));
        $this->assertSame('all_products', $this->getTable((new ProductStub)->setTable('all_products')));
    }

    public function testGetTableConnectionNameFromModel(): void
    {
        $this->assertNull($this->getTableConnection(ProductStub::class));
        $this->assertNull($this->getTableConnection(new ProductStub));
        $this->assertSame('mysql', $this->getTableConnection((new ProductStub)->setConnection('mysql')));
    }

    public function testGetTableCustomizedDeletedAtColumnName(): void
    {
        $this->assertSame('trashed_at', $this->getDeletedAtColumn(CustomProductStub::class));
        $this->assertSame('trashed_at', $this->getDeletedAtColumn(new CustomProductStub));
    }

    public function testExpectsDatabaseQueryCount(): void
    {
        $case = new class('testExpectsDatabaseQueryCount') extends TestingTestCase {
            use CreatesApplication;

            public function testExpectsDatabaseQueryCount(): void
            {
                $this->expectsDatabaseQueryCount(0);
            }
        };

        $case->setUp();
        $case->testExpectsDatabaseQueryCount();
        $case->tearDown();

        $case = new class('testExpectsDatabaseQueryCount') extends TestingTestCase {
            use CreatesApplication;

            public function testExpectsDatabaseQueryCount(): void
            {
                $this->expectsDatabaseQueryCount(3);
            }
        };

        $case->setUp();
        $case->testExpectsDatabaseQueryCount();

        try {
            $case->tearDown();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertSame("Expected 3 database queries on the [testing] connection. 0 occurred.\nFailed asserting that 0 is identical to 3.", $e->getMessage());
        }

        $case = new class('testExpectsDatabaseQueryCount') extends TestingTestCase {
            use CreatesApplication;

            public function testExpectsDatabaseQueryCount(): void
            {
                $this->expectsDatabaseQueryCount(3);

                DB::pretend(function ($db) {
                    $db->table('foo')->count();
                    $db->table('foo')->count();
                    $db->table('foo')->count();
                    $db->table('foo')->count();
                });
            }
        };

        $case->setUp();
        $case->testExpectsDatabaseQueryCount();

        try {
            $case->tearDown();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertSame("Expected 3 database queries on the [testing] connection. 4 occurred.\nFailed asserting that 4 is identical to 3.", $e->getMessage());
        }

        $case = new class('testExpectsDatabaseQueryCount') extends TestingTestCase {
            use CreatesApplication;

            public function testExpectsDatabaseQueryCount(): void
            {
                $this->expectsDatabaseQueryCount(4);
                $this->expectsDatabaseQueryCount(1, 'mysql');

                DB::pretend(function ($db) {
                    $db->table('foo')->count();
                    $db->table('foo')->count();
                    $db->table('foo')->count();
                });

                DB::connection('mysql')->pretend(function ($db) {
                    $db->table('foo')->count();
                });
            }
        };

        $case->setUp();
        $case->testExpectsDatabaseQueryCount();
        $case->tearDown();
    }

    /**
     * Mock the query used by database assertions.
     *
     * @param null|int|list<int> $countResult
     */
    protected function mockCountBuilder(bool $existsResult, string $deletedAtColumn = 'deleted_at', array|int|null $countResult = null): Builder&DoubleInterface
    {
        $builder = Double::for(Builder::class)->strict();

        $countResult = Arr::wrap($countResult);
        $countResult = ! empty($countResult) ? $countResult : [$existsResult ? 1 : 0];

        $key = array_key_first($this->data);
        $value = $this->data[$key];

        $builder->allows('where')->with($key, $value)->returns($builder);

        $builder->allows('select')->with(array_keys($this->data))->returns($builder);

        $builder->allows('limit')->returns($builder);

        $builder->allows('where')->with($this->data)->returns($builder);

        $builder->allows('whereNotNull')->with($deletedAtColumn)->returns($builder);

        $builder->allows('whereNull')->with($deletedAtColumn)->returns($builder);

        $builder->allows('exists')->returns($existsResult);

        $builder->allows('count')->returns(...$countResult);

        $this->connection->allows('table')
            ->with($this->table)
            ->returns($builder);

        return $builder;
    }

    /**
     * Get the mocked database connection.
     */
    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Use an in-memory SQLite connection for the database assertions.
     */
    protected function useInMemoryConnection(): Connection
    {
        $capsule = new Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);

        $this->connection = $capsule->getConnection();

        $this->connection->getSchemaBuilder()->create('products', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('name')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('trashed_at')->nullable();
        });

        $this->connection->getSchemaBuilder()->create('orders', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        return $this->connection;
    }
}

class ProductStub extends Model
{
    use SoftDeletes;

    protected ?string $table = 'products';

    protected array $guarded = [];
}

class CustomProductStub extends ProductStub
{
    public const string DELETED_AT = 'trashed_at';
}

class OrderStub extends Model
{
    protected ?string $table = 'orders';

    protected array $guarded = [];
}
