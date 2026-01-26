<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Query;

use Hypervel\Database\Query\Builder;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class QueryBuilderTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('qb_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('stock')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('qb_products')->insert([
            ['name' => 'Widget A', 'category' => 'widgets', 'price' => 19.99, 'stock' => 100, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Widget B', 'category' => 'widgets', 'price' => 29.99, 'stock' => 50, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Gadget X', 'category' => 'gadgets', 'price' => 99.99, 'stock' => 25, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Gadget Y', 'category' => 'gadgets', 'price' => 149.99, 'stock' => 10, 'active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Tool Z', 'category' => 'tools', 'price' => 49.99, 'stock' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function table(): Builder
    {
        return DB::table('qb_products');
    }

    public function testSelectAll(): void
    {
        $products = $this->table()->get();

        $this->assertCount(5, $products);
    }

    public function testSelectSpecificColumns(): void
    {
        $product = $this->table()->select('name', 'price')->first();

        $this->assertSame('Widget A', $product->name);
        $this->assertEquals(19.99, $product->price);
        $this->assertObjectNotHasProperty('category', $product);
    }

    public function testWhereEquals(): void
    {
        $products = $this->table()->where('category', 'widgets')->get();

        $this->assertCount(2, $products);
    }

    public function testWhereWithOperator(): void
    {
        $products = $this->table()->where('price', '>', 50)->get();

        $this->assertCount(2, $products);
    }

    public function testWhereIn(): void
    {
        $products = $this->table()->whereIn('category', ['widgets', 'tools'])->get();

        $this->assertCount(3, $products);
    }

    public function testWhereNotIn(): void
    {
        $products = $this->table()->whereNotIn('category', ['widgets'])->get();

        $this->assertCount(3, $products);
    }

    public function testWhereNull(): void
    {
        $this->table()->where('name', 'Tool Z')->update(['category' => null]);

        $products = $this->table()->whereNull('category')->get();

        $this->assertCount(1, $products);
        $this->assertSame('Tool Z', $products->first()->name);
    }

    public function testWhereNotNull(): void
    {
        $this->table()->where('name', 'Tool Z')->update(['category' => null]);

        $products = $this->table()->whereNotNull('category')->get();

        $this->assertCount(4, $products);
    }

    public function testWhereBetween(): void
    {
        $products = $this->table()->whereBetween('price', [20, 100])->get();

        $this->assertCount(3, $products);
    }

    public function testOrWhere(): void
    {
        $products = $this->table()
            ->where('category', 'widgets')
            ->orWhere('category', 'tools')
            ->get();

        $this->assertCount(3, $products);
    }

    public function testWhereNested(): void
    {
        $products = $this->table()
            ->where('active', true)
            ->where(function ($query) {
                $query->where('category', 'widgets')
                    ->orWhere('price', '>', 100);
            })
            ->get();

        $this->assertCount(2, $products);
    }

    public function testOrderBy(): void
    {
        $products = $this->table()->orderBy('price', 'desc')->get();

        $this->assertSame('Gadget Y', $products->first()->name);
        $this->assertSame('Widget A', $products->last()->name);
    }

    public function testOrderByMultiple(): void
    {
        $products = $this->table()
            ->orderBy('category')
            ->orderBy('price', 'desc')
            ->get();

        $first = $products->first();
        $this->assertSame('gadgets', $first->category);
        $this->assertEquals(149.99, $first->price);
    }

    public function testLimit(): void
    {
        $products = $this->table()->limit(2)->get();

        $this->assertCount(2, $products);
    }

    public function testOffset(): void
    {
        $products = $this->table()->orderBy('id')->offset(2)->limit(2)->get();

        $this->assertCount(2, $products);
        $this->assertSame('Gadget X', $products->first()->name);
    }

    public function testFirst(): void
    {
        $product = $this->table()->where('category', 'gadgets')->first();

        $this->assertSame('Gadget X', $product->name);
    }

    public function testFind(): void
    {
        $first = $this->table()->first();
        $product = $this->table()->find($first->id);

        $this->assertSame($first->name, $product->name);
    }

    public function testValue(): void
    {
        $name = $this->table()->where('category', 'tools')->value('name');

        $this->assertSame('Tool Z', $name);
    }

    public function testPluck(): void
    {
        $names = $this->table()->where('category', 'widgets')->pluck('name');

        $this->assertCount(2, $names);
        $this->assertContains('Widget A', $names->toArray());
        $this->assertContains('Widget B', $names->toArray());
    }

    public function testPluckWithKey(): void
    {
        $products = $this->table()->where('category', 'widgets')->pluck('name', 'id');

        $this->assertCount(2, $products);
        foreach ($products as $id => $name) {
            $this->assertIsInt($id);
            $this->assertIsString($name);
        }
    }

    public function testCount(): void
    {
        $count = $this->table()->where('active', true)->count();

        $this->assertSame(4, $count);
    }

    public function testMax(): void
    {
        $max = $this->table()->max('price');

        $this->assertEquals(149.99, $max);
    }

    public function testMin(): void
    {
        $min = $this->table()->min('price');

        $this->assertEquals(19.99, $min);
    }

    public function testSum(): void
    {
        $sum = $this->table()->where('category', 'widgets')->sum('stock');

        $this->assertEquals(150, $sum);
    }

    public function testAvg(): void
    {
        $avg = $this->table()->where('category', 'widgets')->avg('price');

        $this->assertEquals(24.99, $avg);
    }

    public function testExists(): void
    {
        $this->assertTrue($this->table()->where('category', 'widgets')->exists());
        $this->assertFalse($this->table()->where('category', 'nonexistent')->exists());
    }

    public function testDoesntExist(): void
    {
        $this->assertTrue($this->table()->where('category', 'nonexistent')->doesntExist());
        $this->assertFalse($this->table()->where('category', 'widgets')->doesntExist());
    }

    public function testInsert(): void
    {
        $result = $this->table()->insert([
            'name' => 'New Product',
            'category' => 'new',
            'price' => 9.99,
            'stock' => 5,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($result);
        $this->assertSame(6, $this->table()->count());
    }

    public function testInsertGetId(): void
    {
        $id = $this->table()->insertGetId([
            'name' => 'Another Product',
            'category' => 'another',
            'price' => 14.99,
            'stock' => 3,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertIsInt($id);
        $this->assertNotNull($this->table()->find($id));
    }

    public function testUpdate(): void
    {
        $affected = $this->table()->where('category', 'widgets')->update(['stock' => 200]);

        $this->assertSame(2, $affected);

        $products = $this->table()->where('category', 'widgets')->get();
        foreach ($products as $product) {
            $this->assertEquals(200, $product->stock);
        }
    }

    public function testIncrement(): void
    {
        $this->table()->where('name', 'Widget A')->increment('stock', 10);

        $product = $this->table()->where('name', 'Widget A')->first();
        $this->assertEquals(110, $product->stock);
    }

    public function testDecrement(): void
    {
        $this->table()->where('name', 'Widget A')->decrement('stock', 10);

        $product = $this->table()->where('name', 'Widget A')->first();
        $this->assertEquals(90, $product->stock);
    }

    public function testDelete(): void
    {
        $affected = $this->table()->where('active', false)->delete();

        $this->assertSame(1, $affected);
        $this->assertSame(4, $this->table()->count());
    }

    public function testTruncate(): void
    {
        $this->table()->truncate();

        $this->assertSame(0, $this->table()->count());
    }

    public function testChunk(): void
    {
        $processed = 0;

        $this->table()->orderBy('id')->chunk(2, function ($products) use (&$processed) {
            $processed += $products->count();
        });

        $this->assertSame(5, $processed);
    }

    public function testGroupBy(): void
    {
        $categories = $this->table()
            ->select('category', DB::connection($this->driver)->raw('COUNT(*) as count'))
            ->groupBy('category')
            ->get();

        $this->assertCount(3, $categories);
    }

    public function testHaving(): void
    {
        $categories = $this->table()
            ->select('category', DB::connection($this->driver)->raw('SUM(stock) as total_stock'))
            ->groupBy('category')
            ->havingRaw('SUM(stock) > ?', [50])
            ->get();

        $this->assertCount(1, $categories);
        $this->assertSame('widgets', $categories->first()->category);
    }

    public function testDistinct(): void
    {
        $categories = $this->table()->distinct()->pluck('category');

        $this->assertCount(3, $categories);
    }

    public function testWhen(): void
    {
        $filterCategory = 'widgets';

        $products = $this->table()
            ->when($filterCategory, function ($query, $category) {
                return $query->where('category', $category);
            })
            ->get();

        $this->assertCount(2, $products);

        $products = $this->table()
            ->when(null, function ($query, $category) {
                return $query->where('category', $category);
            })
            ->get();

        $this->assertCount(5, $products);
    }

    public function testUnless(): void
    {
        $showAll = false;

        $products = $this->table()
            ->unless($showAll, function ($query) {
                return $query->where('active', true);
            })
            ->get();

        $this->assertCount(4, $products);
    }

    public function testToSql(): void
    {
        $sql = $this->table()->where('category', 'widgets')->toSql();

        $this->assertStringContainsString('select', strtolower($sql));
        $this->assertStringContainsString('where', strtolower($sql));
    }
}
