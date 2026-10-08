<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\NestedSet\Fixtures\Models\MenuItem;

class ScopedNodeTest extends ScopedNodeTestBase
{
    protected string $menuItem = MenuItem::class;

    /**
     * Seed the fixture trees inside the test coroutine and transaction.
     */
    protected function afterRefreshingDatabase(): void
    {
        parent::afterRefreshingDatabase();

        // Reset the Postgres sequence after inserting explicit keys.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getTablePrefix() . 'menu_items';

            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), (SELECT MAX(id) FROM {$table}))");
        }
    }

    /**
     * Get the fixture migration path.
     */
    protected function getMigrationPath(): string
    {
        return __DIR__ . '/Fixtures/migrations';
    }

    /**
     * Get the key of a seeded fixture menu item.
     */
    protected function key(int $number): int
    {
        return $number;
    }

    public function testPresentNullScopeCanBePersisted(): void
    {
        $node = new NullableMenuItem(['menu_id' => null, 'title' => 'null scope']);

        $this->assertTrue($node->save());
        $this->assertNull($node->menu_id);
        $this->assertSame(0, $node->getDepth());
    }

    public function testRestoringPartiallySelectedScopedNodeHydratesItsTreeIdentity(): void
    {
        SoftDeletingMenuItem::findOrFail(2)->delete();

        // The other menu's child lies within the deleted node's bounds but stays active.
        $this->assertNull(SoftDeletingMenuItem::find(5));
        $this->assertNotNull(SoftDeletingMenuItem::find(6));

        $node = SoftDeletingMenuItem::withTrashed()
            ->select(['id', 'deleted_at'])
            ->findOrFail(2);

        $node->restore();

        $this->assertNotNull(SoftDeletingMenuItem::find(2));
        $this->assertNotNull(SoftDeletingMenuItem::find(5));
        $this->assertNotNull(SoftDeletingMenuItem::find(4));
        $this->assertNotNull(SoftDeletingMenuItem::find(6));
        $this->assertTreeNotBroken(1);
        $this->assertTreeNotBroken(2);
    }
}

class NullableMenuItem extends MenuItem
{
    protected ?string $table = 'nullable_menu_items';
}

class SoftDeletingMenuItem extends MenuItem
{
    use SoftDeletes;

    protected ?string $table = 'menu_items';
}
