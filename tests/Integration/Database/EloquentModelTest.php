<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class EloquentModelTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('test_model1', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamp('nullable_date')->nullable();
        });

        Schema::create('test_model2', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('title');
            $table->integer('count')->default(0);
        });
    }

    public function testUserCanUpdateNullableDate()
    {
        $user = TestModel1::create([
            'nullable_date' => null,
        ]);

        $user->fill([
            'nullable_date' => $now = CarbonImmutable::now(),
        ]);
        $this->assertTrue($user->isDirty('nullable_date'));

        $user->save();
        $this->assertEquals($now->toDateString(), $user->nullable_date->toDateString());
    }

    public function testAttributeChanges()
    {
        $user = TestModel2::create([
            'name' => $originalName = Str::random(), 'title' => Str::random(),
        ]);

        $this->assertEmpty($user->getDirty());
        $this->assertEmpty($user->getChanges());
        $this->assertEmpty($user->getPrevious());
        $this->assertFalse($user->isDirty());
        $this->assertFalse($user->wasChanged());

        $user->name = $overrideName = Str::random();

        $this->assertEquals(['name' => $overrideName], $user->getDirty());
        $this->assertEmpty($user->getChanges());
        $this->assertEmpty($user->getPrevious());
        $this->assertTrue($user->isDirty());
        $this->assertFalse($user->wasChanged());

        $user->save();

        $this->assertEmpty($user->getDirty());
        $this->assertEquals(['name' => $overrideName], $user->getChanges());
        $this->assertEquals(['name' => $originalName], $user->getPrevious());
        $this->assertTrue($user->wasChanged());
        $this->assertTrue($user->wasChanged('name'));
    }

    public function testDiscardChanges()
    {
        $user = TestModel2::create([
            'name' => $originalName = Str::random(), 'title' => Str::random(),
        ]);

        $this->assertEmpty($user->getDirty());
        $this->assertEmpty($user->getChanges());
        $this->assertEmpty($user->getPrevious());
        $this->assertFalse($user->isDirty());
        $this->assertFalse($user->wasChanged());

        $user->name = $overrideName = Str::random();

        $this->assertEquals(['name' => $overrideName], $user->getDirty());
        $this->assertEmpty($user->getChanges());
        $this->assertEmpty($user->getPrevious());
        $this->assertTrue($user->isDirty());
        $this->assertFalse($user->wasChanged());
        $this->assertSame($originalName, $user->getOriginal('name'));
        $this->assertSame($overrideName, $user->getAttribute('name'));

        $user->discardChanges();

        $this->assertEmpty($user->getDirty());
        $this->assertEmpty($user->getChanges());
        $this->assertEmpty($user->getPrevious());
        $this->assertSame($originalName, $user->getOriginal('name'));
        $this->assertSame($originalName, $user->getAttribute('name'));

        $user->save();
        $this->assertFalse($user->wasChanged());
        $this->assertEmpty($user->getChanges());
        $this->assertEmpty($user->getPrevious());
    }

    public function testInsertRecordWithReservedWordFieldName()
    {
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->timestamp('start');
            $table->timestamp('end')->nullable();
            $table->boolean('analyze');
        });

        $model = new class extends Model {
            protected ?string $table = 'actions';

            protected array $guarded = ['id'];

            public bool $timestamps = false;
        };

        $model->newInstance()->create([
            'label' => 'test',
            'start' => '2023-01-01 00:00:00',
            'end' => '2024-01-01 00:00:00',
            'analyze' => true,
        ]);

        $this->assertDatabaseHas('actions', [
            'label' => 'test',
            'start' => '2023-01-01 00:00:00',
            'end' => '2024-01-01 00:00:00',
            'analyze' => true,
        ]);
    }

    #[DataProvider('persistedMutationOperations')]
    public function testPersistedModelMutationsRejectMissingPrimaryKeys(string $operation): void
    {
        Model::preventAccessingMissingAttributes(false);

        $storedModel = TestModel2::create([
            'name' => 'original',
            'title' => 'title',
            'count' => 10,
        ]);
        $partialModel = TestModel2::query()
            ->select('name', 'title', 'count')
            ->findOrFail($storedModel->id);

        try {
            $this->performPersistedMutation($partialModel, $operation);

            $this->fail("The [{$operation}] operation did not reject a missing primary key.");
        } catch (MissingAttributeException $exception) {
            $this->assertStringContainsString('The attribute [id]', $exception->getMessage());
        }

        $this->assertDatabaseHas('test_model2', [
            'id' => $storedModel->id,
            'name' => 'original',
            'count' => 10,
        ]);
    }

    #[DataProvider('persistedMutationOperations')]
    public function testCompletePersistedModelMutationsTargetTheStoredRow(string $operation): void
    {
        $model = TestModel2::create([
            'name' => 'original',
            'title' => 'title',
            'count' => 10,
        ]);

        $this->assertNotFalse($this->performPersistedMutation($model, $operation));

        if ($operation === 'delete') {
            $this->assertDatabaseMissing('test_model2', ['id' => $model->id]);

            return;
        }

        $this->assertDatabaseHas('test_model2', [
            'id' => $model->id,
            'name' => $operation === 'save' ? 'changed' : 'original',
            'count' => str_contains($operation, 'decrement') ? 9 : ($operation === 'save' ? 10 : 11),
        ]);
    }

    public static function persistedMutationOperations(): array
    {
        return [
            'dirty save' => ['save'],
            'delete' => ['delete'],
            'increment' => ['increment'],
            'decrement' => ['decrement'],
            'increment each' => ['incrementEach'],
            'decrement each' => ['decrementEach'],
        ];
    }

    public function testDirtyFreeAndVetoedPartialModelMutationsDoNotRequireThePrimaryKey(): void
    {
        Model::preventAccessingMissingAttributes(false);

        $storedModel = TestModel2::create([
            'name' => 'original',
            'title' => 'title',
            'count' => 10,
        ]);
        $partialModel = TestModel2::query()
            ->select('name', 'title', 'count')
            ->findOrFail($storedModel->id);

        $this->assertTrue($partialModel->save());

        TestModel2::updating(fn () => false);

        $partialModel->name = 'changed';

        $this->assertFalse($partialModel->save());

        TestModel2::deleting(fn () => false);

        $this->assertFalse($partialModel->delete());
        $this->assertDatabaseHas('test_model2', [
            'id' => $storedModel->id,
            'name' => 'original',
            'count' => 10,
        ]);
    }

    private function performPersistedMutation(TestModel2 $model, string $operation): int|bool|null
    {
        return match ($operation) {
            'save' => tap($model, fn (TestModel2 $model) => $model->name = 'changed')->save(),
            'delete' => $model->delete(),
            'increment' => $model->increment('count'),
            'decrement' => $model->decrement('count'),
            'incrementEach' => $model->incrementEach(['count' => 1]),
            'decrementEach' => $model->decrementEach(['count' => 1]),
        };
    }
}

class TestModel1 extends Model
{
    public ?string $table = 'test_model1';

    public bool $timestamps = false;

    protected array $guarded = [];

    protected array $casts = ['nullable_date' => 'datetime'];
}

class TestModel2 extends Model
{
    public ?string $table = 'test_model2';

    public bool $timestamps = false;

    protected array $guarded = [];
}
