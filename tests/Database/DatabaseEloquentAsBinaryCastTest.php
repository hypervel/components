<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentAsBinaryCastTest;

use Hypervel\Database\Eloquent\Casts\AsBinary;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

class DatabaseEloquentAsBinaryCastTest extends TestCase
{
    public function testCastThrowsWhenFormatMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The binary codec format is required.');

        $model = new TestModel;
        $model->setRawAttributes(['no_format' => 'value']);
        $model->no_format;
    }

    public function testCastThrowsOnInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported binary codec format [invalid]. Allowed formats are: uuid, ulid.');

        $model = new TestModel;
        $model->setRawAttributes(['invalid_format' => 'value']);
        $model->invalid_format;
    }

    public function testGetDecodesUuidFromBinary(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $model = new TestModel;
        $model->setRawAttributes(['uuid' => Uuid::fromString($uuid)->toBinary()]);

        $this->assertSame($uuid, $model->uuid);
    }

    public function testGetDecodesUuidFromStreamWithoutConsumingIt(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, Uuid::fromString($uuid)->toBinary());
        $model = new TestModel;
        $model->setRawAttributes(['uuid' => $stream]);

        try {
            $this->assertSame($uuid, $model->uuid);
            $this->assertSame($uuid, $model->uuid);
            $this->assertSame(0, ftell($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testSetEncodesUuidToBinary(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $model = new TestModel;
        $model->uuid = $uuid;

        $this->assertSame(Uuid::fromString($uuid)->toBinary(), $model->getAttributes()['uuid']);
    }

    public function testGetDecodesUlidFromBinary(): void
    {
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $model = new TestModel;
        $model->setRawAttributes(['ulid' => Ulid::fromString($ulid)->toBinary()]);

        $this->assertSame($ulid, $model->ulid);
    }

    public function testSetEncodesUlidToBinary(): void
    {
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $model = new TestModel;
        $model->ulid = $ulid;

        $this->assertSame(Ulid::fromString($ulid)->toBinary(), $model->getAttributes()['ulid']);
    }

    public function testGetReturnsNullForNullValue(): void
    {
        $model = new TestModel;
        $model->setRawAttributes(['uuid' => null]);

        $this->assertNull($model->uuid);
    }

    public function testSetEncodesNullToNull(): void
    {
        $model = new TestModel;
        $model->uuid = null;

        $this->assertNull($model->getAttributes()['uuid']);
    }

    public function testUuidHelperMethod(): void
    {
        $this->assertSame(AsBinary::class . ':uuid', AsBinary::uuid());
    }

    public function testUlidHelperMethod(): void
    {
        $this->assertSame(AsBinary::class . ':ulid', AsBinary::ulid());
    }

    public function testOfHelperMethod(): void
    {
        $this->assertSame(AsBinary::class . ':custom', AsBinary::of('custom'));
    }
}

class TestModel extends Model
{
    protected array $guarded = [];

    protected function casts(): array
    {
        return [
            'uuid' => AsBinary::class . ':uuid',
            'ulid' => AsBinary::class . ':ulid',
            'no_format' => AsBinary::class,
            'invalid_format' => AsBinary::class . ':invalid',
        ];
    }
}
