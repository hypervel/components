<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\BinaryParameter;
use Hypervel\Database\Eloquent\Casts\AsBinary;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

class DatabaseEloquentAsBinaryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('binary_identifiers', function (Blueprint $table): void {
            $table->increments('id');
            $table->binary('uuid', length: 16, fixed: true)->unique();
            $table->binary('ulid', length: 16, fixed: true);
        });

        Schema::create('binary_primary_keys', function (Blueprint $table): void {
            $table->binary('id', length: 16, fixed: true)->primary();
            $table->string('name');
            $table->integer('counter')->default(0);
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function testBinaryIdentifiersRoundTripAcrossModelAndQueryBuilderWritePaths(): void
    {
        $nulAndInvalidUtf8Uuid = '00ff7f80-4048-43c2-b80b-40491d165946';
        $backslashUlid = '2WBHE5RQ2WBHE5RQ2WBHE5RQ2W';
        $nulAndInvalidUtf8UuidBytes = Uuid::fromString($nulAndInvalidUtf8Uuid)->toBinary();
        $backslashUlidBytes = Ulid::fromString($backslashUlid)->toBinary();

        $this->assertStringContainsString("\x00", $nulAndInvalidUtf8UuidBytes);
        $this->assertFalse(mb_check_encoding($nulAndInvalidUtf8UuidBytes, 'UTF-8'));
        $this->assertSame(str_repeat('\\', 16), $backslashUlidBytes);

        $model = AsBinaryIdentifierModel::create([
            'uuid' => $nulAndInvalidUtf8Uuid,
            'ulid' => $backslashUlid,
        ]);

        $this->assertSame($nulAndInvalidUtf8UuidBytes, $model->getAttributes()['uuid']);
        $this->assertSame($backslashUlidBytes, $model->getAttributes()['ulid']);
        $this->assertSame($nulAndInvalidUtf8UuidBytes, $model->getRawOriginal('uuid'));
        $this->assertSame($backslashUlidBytes, $model->getRawOriginal('ulid'));

        $created = AsBinaryIdentifierModel::query()->findOrFail($model->getKey());

        $this->assertSame($nulAndInvalidUtf8Uuid, $created->uuid);
        $this->assertSame($nulAndInvalidUtf8Uuid, $created->uuid);
        $this->assertSame($backslashUlid, $created->ulid);
        $this->assertSame($backslashUlid, $created->ulid);

        $structuralUuid = '21107c1e-6448-43c2-b80b-40491d165946';
        $structuralUuidBytes = Uuid::fromString($structuralUuid)->toBinary();
        $model->uuid = $structuralUuid;

        $this->assertTrue($model->save());
        $this->assertSame($structuralUuidBytes, $model->getAttributes()['uuid']);
        $this->assertSame($structuralUuidBytes, $model->getRawOriginal('uuid'));
        $this->assertSame($structuralUuidBytes, $model->getChanges()['uuid']);

        $updated = AsBinaryIdentifierModel::query()->findOrFail($model->getKey());

        $this->assertSame($structuralUuid, $updated->uuid);
        $this->assertSame($backslashUlid, $updated->ulid);

        $nilUuid = '00000000-0000-0000-0000-000000000000';
        $nilUlid = '00000000000000000000000000';
        $nil = AsBinaryIdentifierModel::create([
            'uuid' => $nilUuid,
            'ulid' => $nilUlid,
        ]);
        $hydratedNil = AsBinaryIdentifierModel::query()->findOrFail($nil->getKey());

        $this->assertSame($nilUuid, $hydratedNil->uuid);
        $this->assertSame($nilUlid, $hydratedNil->ulid);

        if (in_array($model->getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            $saveOrIgnoreUuid = '550e8400-e29b-41d4-a716-446655440000';
            $saveOrIgnoreUlid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
            $saveOrIgnoreUuidBytes = Uuid::fromString($saveOrIgnoreUuid)->toBinary();
            $saveOrIgnore = new AsBinaryIdentifierModel([
                'uuid' => $saveOrIgnoreUuid,
                'ulid' => $saveOrIgnoreUlid,
            ]);

            $this->assertTrue($saveOrIgnore->saveOrIgnore());
            $this->assertSame($saveOrIgnoreUuidBytes, $saveOrIgnore->getAttributes()['uuid']);
            $this->assertSame($saveOrIgnoreUuidBytes, $saveOrIgnore->getRawOriginal('uuid'));

            $hydratedSaveOrIgnore = AsBinaryIdentifierModel::query()
                ->where('uuid', new BinaryParameter($saveOrIgnoreUuidBytes))
                ->firstOrFail();

            $this->assertSame($saveOrIgnoreUuid, $hydratedSaveOrIgnore->uuid);
            $this->assertSame($saveOrIgnoreUlid, $hydratedSaveOrIgnore->ulid);
        }

        $found = AsBinaryIdentifierModel::query()
            ->where('uuid', new BinaryParameter($structuralUuidBytes))
            ->firstOrFail();

        $this->assertSame($structuralUuid, $found->uuid);
        $this->assertSame($backslashUlid, $found->ulid);

        $bulkUpdateUlid = '01BX5ZZKBKACTAV9WEVGEMMVRZ';
        $bulkUpdateUlidBytes = Ulid::fromString($bulkUpdateUlid)->toBinary();

        $this->assertSame(1, AsBinaryIdentifierModel::query()
            ->where('uuid', new BinaryParameter($structuralUuidBytes))
            ->update(['ulid' => new BinaryParameter($bulkUpdateUlidBytes)]));

        $bulkUpdated = AsBinaryIdentifierModel::query()->findOrFail($model->getKey());

        $this->assertSame($structuralUuid, $bulkUpdated->uuid);
        $this->assertSame($bulkUpdateUlid, $bulkUpdated->ulid);

        $upsertUuid = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $upsertUlid = '01H00000000000000000000000';
        $upsertUuidBytes = Uuid::fromString($upsertUuid)->toBinary();
        $upsertUlidBytes = Ulid::fromString($upsertUlid)->toBinary();

        AsBinaryIdentifierModel::query()->upsert([
            [
                'uuid' => new BinaryParameter($upsertUuidBytes),
                'ulid' => new BinaryParameter($upsertUlidBytes),
            ],
        ], ['uuid'], ['ulid']);

        $upserted = AsBinaryIdentifierModel::query()
            ->where('uuid', new BinaryParameter($upsertUuidBytes))
            ->firstOrFail();

        $this->assertSame($upsertUuid, $upserted->uuid);
        $this->assertSame($upsertUlid, $upserted->ulid);
    }

    #[DataProvider('fillAndInsertMethods')]
    public function testBinaryIdentifiersRoundTripThroughFillAndInsert(string $method): void
    {
        $uuid = '00ff7f80-4048-43c2-b80b-40491d165946';
        $ulid = '2WBHE5RQ2WBHE5RQ2WBHE5RQ2W';
        $attributes = ['uuid' => $uuid, 'ulid' => $ulid];

        AsBinaryIdentifierModel::query()->{$method}(
            $method === 'fillAndInsertGetId' ? $attributes : [$attributes]
        );

        $model = AsBinaryIdentifierModel::query()
            ->where('uuid', new BinaryParameter(Uuid::fromString($uuid)->toBinary()))
            ->where('ulid', new BinaryParameter(Ulid::fromString($ulid)->toBinary()))
            ->sole();

        $this->assertSame($uuid, $model->uuid);
        $this->assertSame($ulid, $model->ulid);
    }

    /**
     * Provide the insert methods that prepare model attributes.
     */
    public static function fillAndInsertMethods(): array
    {
        return [
            'insert' => ['fillAndInsert'],
            'insert or ignore' => ['fillAndInsertOrIgnore'],
            'insert and get ID' => ['fillAndInsertGetId'],
        ];
    }

    public function testFactoryInsertPreparesGeneratedAndSuppliedBinaryPrimaryKeys(): void
    {
        $suppliedId = '00ff7f80-4048-43c2-b80b-40491d165946';

        (new AsBinaryPrimaryKeyFactory)->forEachSequence(
            ['name' => 'generated'],
            ['name' => 'supplied', 'id' => $suppliedId],
        )->insert();

        $models = AsBinaryFactoryPrimaryKeyModel::query()->get()->keyBy('name');

        $this->assertCount(2, $models);
        $this->assertTrue(Str::isUuid($models['generated']->id));
        $this->assertSame($suppliedId, $models['supplied']->id);

        foreach ($models as $name => $model) {
            $key = new BinaryParameter(Uuid::fromString($model->id)->toBinary());

            $this->assertSame($name, AsBinaryFactoryPrimaryKeyModel::query()->whereKey($key)->sole()->name);
        }
    }

    public function testBinaryPrimaryKeysPreserveBindingIntentAcrossModelOperations(): void
    {
        $primaryId = '21107c1e-6448-43c2-b80b-40491d165946';
        $otherId = '550e8400-e29b-41d4-a716-446655440000';
        $softDeletedId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $primaryBytes = Uuid::fromString($primaryId)->toBinary();
        $otherBytes = Uuid::fromString($otherId)->toBinary();
        $softDeletedBytes = Uuid::fromString($softDeletedId)->toBinary();
        $primaryKey = new BinaryParameter($primaryBytes);
        $otherKey = new BinaryParameter($otherBytes);

        $model = AsBinaryPrimaryKeyModel::create([
            'id' => $primaryId,
            'name' => 'created',
        ]);
        AsBinaryPrimaryKeyModel::create([
            'id' => $otherId,
            'name' => 'other',
        ]);

        $this->assertSame($primaryBytes, $model->getAttributes()['id']);
        $this->assertSame($primaryBytes, $model->getRawOriginal('id'));
        $this->assertNotInstanceOf(BinaryParameter::class, $model->getAttributes()['id']);
        $this->assertSame('created', AsBinaryPrimaryKeyModel::query()->findOrFail($primaryKey)->name);
        $this->assertSame('created', AsBinaryPrimaryKeyModel::query()->whereKey($primaryKey)->value('name'));
        $this->assertSame(
            ['other'],
            AsBinaryPrimaryKeyModel::query()->whereKeyNot($primaryKey)->orderBy('name')->pluck('name')->all()
        );

        $model->name = 'updated';

        $this->assertTrue($model->save());
        $this->assertSame('updated', AsBinaryPrimaryKeyModel::query()->findOrFail($primaryKey)->name);

        $unsynced = new AsBinaryPrimaryKeyModel;
        $unsynced->setRawAttributes([
            'id' => $otherBytes,
            'name' => 'updated without an original',
            'counter' => 0,
        ]);
        $unsynced->exists = true;

        $this->assertTrue($unsynced->save());
        $this->assertSame('updated without an original', AsBinaryPrimaryKeyModel::query()->findOrFail($otherKey)->name);

        $hydrated = AsBinaryPrimaryKeyModel::query()->findOrFail($primaryKey);
        $hydrated->name = 'first hydrated update';
        $this->assertTrue($hydrated->save());
        $hydrated->name = 'second hydrated update';
        $this->assertTrue($hydrated->save());

        $originalKeyStream = $hydrated->getRawOriginal('id');

        if (is_resource($originalKeyStream)) {
            $this->assertSame(0, ftell($originalKeyStream));
        }

        $fresh = $hydrated->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame('second hydrated update', $fresh->name);

        AsBinaryPrimaryKeyModel::query()->whereKey($primaryKey)->update(['name' => 'refreshed']);
        $hydrated->refresh();

        $this->assertSame('refreshed', $hydrated->name);
        $this->assertSame(1, $hydrated->increment('counter'));
        $this->assertSame(1, AsBinaryPrimaryKeyModel::query()->findOrFail($primaryKey)->counter);
        $this->assertSame(1, $hydrated->decrement('counter'));
        $this->assertSame(0, AsBinaryPrimaryKeyModel::query()->findOrFail($primaryKey)->counter);
        $this->assertNotInstanceOf(BinaryParameter::class, $hydrated->getAttributes()['id']);
        $this->assertNotInstanceOf(BinaryParameter::class, $hydrated->getRawOriginal('id'));

        $storedPrimaryKey = $model->getConnection()
            ->table('binary_primary_keys')
            ->where('id', $primaryKey)
            ->value('id');

        if (is_resource($storedPrimaryKey)) {
            $primaryKeyStream = $storedPrimaryKey;

            try {
                $storedPrimaryKey = stream_get_contents($primaryKeyStream, offset: 0);
            } finally {
                fclose($primaryKeyStream);
            }
        }

        $this->assertSame($primaryBytes, $storedPrimaryKey);
        $this->assertTrue($hydrated->delete());
        $this->assertNull(AsBinaryPrimaryKeyModel::query()->find($primaryKey));

        $softDeleted = SoftDeletingAsBinaryPrimaryKeyModel::create([
            'id' => $softDeletedId,
            'name' => 'soft deleted',
        ]);
        $softDeletedKey = new BinaryParameter($softDeletedBytes);

        $this->assertTrue($softDeleted->delete());
        $this->assertNotNull(SoftDeletingAsBinaryPrimaryKeyModel::withTrashed()->find($softDeletedKey));
        $this->assertTrue($softDeleted->forceDelete());
        $this->assertNull(SoftDeletingAsBinaryPrimaryKeyModel::withTrashed()->find($softDeletedKey));
    }
}

class AsBinaryIdentifierModel extends Model
{
    protected ?string $table = 'binary_identifiers';

    protected array $guarded = [];

    public bool $timestamps = false;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'uuid' => AsBinary::uuid(),
            'ulid' => AsBinary::ulid(),
        ];
    }
}

class AsBinaryPrimaryKeyModel extends Model
{
    protected ?string $table = 'binary_primary_keys';

    protected array $guarded = [];

    protected string $keyType = 'string';

    public bool $incrementing = false;

    public bool $timestamps = false;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'id' => AsBinary::uuid(),
        ];
    }
}

class AsBinaryFactoryPrimaryKeyModel extends AsBinaryPrimaryKeyModel
{
    use HasUuids;
}

class AsBinaryPrimaryKeyFactory extends Factory
{
    protected ?string $model = AsBinaryFactoryPrimaryKeyModel::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [];
    }
}

class SoftDeletingAsBinaryPrimaryKeyModel extends AsBinaryPrimaryKeyModel
{
    use SoftDeletes;
}
