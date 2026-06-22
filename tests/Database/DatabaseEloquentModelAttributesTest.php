<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentModelAttributesTest;

use ErrorException;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Attributes\Appends;
use Hypervel\Database\Eloquent\Attributes\Connection;
use Hypervel\Database\Eloquent\Attributes\DateFormat;
use Hypervel\Database\Eloquent\Attributes\Fillable;
use Hypervel\Database\Eloquent\Attributes\Guarded;
use Hypervel\Database\Eloquent\Attributes\Hidden;
use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Attributes\Touches;
use Hypervel\Database\Eloquent\Attributes\Unguarded;
use Hypervel\Database\Eloquent\Attributes\Visible;
use Hypervel\Database\Eloquent\Attributes\WithoutIncrementing;
use Hypervel\Database\Eloquent\Attributes\WithoutTimestamps;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Tests\TestCase;
use ReflectionClass;

class DatabaseEloquentModelAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'secondary');

        $db->bootEloquent();
        $db->setAsGlobal();

        Model::clearBootedModels();
    }

    public function testTableAttribute(): void
    {
        $model = new ModelWithTableAttribute;

        $this->assertSame('custom_table_name', $model->getTable());
    }

    public function testTablePropertyTakesPrecedence(): void
    {
        $model = new ModelWithTableAttributeAndProperty;

        $this->assertSame('property_table', $model->getTable());
    }

    public function testChildTableAttributeOverridesInheritedTableProperty(): void
    {
        $model = new ChildModelWithTableAttribute;

        $this->assertSame('child_attr', $model->getTable());
    }

    public function testChildInheritsParentTableAttribute(): void
    {
        $model = new ChildModelWithNoTable;

        $this->assertSame('parent_attr', $model->getTable());
    }

    public function testChildTablePropertyOverridesParentTableAttribute(): void
    {
        $model = new ChildModelWithTableProperty;

        $this->assertSame('child_prop', $model->getTable());
    }

    public function testPrimaryKeyAttribute(): void
    {
        $model = new ModelWithPrimaryKeyAttribute;

        $this->assertSame('custom_id', $model->getKeyName());
    }

    public function testPrimaryKeyPropertyTakesPrecedence(): void
    {
        $model = new ModelWithPrimaryKeyAttributeAndProperty;

        $this->assertSame('property_id', $model->getKeyName());
    }

    public function testPrimaryKeyAttributeWithType(): void
    {
        $model = new ModelWithPrimaryKeyTypeAttribute;

        $this->assertSame('uuid', $model->getKeyName());
        $this->assertSame('string', $model->getKeyType());
    }

    public function testPrimaryKeyAttributeWithIncrementing(): void
    {
        $model = new ModelWithPrimaryKeyIncrementingAttribute;

        $this->assertSame('uuid', $model->getKeyName());
        $this->assertFalse($model->getIncrementing());
    }

    public function testPrimaryKeyAttributeWithAllOptions(): void
    {
        $model = new ModelWithFullPrimaryKeyAttribute;

        $this->assertSame('uuid', $model->getKeyName());
        $this->assertSame('string', $model->getKeyType());
        $this->assertFalse($model->getIncrementing());
    }

    public function testDedicatedWithoutIncrementingAttribute(): void
    {
        $model = new ModelWithDedicatedWithoutIncrementingAttribute;

        $this->assertFalse($model->getIncrementing());
    }

    public function testDedicatedWithoutIncrementingAttributeOverridesTableIncrementing(): void
    {
        $model = new ModelWithWithoutIncrementingAttributeOverride;

        $this->assertFalse($model->getIncrementing());
    }

    public function testTableAttributeIncrementingAppliesToPivotModels(): void
    {
        $model = new PivotWithIncrementing;

        $this->assertTrue($model->getIncrementing());
    }

    public function testConnectionAttribute(): void
    {
        $model = new ModelWithConnectionAttribute;

        $this->assertSame('secondary', $model->getConnectionName());
    }

    public function testConnectionAttributeWithUnitEnum(): void
    {
        $model = new ModelWithUnitEnumConnectionAttribute;

        $this->assertSame('secondary', $model->getConnectionName());
    }

    public function testConnectionAttributeWithBackedEnum(): void
    {
        $model = new ModelWithBackedEnumConnectionAttribute;

        $this->assertSame('secondary', $model->getConnectionName());
    }

    public function testTimestampsAttribute(): void
    {
        $model = new ModelWithTimestampsFalseAttribute;

        $this->assertFalse($model->usesTimestamps());
    }

    public function testWithoutTimestampsAttribute(): void
    {
        $model = new ModelWithoutTimestampsAttribute;

        $this->assertFalse($model->usesTimestamps());
    }

    public function testTimestampsPropertyTakesPrecedence(): void
    {
        $model = new ModelWithTimestampsAttributeAndProperty;

        $this->assertFalse($model->usesTimestamps());
    }

    public function testDateFormatAttribute(): void
    {
        $model = new ModelWithDateFormatAttribute;

        $this->assertSame('U', $model->getDateFormat());
    }

    public function testDedicatedDateFormatAttribute(): void
    {
        $model = new ModelWithDedicatedDateFormatAttribute;

        $this->assertSame('Y-m-d', $model->getDateFormat());
    }

    public function testDedicatedDateFormatAttributeOverridesTableDateFormat(): void
    {
        $model = new ModelWithDateFormatAttributeOverride;

        $this->assertSame('Y-m-d', $model->getDateFormat());
    }

    public function testDedicatedWithoutTimestampsAttribute(): void
    {
        $model = new ModelWithDedicatedWithoutTimestampsAttribute;

        $this->assertFalse($model->usesTimestamps());
    }

    public function testDedicatedWithoutTimestampsAttributeOverridesTableTimestamps(): void
    {
        $model = new ModelWithWithoutTimestampsAttributeOverride;

        $this->assertFalse($model->usesTimestamps());
    }

    public function testFillableAttribute(): void
    {
        $model = new ModelWithFillableAttribute;

        $this->assertSame(['name', 'email'], $model->getFillable());
    }

    public function testFillableAttributeVariadic(): void
    {
        $model = new ModelWithFillableAttributeVariadic;

        $this->assertSame(['name', 'email'], $model->getFillable());
    }

    public function testFillablePropertyMergesWithAttribute(): void
    {
        $model = new ModelWithFillableAttributeAndProperty;

        $this->assertEqualsCanonicalizing(['title', 'name', 'email'], $model->getFillable());
    }

    public function testGuardedAttribute(): void
    {
        $model = new ModelWithGuardedAttribute;

        $this->assertSame(['id', 'secret'], $model->getGuarded());
    }

    public function testGuardedAttributeVariadic(): void
    {
        $model = new ModelWithGuardedAttributeVariadic;

        $this->assertSame(['id', 'secret'], $model->getGuarded());
    }

    public function testGuardedPropertyTakesPrecedence(): void
    {
        $model = new ModelWithGuardedAttributeAndProperty;

        $this->assertSame(['token'], $model->getGuarded());
    }

    public function testUnguardedAttribute(): void
    {
        $model = new ModelWithUnguardedAttribute;

        $this->assertSame([], $model->getGuarded());
        $this->assertFalse($model->isGuarded('anything'));
    }

    public function testGuardedAttributeIsInherited(): void
    {
        $model = new ModelExtendingGuardedParent;

        $this->assertSame(['id', 'secret'], $model->getGuarded());
    }

    public function testHiddenAttribute(): void
    {
        $model = new ModelWithHiddenAttribute;

        $this->assertSame(['password', 'secret'], $model->getHidden());
    }

    public function testHiddenAttributeVariadic(): void
    {
        $model = new ModelWithHiddenAttributeVariadic;

        $this->assertSame(['password', 'secret'], $model->getHidden());
    }

    public function testVisibleAttribute(): void
    {
        $model = new ModelWithVisibleAttribute;

        $this->assertSame(['id', 'name'], $model->getVisible());
    }

    public function testVisibleAttributeVariadic(): void
    {
        $model = new ModelWithVisibleAttributeVariadic;

        $this->assertSame(['id', 'name'], $model->getVisible());
    }

    public function testAppendsAttribute(): void
    {
        $model = new ModelWithAppendsAttribute;

        $this->assertSame(['full_name', 'is_admin'], $model->getAppends());
    }

    public function testAppendsAttributeVariadic(): void
    {
        $model = new ModelWithAppendsAttributeVariadic;

        $this->assertSame(['full_name', 'is_admin'], $model->getAppends());
    }

    public function testTouchesAttribute(): void
    {
        $model = new ModelWithTouchesAttribute;

        $this->assertSame(['post', 'author'], $model->getTouchedRelations());
    }

    public function testTouchesAttributeVariadic(): void
    {
        $model = new ModelWithTouchesAttributeVariadic;

        $this->assertSame(['post', 'author'], $model->getTouchedRelations());
    }

    public function testMergeFillableWorksWithAttribute(): void
    {
        $model = new ModelWithFillableAttribute;

        $this->assertSame(['name', 'email'], $model->getFillable());

        $model->mergeFillable(['phone']);

        $this->assertSame(['name', 'email', 'phone'], $model->getFillable());
    }

    public function testMergeHiddenWorksWithAttribute(): void
    {
        $model = new ModelWithHiddenAttribute;

        $this->assertSame(['password', 'secret'], $model->getHidden());

        $model->mergeHidden(['api_key']);

        $this->assertSame(['password', 'secret', 'api_key'], $model->getHidden());
    }

    public function testMergeFillableWithEmptyArrayIsNoop(): void
    {
        $model = new ModelWithFillableAttribute;
        $original = $model->getFillable();

        $result = $model->mergeFillable([]);

        $this->assertSame($model, $result);
        $this->assertSame($original, $model->getFillable());
    }

    public function testMergeHiddenWithEmptyArrayIsNoop(): void
    {
        $model = new ModelWithHiddenAttribute;
        $original = $model->getHidden();

        $result = $model->mergeHidden([]);

        $this->assertSame($model, $result);
        $this->assertSame($original, $model->getHidden());
    }

    public function testMergeVisibleWithEmptyArrayIsNoop(): void
    {
        $model = new ModelWithVisibleAttribute;
        $original = $model->getVisible();

        $result = $model->mergeVisible([]);

        $this->assertSame($model, $result);
        $this->assertSame($original, $model->getVisible());
    }

    public function testMergeAppendsWithEmptyArrayIsNoop(): void
    {
        $model = new ModelWithAppendsAttribute;
        $original = $model->getAppends();

        $result = $model->mergeAppends([]);

        $this->assertSame($model, $result);
        $this->assertSame($original, $model->getAppends());
    }

    public function testSetFillableOverridesAttribute(): void
    {
        $model = new ModelWithFillableAttribute;

        $this->assertSame(['name', 'email'], $model->getFillable());

        $model->fillable(['only_this']);

        $this->assertSame(['only_this'], $model->getFillable());
    }

    public function testSetHiddenOverridesAttribute(): void
    {
        $model = new ModelWithHiddenAttribute;

        $this->assertSame(['password', 'secret'], $model->getHidden());

        $model->setHidden(['only_this']);

        $this->assertSame(['only_this'], $model->getHidden());
    }

    public function testIsIgnoringTouchWithTimestampsAttribute(): void
    {
        $this->assertTrue(ModelWithoutTimestampsAttribute::isIgnoringTouch());
        $this->assertTrue(ModelWithTimestampsFalseAttribute::isIgnoringTouch());
        $this->assertFalse(ModelWithFillableAttribute::isIgnoringTouch());
    }

    public function testIsIgnoringTouchWithTimestampsAttributeAfterTableAttributeCacheIsWarmed(): void
    {
        new ModelWithTimestampsFalseAttribute;

        $this->assertTrue(ModelWithTimestampsFalseAttribute::isIgnoringTouch());
    }

    public function testVariadicAttributeWithNoArgumentsDoesNotWarn(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $model = new ModelWithEmptyFillableAttribute;

            $this->assertSame([], $model->getFillable());
        } finally {
            restore_error_handler();
        }
    }

    public function testClassAttributesAreInitializedWhenModelIsUnserialized(): void
    {
        $model = (new ReflectionClass(ModelWithTableAttribute::class))->newInstanceWithoutConstructor();

        $unserialized = unserialize(serialize($model));

        $this->assertInstanceOf(ModelWithTableAttribute::class, $unserialized);
        $this->assertSame('custom_table_name', $unserialized->getTable());
    }

    public function testTraitInitializerMergesAppendsWithAttribute(): void
    {
        $model = new ModelWithAppendsAttributeAndTrait;

        $this->assertEqualsCanonicalizing(['full_name', 'is_admin', 'url'], $model->getAppends());
    }

    public function testTraitInitializerMergesHiddenWithAttribute(): void
    {
        $model = new ModelWithHiddenAttributeAndTrait;

        $this->assertEqualsCanonicalizing(['password', 'secret', 'api_token'], $model->getHidden());
    }

    public function testTraitInitializerMergesVisibleWithAttribute(): void
    {
        $model = new ModelWithVisibleAttributeAndTrait;

        $this->assertEqualsCanonicalizing(['id', 'name', 'email'], $model->getVisible());
    }

    public function testTraitInitializerMergesFillableWithAttribute(): void
    {
        $model = new ModelWithFillableAttributeAndTrait;

        $this->assertEqualsCanonicalizing(['name', 'email', 'phone'], $model->getFillable());
    }
}

