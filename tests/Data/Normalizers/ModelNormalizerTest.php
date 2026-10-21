<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Normalizers\ModelNormalizerTest;

use Exception;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\LoadRelation;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Mappers\SnakeCaseMapper;
use Hypervel\Data\Optional;
use Hypervel\Database\LazyLoadingViolationException;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\FakeModelData;
use Hypervel\Tests\Data\Fixtures\FakeNestedModelData;
use Hypervel\Tests\Data\Fixtures\Models\FakeModel;
use Hypervel\Tests\Data\Fixtures\Models\FakeNestedModel;

class ModelNormalizerTest extends TestCase
{
    use RefreshDatabase;

    // Spatie's ModelNormalizer class is not included; Hypervel reads models through its fixed source handling.

    protected bool $migrateRefresh = true;

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Get the migration options.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/../Fixtures/Migrations',
        ];
    }

    public function testCanGetADataObjectFromModel(): void
    {
        $model = FakeModel::factory()->create();
        $data = FakeModelData::from($model);

        $this->assertSame($model->string, $data->string);
        $this->assertSame($model->nullable, $data->nullable);
        $this->assertEquals($model->date, $data->date);
        $this->assertSame('translated_string', $data->translated);
    }

    public function testDoesNotLoopInfinitelyOnRelations(): void
    {
        $parentModel = FakeModel::factory()->makeOne();
        $childModel = FakeNestedModel::factory()->makeOne();

        $childModel->setRelation('parent', $parentModel);
        $parentModel->setRelation('pivot', $childModel);

        $data = FakeModelData::from($parentModel);

        $this->assertSame($parentModel->string, $data->string);
        $this->assertSame($parentModel->nullable, $data->nullable);
        $this->assertEquals($parentModel->date, $data->date);
    }

    public function testCanGetADataObjectWithNestingFromModelAndRelationsWhenLoaded(): void
    {
        $model = FakeModel::factory()->create();

        $nestedModelA = FakeNestedModel::factory()->for($model)->create();
        $nestedModelB = FakeNestedModel::factory()->for($model)->create();

        $data = FakeModelData::from($model->load('fakeNestedModels'));

        $this->assertSame($model->string, $data->string);
        $this->assertSame($model->nullable, $data->nullable);
        $this->assertEquals($model->date, $data->date);
        $this->assertCount(2, $data->fake_nested_models);

        foreach ([$nestedModelA, $nestedModelB] as $index => $nestedModel) {
            $this->assertSame($nestedModel->string, $data->fake_nested_models[$index]->string);
            $this->assertSame($nestedModel->nullable, $data->fake_nested_models[$index]->nullable);
            $this->assertEquals($nestedModel->date, $data->fake_nested_models[$index]->date);
        }
    }

    public function testCanGetADataObjectFromModelWithAccessors(): void
    {
        $model = FakeModel::factory()->create();
        $data = FakeModelData::from($model);

        $this->assertSame($model->accessor, $data->accessor);
        $this->assertSame($model->old_accessor, $data->old_accessor);
    }

    public function testWillOnlyCallModelAccessorsWhenRequired(): void
    {
        $dataClass = new class extends Data {
            public string $accessor;

            public string $old_accessor;
        };

        $data = $dataClass::from(FakeModel::factory()->create());

        $this->assertStringStartsWith('accessor_', $data->accessor);

        foreach ([
            'This attribute should not be called' => new class extends Data {
                public string $performance_heavy;
            },
            'This accessor should not be called' => new class extends Data {
                public string $performance_heavy_accessor;
            },
        ] as $message => $dataClass) {
            try {
                $dataClass::from(FakeModel::factory()->create());
                $this->fail("Expected [{$message}].");
            } catch (Exception $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testWillReturnNullForNonExistingProperties(): void
    {
        $dataClass = new class extends Data {
            public ?string $non_existing_property;
        };

        $data = $dataClass::from(FakeModel::factory()->create());

        $this->assertNull($data->non_existing_property);
    }

    public function testCanLoadRelationsOnAModelWhenRequiredAndTheLoadRelationAttributeIsSet(): void
    {
        $model = FakeModel::factory()->create();

        FakeNestedModel::factory()->for($model)->create();
        FakeNestedModel::factory()->for($model)->create();

        $dataClass = new class extends Data {
            #[LoadRelation, DataCollectionOf(FakeNestedModelData::class)]
            public array $fake_nested_models;

            #[LoadRelation, DataCollectionOf(FakeNestedModelData::class)]
            public array $fake_nested_models_snake_cased;
        };

        $model->load('fake_nested_models_snake_cased');
        DB::enableQueryLog();

        $data = $dataClass::from($model);

        $queryLog = DB::getQueryLog();

        $this->assertCount(2, $data->fake_nested_models);
        $this->assertContainsOnlyInstancesOf(FakeNestedModelData::class, $data->fake_nested_models);
        $this->assertCount(2, $data->fake_nested_models_snake_cased);
        $this->assertContainsOnlyInstancesOf(FakeNestedModelData::class, $data->fake_nested_models_snake_cased);
        $this->assertCount(1, $queryLog);
    }

    public function testWillNotAutomaticallyLoadRelationWhenTheLoadRelationAttributeIsNotSet(): void
    {
        $model = FakeModel::factory()->create();

        FakeNestedModel::factory()->for($model)->create();
        FakeNestedModel::factory()->for($model)->create();

        $optionalClass = new class extends Data {
            #[DataCollectionOf(FakeNestedModelData::class)]
            public array|Optional $fake_nested_models;
        };

        DB::enableQueryLog();

        $data = $optionalClass::from($model);

        $this->assertInstanceOf(Optional::class, $data->fake_nested_models);
        $this->assertCount(0, DB::getQueryLog());

        $nullableClass = new class extends Data {
            public ?array $fake_nested_models = null;
        };

        DB::flushQueryLog();

        $data = $nullableClass::from($model);

        $this->assertNull($data->fake_nested_models);
        $this->assertCount(0, DB::getQueryLog());
    }

    public function testCanUseMappersToMapTheNames(): void
    {
        $model = FakeModel::factory()->create();

        FakeNestedModel::factory()->for($model)->create();
        FakeNestedModel::factory()->for($model)->create();

        $dataClass = new class extends Data {
            #[DataCollectionOf(FakeNestedModelData::class), MapInputName(SnakeCaseMapper::class)]
            public array|Optional $fakeNestedModels;

            #[MapInputName(SnakeCaseMapper::class)]
            public string $oldAccessor;
        };

        $data = $dataClass::from($model->load('fakeNestedModels'));

        $this->assertCount(2, $data->fakeNestedModels);
        $this->assertContainsOnlyInstancesOf(FakeNestedModelData::class, $data->fakeNestedModels);
        $this->assertSame($model->old_accessor, $data->oldAccessor);
    }

    public function testCanCreateADataPropertyForAModelAttributeWhichFetchesARelationThatIsLoadedAndItWillNotTriggerALazyLoadingException(): void
    {
        $dataClass = new class('') extends Data {
            /**
             * Create the data object from an attribute that reads a relation.
             */
            public function __construct(public string $accessor_using_relation)
            {
            }
        };

        $model = FakeModel::factory()->create();
        FakeNestedModel::factory()->for($model)->create();

        $freshModel = FakeModel::query()->first();

        $freshModel->preventsLazyLoading = true;

        try {
            $freshModel->append('accessorUsingRelation');
            $dataClass::from($freshModel);
            $this->fail('Expected reading the unloaded relation to be prevented.');
        } catch (LazyLoadingViolationException) {
        }

        $freshModel = $freshModel
            ->load('fakeNestedModels')
            ->append('accessorUsingRelation');

        $data = $dataClass::from($freshModel);

        $this->assertSame($freshModel->accessor_using_relation, $data->accessor_using_relation);
    }
}
