<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\NestedSet\Database;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\NestedSet\HasNode;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

abstract class NestedSetDatabaseTestCase extends DatabaseTestCase
{
    protected const string FIRST_TENANT = '018f3a2b-0000-7000-8000-000000000001';

    protected const string SECOND_TENANT = '018f3a2b-0000-7000-8000-000000000002';

    /**
     * Create the integer and scoped UUID node tables.
     */
    protected function afterRefreshingDatabase(): void
    {
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
    }

    /**
     * Drop the node tables.
     */
    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropIfExists('nested_set_uuid_nodes');
        Schema::dropIfExists('nested_set_integer_nodes');
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

    public function testUuidTreeSoftDeleteAndRestoreRemainScopeCorrect(): void
    {
        $root = $this->createUuidNode(
            '018f3a2b-0000-7000-8000-000000000601',
            self::FIRST_TENANT,
            'root',
        );
        $child = $this->createUuidNode(
            '018f3a2b-0000-7000-8000-000000000602',
            self::FIRST_TENANT,
            'child',
            $root,
        );
        $otherRoot = $this->createUuidNode(
            '018f3a2b-0000-7000-8000-000000000701',
            self::SECOND_TENANT,
            'other root',
        );

        $root->delete();

        $this->assertNull(UuidNestedSetNode::find($root->getKey()));
        $this->assertNull(UuidNestedSetNode::find($child->getKey()));
        $this->assertNotNull(UuidNestedSetNode::find($otherRoot->getKey()));
        $this->assertFalse(UuidNestedSetNode::scoped([
            'tenant_id' => self::FIRST_TENANT,
        ])->isBroken());
        $this->assertFalse(UuidNestedSetNode::scoped([
            'tenant_id' => self::SECOND_TENANT,
        ])->isBroken());

        $root->restore();

        $this->assertNotNull(UuidNestedSetNode::find($root->getKey()));
        $this->assertNotNull(UuidNestedSetNode::find($child->getKey()));
        $this->assertNotNull(UuidNestedSetNode::find($otherRoot->getKey()));
        $this->assertFalse(UuidNestedSetNode::scoped([
            'tenant_id' => self::FIRST_TENANT,
        ])->isBroken());
        $this->assertFalse(UuidNestedSetNode::scoped([
            'tenant_id' => self::SECOND_TENANT,
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