enum ConnectionUnitEnum
{
    case secondary;
}

enum ConnectionBackedEnum: string
{
    case Secondary = 'secondary';
}

#[Table('custom_table_name')]
class ModelWithTableAttribute extends Model
{
}

#[Table('attribute_table')]
class ModelWithTableAttributeAndProperty extends Model
{
    protected ?string $table = 'property_table';
}

class ParentModelWithTableProperty extends Model
{
    protected ?string $table = 'parent_prop';
}

#[Table(name: 'child_attr')]
class ChildModelWithTableAttribute extends ParentModelWithTableProperty
{
}

#[Table(name: 'parent_attr')]
class ParentModelWithTableAttribute extends Model
{
}

class ChildModelWithNoTable extends ParentModelWithTableAttribute
{
}

class ChildModelWithTableProperty extends ParentModelWithTableAttribute
{
    protected ?string $table = 'child_prop';
}

#[Table(key: 'custom_id')]
class ModelWithPrimaryKeyAttribute extends Model
{
}

#[Table(key: 'attribute_id')]
class ModelWithPrimaryKeyAttributeAndProperty extends Model
{
    protected string $primaryKey = 'property_id';
}

#[Table(key: 'uuid', keyType: 'string')]
class ModelWithPrimaryKeyTypeAttribute extends Model
{
}

