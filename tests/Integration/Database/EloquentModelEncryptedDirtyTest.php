<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\TestWith;

class EloquentModelEncryptedDirtyTest extends DatabaseTestCase
{
    public function testDirtyAttributeBehaviorWithNoPreviousKeys(): void
    {
        config(['app.key' => str_repeat('a', 32)]);
        Model::$encrypter = null;

        $model = new EncryptedDirtyAttributeCast([
            'secret' => 'some-secret',
            'secret_array_object' => [1, 2, 3],
        ]);

        $model->syncOriginal();

        $this->assertFalse($model->isDirty('secret'));
        $this->assertFalse($model->isDirty('secret_array_object'));

        $model->secret = 'some-secret';
        $model->secret_array_object = [1, 2, 3];

        // Encrypted attributes should always be considered dirty if updated in any way because of rotatable encryption keys...
        $this->assertFalse($model->isDirty('secret'));
        $this->assertFalse($model->isDirty('secret_array_object'));

        $model->secret = 'some-other-secret';
        $model->secret_array_object = [4, 5, 6];

        // Encrypted attributes should always be considered dirty if updated in any way because of rotatable encryption keys...
        $this->assertTrue($model->isDirty('secret'));
        $this->assertTrue($model->isDirty('secret_array_object'));
    }

    public function testEncryptedObjectAndCollectionAttributesAreNotDirtyWhenSetToTheSameValue(): void
    {
        config(['app.key' => str_repeat('a', 32)]);
        Model::$encrypter = null;

        $model = new EncryptedDirtyAttributeCast([
            'secret_object' => ['name' => 'Taylor'],
            'secret_collection' => [1, 2, 3],
        ]);

        $model->syncOriginal();

        $model->secret_object = ['name' => 'Taylor'];
        $model->secret_collection = [1, 2, 3];

        $this->assertFalse($model->isDirty('secret_object'));
        $this->assertFalse($model->isDirty('secret_collection'));

        $model->secret_object = ['name' => 'Otwell'];
        $model->secret_collection = [4, 5, 6];

        $this->assertTrue($model->isDirty('secret_object'));
        $this->assertTrue($model->isDirty('secret_collection'));

        $model = new EncryptedDirtyAttributeCast([
            'secret_object' => null,
            'secret_collection' => null,
        ]);

        $model->syncOriginal();

        $model->secret_object = ['name' => 'Taylor'];
        $model->secret_collection = [1, 2, 3];

        $this->assertTrue($model->isDirty('secret_object'));
        $this->assertTrue($model->isDirty('secret_collection'));
    }

    #[TestWith(['{"nested":[]}', '{"nested":{}}', true])]
    #[TestWith(['[]', '{}', true])]
    #[TestWith(['{"value":1}', '{"value":"1"}', true])]
    #[TestWith(['{"nested": {"value": 1}}', '{"nested":{"value":1}}', false])]
    public function testEncryptedObjectDirtyCheckingPreservesJsonTypes(string $original, string $current, bool $dirty): void
    {
        config(['app.key' => str_repeat('a', 32)]);
        Model::$encrypter = null;

        $model = new EncryptedDirtyAttributeCast;
        $model->setRawAttributes(['secret_object' => Crypt::encryptString($original)], sync: true);
        $model->secret_object = json_decode($current, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($dirty, $model->isDirty('secret_object'));
    }

    public function testDirtyAttributeBehaviorWithPreviousKeys(): void
    {
        config(['app.key' => str_repeat('a', 32)]);
        config(['app.previous_keys' => [str_repeat('b', 32)]]);
        Model::$encrypter = null;

        $model = new EncryptedDirtyAttributeCast([
            'secret' => 'some-secret',
            'secret_array_object' => [1, 2, 3],
        ]);

        $model->syncOriginal();

        $this->assertFalse($model->isDirty('secret'));
        $this->assertFalse($model->isDirty('secret_array_object'));

        $model->secret = 'some-secret';
        $model->secret_array_object = [1, 2, 3];

        // Encrypted attributes should always be considered dirty if updated in any way because of rotatable encryption keys...
        $this->assertTrue($model->isDirty('secret'));
        $this->assertTrue($model->isDirty('secret_array_object'));

        $model->secret = 'some-other-secret';
        $model->secret_array_object = [4, 5, 6];

        // Encrypted attributes should always be considered dirty if updated in any way because of rotatable encryption keys...
        $this->assertTrue($model->isDirty('secret'));
        $this->assertTrue($model->isDirty('secret_array_object'));
    }
}

/**
 * @property $secret
 * @property $secret_array
 * @property $secret_json
 * @property $secret_object
 * @property $secret_collection
 */
class EncryptedDirtyAttributeCast extends Model
{
    protected array $guarded = [];

    public array $casts = [
        'secret' => 'encrypted',
        'secret_array' => 'encrypted:array',
        'secret_json' => 'encrypted:json',
        'secret_object' => 'encrypted:object',
        'secret_collection' => 'encrypted:collection',
        'secret_array_object' => AsEncryptedArrayObject::class,
    ];
}
