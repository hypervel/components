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
use Hypervel\Database\Eloquent\Attributes\Refreshes;
use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Attributes\Touches;
use Hypervel\Database\Eloquent\Attributes\Unguarded;
use Hypervel\Database\Eloquent\Attributes\Visible;
use Hypervel\Database\Eloquent\Attributes\WithoutIncrementing;
use Hypervel\Database\Eloquent\Attributes\WithoutTimestamps;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionClass;
use UnitEnum;

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

    public function testGrandchildKeepsTableAttributeOverride(): void
    {
        $this->assertSame('child_attr', (new GrandchildModelWithTableAttribute)->getTable());
    }

    public function testTableAttributeWithoutNamePreservesInheritedTable(): void
    {
        $model = new ChildModelWithNamelessTableAttribute;

        $this->assertSame('parent_prop', $model->getTable());
        $this->assertFalse($model->usesTimestamps());
    }

    public function testTableAttributeIsResolvedFromATrait(): void
    {
        $model = new ModelWithTableAttributeTrait;

        $this->assertSame('trait_table', $model->getTable());
    }

    public function testChildInheritsTableAttributeFromAParentTrait(): void
    {
        $model = new ChildModelWithParentTableAttributeTrait;

        $this->assertSame('parent_trait_table', $model->getTable());
    }

    public function testClassTableAttributeTakesPrecedenceOverTraitAttribute(): void
    {
        $model = new ModelWithClassAndTraitTableAttributes;

        $this->assertSame('class_table', $model->getTable());
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

    public function testPrimaryKeyAttributeCanDisablePrimaryKey(): void
    {
        $model = new ModelWithoutPrimaryKeyAttribute;

        $this->assertNull($model->getKeyName());
    }

    public function testPrimaryKeyCanBeDisabledByPropertyOrSetter(): void
    {
        $model = new class extends Model {
            protected ?string $primaryKey = null;
        };

        $this->assertNull($model->getKey());
        $this->assertSame([], $model->getCasts());

        $model->setKeyName('id');
        $this->assertSame(['id' => 'int'], $model->getCasts());

        $model->setKeyName(null);
        $this->assertNull($model->getKeyName());
        $this->assertSame([], $model->getCasts());
    }

    public function testIncrementingUsesTheOverriddenKeyName(): void
    {
        $keyless = new class extends Model {
            /**
             * Get the primary key for the model.
             */
            public function getKeyName(): ?string
            {
                return null;
            }
        };

        $this->assertFalse($keyless->getIncrementing());
        $this->assertSame([], $keyless->getCasts());

        $keyed = new class extends Model {
            protected ?string $primaryKey = null;

            /**
             * Get the primary key for the model.
             */
            public function getKeyName(): string
            {
                return 'row_id';
            }
        };

        $this->assertTrue($keyed->getIncrementing());
        $this->assertSame(['row_id' => 'int'], $keyed->getCasts());
    }

    public function testKeylessModelsCanBeInsertedAndRetrieved(): void
    {
        DB::schema()->create('keyless_records', function (Blueprint $table): void {
            $table->string('name');
        });

        $model = new ModelWithoutPrimaryKeyAttribute;
        $model->setTable('keyless_records');
        $model->timestamps = false;
        $model->name = 'Taylor';

        $this->assertTrue($model->save());
        $this->assertTrue($model->exists);
        $this->assertNull($model->getKey());
        $this->assertSame(['name' => 'Taylor'], $model->getAttributes());
        $this->assertSame(['name' => 'Taylor'], $model->newQuery()->first()->toArray());

        $another = $model->replicate();
        $another->setTable('keyless_records');
        $another->timestamps = false;
        $another->name = 'Abigail';

        $this->assertTrue($another->saveOrIgnore());
        $this->assertSame(['Taylor', 'Abigail'], $model->newQuery()->pluck('name')->all());
    }

    #[TestWith(['save'])]
    #[TestWith(['refresh'])]
    #[TestWith(['delete'])]
    public function testKeylessModelsRejectOperationsRequiringAnIdentity(string $operation): void
    {
        $model = new ModelWithoutPrimaryKeyAttribute;
        $model->timestamps = false;
        $model->exists = true;
        $model->name = 'Taylor';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No primary key defined on model.');

        $model->{$operation}();
    }

    public function testKeylessModelsCanSupplyTheirOwnSaveKeys(): void
    {
        DB::schema()->create('keyless_records', function (Blueprint $table): void {
            $table->string('tenant');
            $table->string('name');
            $table->integer('visits');
        });

        DB::table('keyless_records')->insert([
            ['tenant' => 'first', 'name' => 'Taylor', 'visits' => 1],
            ['tenant' => 'second', 'name' => 'Taylor', 'visits' => 1],
        ]);

        $model = new class extends ModelWithoutPrimaryKeyAttribute {
            protected ?string $table = 'keyless_records';

            public bool $timestamps = false;

            /**
             * Set the keys for a save update query.
             */
            protected function setKeysForSaveQuery(Builder $query): Builder
            {
                return $query->where('tenant', $this->tenant)->where('name', $this->name);
            }
        };

        $record = $model->newQuery()->where('tenant', 'first')->first();
        $record->visits = 2;

        $this->assertTrue($record->save());
        $this->assertSame(['first' => 2, 'second' => 1], DB::table('keyless_records')->pluck('visits', 'tenant')->all());
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

    public function testConnectionAttributeWithIntegerBackedEnum(): void
    {
        $model = new ModelWithIntegerBackedEnumConnectionAttribute;

        $this->assertSame('0', $model->getConnectionName());
    }

    public function testChildConnectionAttributeOverridesInheritedConnectionProperty(): void
    {
        $model = new ChildModelWithConnectionAttribute;

        $this->assertSame('secondary', $model->getConnectionName());
    }

    public function testChildInheritsParentConnectionAttribute(): void
    {
        $model = new ChildModelWithNoConnection;

        $this->assertSame('secondary', $model->getConnectionName());
    }

    public function testGrandchildKeepsConnectionAttributeOverride(): void
    {
        $this->assertSame('secondary', (new GrandchildModelWithConnectionAttribute)->getConnectionName());
    }

    public function testInheritedLocationAttributesPreserveValuesAssignedDuringConstruction(): void
    {
        $inherited = new class extends ModelWithLocationAttributes {
            use SetsRuntimeModelLocation;
        };

        $this->assertSame('runtime_table', $inherited->getTable());
        $this->assertSame('runtime_connection', $inherited->getConnectionName());

        $grandchild = new class extends ModelWithLocationAttributeOverrides {
            /**
             * Set runtime configuration before initializing the model.
             */
            public function __construct(array $attributes = [])
            {
                $this->setTable('runtime_table');
                $this->setConnection('runtime_connection');

                parent::__construct($attributes);
            }
        };

        $this->assertSame('runtime_table', $grandchild->getTable());
        $this->assertSame('runtime_connection', $grandchild->getConnectionName());

        $direct = new #[Table('attribute_table')] #[Connection('secondary')] class extends Model {
            use SetsRuntimeModelLocation;
        };

        $this->assertSame('attribute_table', $direct->getTable());
        $this->assertSame('secondary', $direct->getConnectionName());
    }

    public function testChildConnectionPropertyOverridesParentConnectionAttribute(): void
    {
        $model = new ChildModelWithConnectionProperty;

        $this->assertSame('default', $model->getConnectionName());
    }

    public function testLocationAttributesInitializePropertiesWithoutDefaults(): void
    {
        $model = new #[Table('attribute_table')] #[Connection('secondary')] class extends Model {
            protected ?string $table;

            protected UnitEnum|string|null $connection;
        };

        $this->assertSame('attribute_table', $model->getTable());
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
        $this->assertTrue($model::isIgnoringTouch());
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
        $this->assertTrue($model::isIgnoringTouch());
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

    public function testGuardedAttributeOnPivot(): void
    {
        $model = new PivotWithGuardedAttribute;

        $this->assertSame(['id', 'secret'], $model->getGuarded());
    }

    public function testGuardedAttributeOnMorphPivot(): void
    {
        $model = new MorphPivotWithGuardedAttribute;

        $this->assertSame(['id', 'secret'], $model->getGuarded());
    }

    public function testUnguardedAttributeOnPivot(): void
    {
        $model = new PivotWithUnguardedAttribute;

        $this->assertSame([], $model->getGuarded());
        $this->assertFalse($model->isGuarded('anything'));
    }

    public function testGuardedPropertyTakesPrecedenceOnPivot(): void
    {
        $model = new PivotWithGuardedAttributeAndProperty;

        $this->assertSame(['token'], $model->getGuarded());
    }

    public function testPivotWithoutGuardedAttributeStaysUnguarded(): void
    {
        $model = new PivotWithoutGuardedAttribute;

        $this->assertSame([], $model->getGuarded());
        $this->assertFalse($model->isGuarded('anything'));
    }

    public function testEmptyGuardedPropertyIsNotTreatedAsUnset(): void
    {
        $this->assertSame([], (new ModelWithEmptyGuardedProperty)->getGuarded());
        $this->assertSame([], (new ModelWithEmptyGuardedPropertyAndAttribute)->getGuarded());
    }

    public function testChildGuardedPropertyOverridesInheritedUnguardedAttribute(): void
    {
        $model = new ModelWithGuardedPropertyExtendingUnguardedParent;
        $anonymousModel = new class extends UnguardedBaseModel {
            protected array $guarded = ['*'];
        };

        $this->assertSame(['*'], $model->getGuarded());
        $this->assertSame(['*'], $anonymousModel->getGuarded());
    }

    public function testChildUnguardedPropertyOverridesInheritedGuardedAttribute(): void
    {
        $model = new ModelWithUnguardedPropertyExtendingGuardedParent;

        $this->assertSame([], $model->getGuarded());
    }

    public function testChildGuardedAttributeOverridesInheritedUnguardedAttribute(): void
    {
        $model = new ModelWithGuardedAttributeExtendingUnguardedParent;

        $this->assertSame(['*'], $model->getGuarded());
        $this->assertSame(['*'], (new ModelExtendingGuardedAttributeChild)->getGuarded());
    }

    public function testChildUnguardedAttributeOverridesInheritedGuardedConfiguration(): void
    {
        $this->assertSame([], (new ModelWithUnguardedAttributeExtendingGuardedParent)->getGuarded());
        $this->assertSame([], (new ModelWithUnguardedAttributeExtendingGuardedPropertyParent)->getGuarded());
    }

    public function testClassGuardConfigurationOverridesTraitConfiguration(): void
    {
        $this->assertSame(['*'], (new ModelWithGuardedClassAndUnguardedTrait)->getGuarded());
        $this->assertSame([], (new ModelWithUnguardedClassAndGuardedTrait)->getGuarded());
    }

    public function testGuardedWinsAcrossContradictoryTraitConfiguration(): void
    {
        $this->assertSame(['*'], (new ModelWithContradictoryGuardTraits)->getGuarded());
    }

    public function testGuardedWinsWhenClassDeclaresContradictoryGuardAttributes(): void
    {
        $this->assertSame(['*'], (new ModelWithContradictoryGuardAttributes)->getGuarded());
    }

    public function testGuardMetadataDoesNotReplaceConfigurationSetBeforeParentConstruction(): void
    {
        $this->assertSame(['runtime'], (new ModelWithPreParentGuardConfiguration)->getGuarded());
    }

    public function testGuardAndTableMetadataCachesAreUsedAndCleared(): void
    {
        new ModelWithUnguardedAttribute;
        new ChildModelWithTableAttribute;

        $reflection = new ReflectionClass(Model::class);
        $guardConfigurationsProperty = $reflection->getProperty('guardConfigurations');
        $classAttributeOverridesProperty = $reflection->getProperty('classAttributeOverrides');
        $classPropertyDeclarersProperty = $reflection->getProperty('classPropertyDeclarers');

        $guardConfigurations = $guardConfigurationsProperty->getValue();
        $classAttributeOverrides = $classAttributeOverridesProperty->getValue();
        $classPropertyDeclarers = $classPropertyDeclarersProperty->getValue();

        $guardConfigurations[ModelWithUnguardedAttribute::class] = [
            'guarded' => ['cached'],
            'propertyDefault' => ['*'],
        ];
        $cacheKey = ChildModelWithTableAttribute::class . '@' . Table::class . '@table';
        $classAttributeOverrides[$cacheKey] = false;

        $guardConfigurationsProperty->setValue(null, $guardConfigurations);
        $classAttributeOverridesProperty->setValue(null, $classAttributeOverrides);

        $this->assertSame(['cached'], (new ModelWithUnguardedAttribute)->getGuarded());
        $this->assertSame('parent_prop', (new ChildModelWithTableAttribute)->getTable());

        unset($classAttributeOverrides[$cacheKey]);
        $classPropertyDeclarers[ChildModelWithTableAttribute::class . '@table'] = ChildModelWithTableAttribute::class;
        $classAttributeOverridesProperty->setValue(null, $classAttributeOverrides);
        $classPropertyDeclarersProperty->setValue(null, $classPropertyDeclarers);

        $this->assertSame('parent_prop', (new ChildModelWithTableAttribute)->getTable());

        Model::clearBootedModels();

        $this->assertSame([], $guardConfigurationsProperty->getValue());
        $this->assertSame([], $classAttributeOverridesProperty->getValue());
        $this->assertSame([], $classPropertyDeclarersProperty->getValue());
        $this->assertSame([], (new ModelWithUnguardedAttribute)->getGuarded());
        $this->assertSame('child_attr', (new ChildModelWithTableAttribute)->getTable());
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

    public function testRefreshesAttribute(): void
    {
        $model = new ModelWithRefreshesAttribute;

        $this->assertSame(['name', 'slug'], $model->getRefreshes());
    }

    public function testRefreshesAttributeVariadic(): void
    {
        $model = new ModelWithRefreshesAttributeVariadic;

        $this->assertSame(['name', 'slug'], $model->getRefreshes());
    }

    public function testRefreshesPropertyTakesPrecedence(): void
    {
        $model = new ModelWithRefreshesAttributeAndProperty;

        $this->assertSame(['email'], $model->getRefreshes());
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

    public function testIsIgnoringTouchAfterModelIsConstructed(): void
    {
        new ModelWithTableAndTimestampsFalseAttribute;

        $this->assertTrue(ModelWithTableAndTimestampsFalseAttribute::isIgnoringTouch());
    }

    public function testTableAndTimestampsAttributesApplyAfterIsIgnoringTouch(): void
    {
        ModelWithTableAndTimestampsFalseAttribute::isIgnoringTouch();

        $model = new ModelWithTableAndTimestampsFalseAttribute;

        $this->assertSame('collision_table', $model->getTable());
        $this->assertFalse($model->usesTimestamps());
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

    #[DataProvider('namedAttributeArguments')]
    public function testVariadicAttributeAcceptsANamedArgument(string $model, string $getter, array $expected): void
    {
        $this->assertSame($expected, (new $model)->{$getter}());
    }

    /**
     * Supply models whose attributes pass their list as a named argument.
     */
    public static function namedAttributeArguments(): array
    {
        return [
            'fillable' => [ModelWithNamedFillableAttribute::class, 'getFillable', ['name', 'email']],
            'guarded' => [ModelWithNamedGuardedAttribute::class, 'getGuarded', ['id', 'secret']],
            'hidden' => [ModelWithNamedHiddenAttribute::class, 'getHidden', ['password', 'secret']],
            'visible' => [ModelWithNamedVisibleAttribute::class, 'getVisible', ['id', 'name']],
            'appends' => [ModelWithNamedAppendsAttribute::class, 'getAppends', ['full_name', 'is_admin']],
            'touches' => [ModelWithNamedTouchesAttribute::class, 'getTouchedRelations', ['post', 'author']],
            'refreshes' => [ModelWithNamedRefreshesAttribute::class, 'getRefreshes', ['name', 'email']],
        ];
    }

    public function testClassAttributesAreInitializedWhenModelIsUnserialized(): void
    {
        $model = (new ReflectionClass(ModelWithTableAttribute::class))->newInstanceWithoutConstructor();

        $unserialized = unserialize(serialize($model));

        $this->assertInstanceOf(ModelWithTableAttribute::class, $unserialized);
        $this->assertSame('custom_table_name', $unserialized->getTable());
    }

    public function testRuntimeModelConfigurationSurvivesSerialization(): void
    {
        SerializableModelWithAttributes::$initializerRuns = 0;

        $model = new SerializableModelWithAttributes;
        $model->guard(['*']);
        $model->fillable(['runtime_fillable']);
        $model->setHidden(['runtime_hidden']);
        $model->setVisible(['runtime_visible']);
        $model->setAppends(['runtime_append']);
        $model->mergeCasts(['payload' => 'integer']);
        $model->setRawAttributes(['payload' => '42']);
        $model->replaceTouchedRelations([]);
        $model->timestamps = true;
        $model->setTable('runtime_table');
        $model->setConnection('default');
        $model->setKeyName('id');
        $model->setKeyType('int');
        $model->setIncrementing(true);
        $model->setDateFormat('Y-m-d');

        $unserialized = unserialize(serialize($model));

        $this->assertInstanceOf(SerializableModelWithAttributes::class, $unserialized);
        $this->assertSame(['*'], $unserialized->getGuarded());
        $this->assertSame(['runtime_fillable'], $unserialized->getFillable());
        $this->assertSame(['runtime_hidden'], $unserialized->getHidden());
        $this->assertSame(['runtime_visible'], $unserialized->getVisible());
        $this->assertSame(['runtime_append'], $unserialized->getAppends());
        $this->assertSame('integer', $unserialized->getCasts()['payload']);
        $this->assertSame(42, $unserialized->getAttribute('payload'));
        $this->assertSame([], $unserialized->getTouchedRelations());
        $this->assertTrue($unserialized->usesTimestamps());
        $this->assertSame('runtime_table', $unserialized->getTable());
        $this->assertSame('default', $unserialized->getConnectionName());
        $this->assertSame('id', $unserialized->getKeyName());
        $this->assertSame('int', $unserialized->getKeyType());
        $this->assertTrue($unserialized->getIncrementing());
        $this->assertSame('Y-m-d', $unserialized->getDateFormat());
        $this->assertSame(2, SerializableModelWithAttributes::$initializerRuns);
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

    public function testTraitWithAttributeAppliesToClass(): void
    {
        $model = new ModelUsingWithoutIncrementingTraitWithAttribute;

        $this->assertFalse($model->getIncrementing());
    }

    public function testClassAttributeTakesPrecedenceOverTrait(): void
    {
        $model = new ModelOverridingTraitConnectionAttribute;

        $this->assertSame('primary', $model->getConnectionName());
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

enum ConnectionIntegerBackedEnum: int
{
    case Zero = 0;
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

class GrandchildModelWithTableAttribute extends ChildModelWithTableAttribute
{
}

#[Table(timestamps: false)]
class ChildModelWithNamelessTableAttribute extends ParentModelWithTableProperty
{
}

#[Table(name: 'parent_attr')]
class ParentModelWithTableAttribute extends Model
{
}

class ChildModelWithNoTable extends ParentModelWithTableAttribute
{
}

#[Table(name: 'trait_table')]
trait ModelTableAttributeTrait
{
}

class ModelWithTableAttributeTrait extends Model
{
    use ModelTableAttributeTrait;
}

#[Table(name: 'parent_trait_table')]
trait ParentModelTableAttributeTrait
{
}

class ParentModelWithTableAttributeTrait extends Model
{
    use ParentModelTableAttributeTrait;
}

class ChildModelWithParentTableAttributeTrait extends ParentModelWithTableAttributeTrait
{
}

#[Table(name: 'class_table')]
class ModelWithClassAndTraitTableAttributes extends Model
{
    use ModelTableAttributeTrait;
}

class ChildModelWithTableProperty extends ParentModelWithTableAttribute
{
    protected ?string $table = 'child_prop';
}

#[Table(key: 'custom_id')]
class ModelWithPrimaryKeyAttribute extends Model
{
}

#[Table(key: false)]
class ModelWithoutPrimaryKeyAttribute extends Model
{
}

#[Table(key: 'attribute_id')]
class ModelWithPrimaryKeyAttributeAndProperty extends Model
{
    protected ?string $primaryKey = 'property_id';
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

#[Connection(ConnectionIntegerBackedEnum::Zero)]
class ModelWithIntegerBackedEnumConnectionAttribute extends Model
{
}

class ParentModelWithConnectionProperty extends Model
{
    protected UnitEnum|string|null $connection = 'default';
}

#[Connection('secondary')]
class ChildModelWithConnectionAttribute extends ParentModelWithConnectionProperty
{
}

class GrandchildModelWithConnectionAttribute extends ChildModelWithConnectionAttribute
{
}

#[Connection('secondary')]
class ParentModelWithConnectionAttribute extends Model
{
}

class ChildModelWithNoConnection extends ParentModelWithConnectionAttribute
{
}

class ChildModelWithConnectionProperty extends ParentModelWithConnectionAttribute
{
    protected UnitEnum|string|null $connection = 'default';
}

trait SetsRuntimeModelLocation
{
    /**
     * Configure the model's table and connection during initialization.
     */
    protected function initializeSetsRuntimeModelLocation(): void
    {
        $this->setTable('runtime_table');
        $this->setConnection('runtime_connection');
    }
}

#[Table('attribute_table')]
#[Connection('secondary')]
class ModelWithLocationAttributes extends Model
{
}

class ModelWithLocationProperties extends Model
{
    protected ?string $table = 'parent_table';

    protected UnitEnum|string|null $connection = 'default';
}

#[Table('attribute_table')]
#[Connection('secondary')]
class ModelWithLocationAttributeOverrides extends ModelWithLocationProperties
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

#[Table(timestamps: true)]
class ModelWithTimestampsAttributeAndProperty extends Model
{
    public bool $timestamps = false;
}

#[Table(name: 'collision_table', timestamps: false)]
class ModelWithTableAndTimestampsFalseAttribute extends Model
{
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

class ModelWithEmptyGuardedProperty extends Model
{
    protected array $guarded = [];
}

#[Guarded(['id', 'secret'])]
class ModelWithEmptyGuardedPropertyAndAttribute extends Model
{
    protected array $guarded = [];
}

#[Guarded(['id', 'secret'])]
class PivotWithGuardedAttribute extends Pivot
{
}

#[Guarded(['id', 'secret'])]
class MorphPivotWithGuardedAttribute extends MorphPivot
{
}

#[Unguarded]
class PivotWithUnguardedAttribute extends Pivot
{
}

#[Guarded(['id', 'secret'])]
class PivotWithGuardedAttributeAndProperty extends Pivot
{
    protected array $guarded = ['token'];
}

class PivotWithoutGuardedAttribute extends Pivot
{
}

#[Unguarded]
class UnguardedBaseModel extends Model
{
}

class ModelWithGuardedPropertyExtendingUnguardedParent extends UnguardedBaseModel
{
    protected array $guarded = ['*'];
}

class ModelWithUnguardedPropertyExtendingGuardedParent extends GuardedBaseModel
{
    protected array $guarded = [];
}

#[Guarded('*')]
class ModelWithGuardedAttributeExtendingUnguardedParent extends UnguardedBaseModel
{
}

class ModelExtendingGuardedAttributeChild extends ModelWithGuardedAttributeExtendingUnguardedParent
{
}

#[Unguarded]
class ModelWithUnguardedAttributeExtendingGuardedParent extends GuardedBaseModel
{
}

class GuardedPropertyBaseModel extends Model
{
    protected array $guarded = ['secret'];
}

#[Unguarded]
class ModelWithUnguardedAttributeExtendingGuardedPropertyParent extends GuardedPropertyBaseModel
{
}

#[Unguarded]
trait UnguardedModelTrait
{
}

#[Guarded('*')]
trait GuardedModelTrait
{
}

class ModelWithContradictoryGuardTraits extends Model
{
    use GuardedModelTrait;
    use UnguardedModelTrait;
}

#[Guarded('*')]
class ModelWithGuardedClassAndUnguardedTrait extends Model
{
    use UnguardedModelTrait;
}

#[Unguarded]
class ModelWithUnguardedClassAndGuardedTrait extends Model
{
    use GuardedModelTrait;
}

#[Guarded('*')]
#[Unguarded]
class ModelWithContradictoryGuardAttributes extends Model
{
}

#[Unguarded]
class ModelWithPreParentGuardConfiguration extends Model
{
    /**
     * Create a new model instance.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->guard(['runtime']);

        parent::__construct($attributes);
    }
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

#[Refreshes(['name', 'slug'])]
class ModelWithRefreshesAttribute extends Model
{
    /**
     * Get the attributes refreshed after writes.
     */
    public function getRefreshes(): array
    {
        return $this->refreshes;
    }
}

#[Refreshes('name', 'slug')]
class ModelWithRefreshesAttributeVariadic extends ModelWithRefreshesAttribute
{
}

#[Refreshes(['name', 'slug'])]
class ModelWithRefreshesAttributeAndProperty extends ModelWithRefreshesAttribute
{
    protected array $refreshes = ['email'];
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
    /**
     * Append the URL attribute.
     */
    protected function initializeAddsUrlAppend(): void
    {
        $this->mergeAppends(['url']);
    }
}

trait AddsApiTokenHidden
{
    /**
     * Hide the API token attribute.
     */
    protected function initializeAddsApiTokenHidden(): void
    {
        $this->mergeHidden(['api_token']);
    }
}

trait AddsEmailVisible
{
    /**
     * Make the email attribute visible.
     */
    protected function initializeAddsEmailVisible(): void
    {
        $this->mergeVisible(['email']);
    }
}

trait AddsPhoneFillable
{
    /**
     * Make the phone attribute fillable.
     */
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

#[WithoutIncrementing]
trait TraitUsingWithoutIncrementingAttribute
{
}

class ModelUsingWithoutIncrementingTraitWithAttribute extends Model
{
    use TraitUsingWithoutIncrementingAttribute;
}

#[Connection('secondary')]
trait TraitUsingConnectionAttribute
{
}

#[Connection('primary')]
class ModelOverridingTraitConnectionAttribute extends Model
{
    use TraitUsingConnectionAttribute;
}

#[Fillable]
class ModelWithEmptyFillableAttribute extends Model
{
}

#[Fillable(columns: ['name', 'email'])]
class ModelWithNamedFillableAttribute extends Model
{
}

#[Guarded(columns: ['id', 'secret'])]
class ModelWithNamedGuardedAttribute extends Model
{
}

#[Hidden(columns: ['password', 'secret'])]
class ModelWithNamedHiddenAttribute extends Model
{
}

#[Visible(columns: ['id', 'name'])]
class ModelWithNamedVisibleAttribute extends Model
{
}

#[Appends(columns: ['full_name', 'is_admin'])]
class ModelWithNamedAppendsAttribute extends Model
{
}

#[Touches(relations: ['post', 'author'])]
class ModelWithNamedTouchesAttribute extends Model
{
}

#[Refreshes(columns: ['name', 'email'])]
class ModelWithNamedRefreshesAttribute extends ModelWithRefreshesAttribute
{
}

trait CountsModelInitializations
{
    protected function initializeCountsModelInitializations(): void
    {
        ++static::$initializerRuns;
    }
}

#[Table(
    name: 'attribute_table',
    key: 'uuid',
    keyType: 'string',
    incrementing: false,
    timestamps: false,
)]
#[Connection('secondary')]
#[DateFormat('U')]
#[Fillable('class_fillable')]
#[Unguarded]
#[Hidden('class_hidden')]
#[Visible('class_visible')]
#[Appends('class_append')]
#[Touches('class_touch')]
#[WithoutTimestamps]
class SerializableModelWithAttributes extends Model
{
    use CountsModelInitializations;

    public static int $initializerRuns = 0;

    /**
     * Replace the touched relationships.
     *
     * @param array<int, string> $relations
     */
    public function replaceTouchedRelations(array $relations): void
    {
        $this->touches = $relations;
    }

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'string'];
    }
}