#[Table(key: 'uuid', incrementing: false)]
class ModelWithPrimaryKeyIncrementingAttribute extends Model
{
}

#[Table(key: 'uuid', keyType: 'string', incrementing: false)]
class ModelWithFullPrimaryKeyAttribute extends Model
{
}

#[Connection('secondary')]
class ModelWithConnectionAttribute extends Model
{
}

#[Connection(ConnectionUnitEnum::secondary)]
class ModelWithUnitEnumConnectionAttribute extends Model
{
}

#[Connection(ConnectionBackedEnum::Secondary)]
class ModelWithBackedEnumConnectionAttribute extends Model
{
}

#[Table(timestamps: false)]
class ModelWithTimestampsFalseAttribute extends Model
{
}

#[Table(timestamps: false)]
class ModelWithoutTimestampsAttribute extends Model
{
}

#[Table(timestamps: false)]
class ModelWithTimestampsAttributeAndProperty extends Model
{
    public bool $timestamps = false;
}

#[Table(dateFormat: 'U')]
class ModelWithDateFormatAttribute extends Model
{
}

#[Fillable(['name', 'email'])]
class ModelWithFillableAttribute extends Model
{
}

#[Fillable('name', 'email')]
class ModelWithFillableAttributeVariadic extends Model
{
}

