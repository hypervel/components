<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\NestedSet\HasNode;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

abstract class NestedSetDatabaseTestCase extends DatabaseTestCase
{
    protected const FIRST_TENANT = '018f3a2b-0000-7000-8000-000000000001';

    protected const SECOND_TENANT = '018f3a2b-0000-7000-8000-000000000002';

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('nested_set_bigint_nodes', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            NestedSet::columns($table);
        });

        Schema::create('nested_set_integer_nodes', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            NestedSet::integerColumns($table);
        });

        Schema::create('nested_set_uuid_nodes', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->softDeletes();
            NestedSet::uuidColumns($table, ['tenant_id']);
        });

        Schema::create('nested_set_ulid_nodes', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            NestedSet::ulidColumns($table);
        });
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropIfExists('nested_set_ulid_nodes');
        Schema::dropIfExists('nested_set_uuid_nodes');
        Schema::dropIfExists('nested_set_integer_nodes');
        Schema::dropIfExists('nested_set_bigint_nodes');
    }

    public function testSchemaUsesMatchingKeyTypesAndExactIndexOrder(): void
    {
        foreach ([
            'nested_set_bigint_nodes',
            'nested_set_integer_nodes',
            'nested_set_uuid_nodes',
            'nested_set_ulid_nodes',
        ] as $table) {
            $this->assertSame(
                Schema::getColumnType($table, 'id'),
                Schema::getColumnType($table, NestedSet::PARENT_ID),
            );
            $this->assertSame(
                match ($this->driver) {
                    'pgsql' => 'int2',
                    'sqlite' => 'integer',
                    default => 'smallint',
                },
                Schema::getColumnType($table, NestedSet::DEPTH),
            );
        }

        $this->assertNestedSetIndexes('nested_set_bigint_nodes');
        $this->assertNestedSetIndexes('nested_set_integer_nodes');
        $this->assertNestedSetIndexes('nested_set_uuid_nodes', ['tenant_id']);
        $this->assertNestedSetIndexes('nested_set_ulid_nodes');
    }

    public function testSchemaDropIsSymmetric(): void
    {
        Schema::table('nested_set_uuid_nodes', static function (Blueprint $table): void {
            NestedSet::dropColumns($table, ['tenant_id']);
        });

        $this->assertSame(
            ['id', 'tenant_id', 'name', 'deleted_at'],
            Schema::getColumnListing('nested_set_uuid_nodes'),
        );
        $this->assertSame([], array_values(array_filter(
            Schema::getIndexes('nested_set_uuid_nodes'),
            static fn (array $index): bool => ! $index['primary'],
        )));
    }

    public function testIntegerAndUuidTreesMaintainDepthAndTenantIsolation(): void
    {
        $integerRoot = IntegerNestedSetNode::create(['name' => 'integer root']);
        $integerChild = new IntegerNestedSetNode(['name' => 'integer child']);
        $integerChild->appendToNode($integerRoot)->save();

        $firstRoot = $this->createUuidNode(
            '018f3a2b-0000-7000-8000-000000000101',
            self::FIRST_TENANT,
            'first root',
        );
        $firstChild = $this->createUuidNode(
            '018f3a2b-0000-7000-8000-000000000102',
            self::FIRST_TENANT,
            'first child',
            $firstRoot,
        );
        $secondRoot = $this->createUuidNode(
            '018f3a2b-0000-7000-8000-000000000201',
            self::SECOND_TENANT,
            'second root',
        );

        $this->assertSame(0, $integerRoot->getDepth());
        $this->assertSame(1, $integerChild->getDepth());
        $this->assertSame(0, $firstRoot->getDepth());
        $this->assertSame(1, $firstChild->getDepth());
        $this->assertSame([1, 4], $firstRoot->getBounds());
        $this->assertSame([1, 2], $secondRoot->getBounds());

        $this->assertSame(
            [$firstChild->getKey()],
            $firstRoot->descendants()->pluck('id')->all(),
        );
        $this->assertSame(
            [$firstRoot->getKey()],
            $firstChild->ancestors()->pluck('id')->all(),
        );
        $this->assertFalse(UuidNestedSetNode::scoped([
            'tenant_id' => self::FIRST_TENANT,
        ])->isBroken());
        $this->assertFalse(UuidNestedSetNode::scoped([
            'tenant_id' => self::SECOND_TENANT,
        ])->isBroken());
    }

    public function testMovingAnExistingSubtreeUpdatesPersistedDepth(): void
    {
        $firstRoot = IntegerNestedSetNode::create(['name' => 'first root']);
        $parent = new IntegerNestedSetNode(['name' => 'parent']);
        $parent->appendToNode($firstRoot)->save();
        $moved = new IntegerNestedSetNode(['name' => 'moved']);
        $moved->appendToNode($parent)->save();
        $descendant = new IntegerNestedSetNode(['name' => 'descendant']);
        $descendant->appendToNode($moved)->save();
        $secondRoot = IntegerNestedSetNode::create(['name' => 'second root']);

        $moved->appendToNode($secondRoot)->save();

        $this->assertSame(1, IntegerNestedSetNode::findOrFail($moved->getKey())->getDepth());
        $this->assertSame(2, IntegerNestedSetNode::findOrFail($descendant->getKey())->getDepth());
        $this->assertFalse(IntegerNestedSetNode::query()->isBroken());
    }

    public function testUuidTreeRepairAndSoftDeleteRemainScopeCorrect(): void
    {
        $root = $this->createUuidNode(
            '018f3a2b-0000-7000-8000-000000000301',
            self::FIRST_TENANT,
            'root',
        );
        $child = $this->createUuidNode(
            '018f3a2b-0000-7000-8000-000000000302',
            self::FIRST_TENANT,
            'child',
            $root,
        );
        $otherRoot = $this->createUuidNode(
            '018f3a2b-0000-7000-8000-000000000401',
            self::SECOND_TENANT,
            'other root',
        );

        DB::table('nested_set_uuid_nodes')
            ->where('id', $child->getKey())
            ->update([
                NestedSet::LFT => 1,
                NestedSet::PARENT_ID => '018f3a2b-0000-7000-8000-000000000999',
                NestedSet::DEPTH => 0,
            ]);

        UuidNestedSetNode::scoped([
            'tenant_id' => self::FIRST_TENANT,
        ])->fixTree();

        $child->refresh();
        $otherRoot->refresh();

        $this->assertNull($child->getParentId());
        $this->assertSame(0, $child->getDepth());
        $this->assertSame([1, 2], $otherRoot->getBounds());
        $this->assertFalse(UuidNestedSetNode::scoped([
            'tenant_id' => self::FIRST_TENANT,
        ])->isBroken());

        $root->delete();
        $root->restore();

        $this->assertFalse(UuidNestedSetNode::scoped([
            'tenant_id' => self::FIRST_TENANT,
        ])->isBroken());
    }

    protected function createUuidNode(
        string $id,
        string $tenantId,
        string $name,
        ?UuidNestedSetNode $parent = null,
    ): UuidNestedSetNode {
        $node = new UuidNestedSetNode([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $name,
        ]);

        if ($parent !== null) {
            $node->appendToNode($parent);
        }

        $node->save();

        return $node;
    }

    protected function assertNestedSetIndexes(string $table, array $scopes = []): void
    {
        $indexColumns = array_map(
            static fn (array $index): array => $index['columns'],
            Schema::getIndexes($table),
        );

        $this->assertContains([...$scopes, NestedSet::RGT], $indexColumns);
        $this->assertContains([...$scopes, NestedSet::LFT], $indexColumns);
        $this->assertContains([...$scopes, NestedSet::PARENT_ID, NestedSet::LFT], $indexColumns);
    }
}

class IntegerNestedSetNode extends Model
{
    use HasNode;

    public bool $timestamps = false;

    protected ?string $table = 'nested_set_integer_nodes';

    protected array $fillable = ['name'];
}

class UuidNestedSetNode extends Model
{
    use HasNode;
    use SoftDeletes;

    public bool $incrementing = false;

    public bool $timestamps = false;

    protected string $keyType = 'string';

    protected ?string $table = 'nested_set_uuid_nodes';

    protected array $fillable = ['id', 'tenant_id', 'name'];

    protected function getScopeAttributes(): array
    {
        return ['tenant_id'];
    }
}
