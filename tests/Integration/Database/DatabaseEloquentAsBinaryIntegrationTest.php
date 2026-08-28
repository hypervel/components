<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\BinaryParameter;
use Hypervel\Database\Eloquent\Casts\AsBinary;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
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