#[Fillable(['name', 'email'])]
class ModelWithFillableAttributeAndProperty extends Model
{
    protected array $fillable = ['title'];
}

#[Guarded(['id', 'secret'])]
class ModelWithGuardedAttribute extends Model
{
}

#[Guarded('id', 'secret')]
class ModelWithGuardedAttributeVariadic extends Model
{
}

#[Guarded(['id', 'secret'])]
class ModelWithGuardedAttributeAndProperty extends Model
{
    protected array $guarded = ['token'];
}

#[Guarded(['id', 'secret'])]
class GuardedBaseModel extends Model
{
}

class ModelExtendingGuardedParent extends GuardedBaseModel
{
}

#[Unguarded]
class ModelWithUnguardedAttribute extends Model
{
}

#[Hidden(['password', 'secret'])]
class ModelWithHiddenAttribute extends Model
{
}

#[Hidden('password', 'secret')]
class ModelWithHiddenAttributeVariadic extends Model
{
}

#[Visible(['id', 'name'])]
class ModelWithVisibleAttribute extends Model
{
}

#[Visible('id', 'name')]
class ModelWithVisibleAttributeVariadic extends Model
{
}

#[Appends(['full_name', 'is_admin'])]
class ModelWithAppendsAttribute extends Model
{
}

#[Appends('full_name', 'is_admin')]
class ModelWithAppendsAttributeVariadic extends Model
{
}

#[Touches(['post', 'author'])]
class ModelWithTouchesAttribute extends Model
{
}

#[Touches('post', 'author')]
class ModelWithTouchesAttributeVariadic extends Model
{
}

#[DateFormat('Y-m-d')]
class ModelWithDedicatedDateFormatAttribute extends Model
{
}

#[Table(dateFormat: 'U')]
#[DateFormat('Y-m-d')]
class ModelWithDateFormatAttributeOverride extends Model
{
}

#[WithoutTimestamps]
class ModelWithDedicatedWithoutTimestampsAttribute extends Model
{
}

#[Table(timestamps: true)]
#[WithoutTimestamps]
class ModelWithWithoutTimestampsAttributeOverride extends Model
{
}

#[WithoutIncrementing]
class ModelWithDedicatedWithoutIncrementingAttribute extends Model
{
}

#[Table(incrementing: true)]
#[WithoutIncrementing]
class ModelWithWithoutIncrementingAttributeOverride extends Model
{
}

#[Table(incrementing: true)]
class PivotWithIncrementing extends Pivot
{
}

// Traits for testing trait initializer + Attribute collision

trait AddsUrlAppend
{
    protected function initializeAddsUrlAppend(): void
    {
        $this->mergeAppends(['url']);
    }
}

trait AddsApiTokenHidden
{
    protected function initializeAddsApiTokenHidden(): void
    {
        $this->mergeHidden(['api_token']);
    }
}

trait AddsEmailVisible
{
    protected function initializeAddsEmailVisible(): void
    {
        $this->mergeVisible(['email']);
    }
}

trait AddsPhoneFillable
{
    protected function initializeAddsPhoneFillable(): void
    {
        $this->mergeFillable(['phone']);
    }
}

#[Appends(['full_name', 'is_admin'])]
class ModelWithAppendsAttributeAndTrait extends Model
{
    use AddsUrlAppend;
}

#[Hidden(['password', 'secret'])]
class ModelWithHiddenAttributeAndTrait extends Model
{
    use AddsApiTokenHidden;
}

#[Visible(['id', 'name'])]
class ModelWithVisibleAttributeAndTrait extends Model
{
    use AddsEmailVisible;
}

#[Fillable(['name', 'email'])]
class ModelWithFillableAttributeAndTrait extends Model
{
    use AddsPhoneFillable;
}

#[Fillable]
class ModelWithEmptyFillableAttribute extends Model
{
}
