<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Concerns;

use Carbon\CarbonImmutable;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Encryption\Encrypter;
use Hypervel\Hashing\BcryptHasher;
use Hypervel\Support\Facades\Hash;
use Hypervel\Testbench\TestCase;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class HasAttributesCastsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up a test encrypter
        $encrypter = new Encrypter(str_repeat('a', 16));
        ImmutableDateModel::encryptUsing($encrypter);
        EncryptedModel::encryptUsing($encrypter);
        HashedModel::encryptUsing($encrypter);
        JsonUnicodeModel::encryptUsing($encrypter);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Reset encrypter
        ImmutableDateModel::encryptUsing(null);
        EncryptedModel::encryptUsing(null);
        HashedModel::encryptUsing(null);
        JsonUnicodeModel::encryptUsing(null);
    }

    public function testImmutableDateCastReturnsImmutableCarbon(): void
    {
        $model = new ImmutableDateModel();
        $model->setRawAttributes([
            'birth_date' => '2000-01-15',
        ]);

        $date = $model->birth_date;

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame('2000-01-15', $date->format('Y-m-d'));
    }

    public function testImmutableDatetimeCastReturnsImmutableCarbon(): void
    {
        $model = new ImmutableDateModel();
        $model->setRawAttributes([
            'created_at' => '2000-01-15 10:30:00',
        ]);

        $datetime = $model->created_at;

        $this->assertInstanceOf(CarbonImmutable::class, $datetime);
        $this->assertSame('2000-01-15 10:30:00', $datetime->format('Y-m-d H:i:s'));
    }

    public function testImmutableCustomDatetimeCastReturnsImmutableCarbon(): void
    {
        $model = new ImmutableDateModel();
        // Use standard datetime format that asDateTime can parse
        $model->setRawAttributes([
            'formatted_at' => '2000-01-15 10:30:00',
        ]);

        $datetime = $model->formatted_at;

        $this->assertInstanceOf(CarbonImmutable::class, $datetime);
        $this->assertSame('2000-01-15', $datetime->format('Y-m-d'));
    }

    public function testJsonUnicodeCastPreservesUnicodeOnSet(): void
    {
        $model = new JsonUnicodeModel();
        $model->data = ['name' => '日本語', 'emoji' => '😀'];

        $rawAttributes = $model->getAttributes();

        // Should contain unescaped unicode
        $this->assertStringContainsString('日本語', $rawAttributes['data']);
        $this->assertStringContainsString('😀', $rawAttributes['data']);
        // Should NOT contain escaped unicode like \u65e5
        $this->assertStringNotContainsString('\u', $rawAttributes['data']);
    }

    public function testJsonUnicodeCastPreservesUnicodeOnGet(): void
    {
        $model = new JsonUnicodeModel();
        $model->setRawAttributes([
            'data' => '{"name":"日本語","emoji":"😀"}',
        ]);

        $data = $model->data;

        $this->assertSame('日本語', $data['name']);
        $this->assertSame('😀', $data['emoji']);
    }

    public function testEncryptedCastEncryptsOnSet(): void
    {
        $model = new EncryptedModel();
        $model->secret = 'my-secret-value';

        $rawAttributes = $model->getAttributes();

        // Should be encrypted (not plain text)
        $this->assertNotSame('my-secret-value', $rawAttributes['secret']);
        // Should be base64-encoded JSON (typical encryption format)
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $rawAttributes['secret']);
    }

    public function testEncryptedCastDecryptsOnGet(): void
    {
        $encrypter = new Encrypter(str_repeat('a', 16));
        $encrypted = $encrypter->encrypt('my-secret-value', false);

        $model = new EncryptedModel();
        $model->setRawAttributes([
            'secret' => $encrypted,
        ]);

        $this->assertSame('my-secret-value', $model->secret);
    }

    public function testEncryptedArrayCast(): void
    {
        $model = new EncryptedModel();
        $model->secret_array = ['key' => 'value', 'nested' => ['a' => 1]];

        // Verify it's encrypted in raw attributes
        $rawAttributes = $model->getAttributes();
        $this->assertNotSame(['key' => 'value', 'nested' => ['a' => 1]], $rawAttributes['secret_array']);

        // Verify it decrypts correctly
        $this->assertSame(['key' => 'value', 'nested' => ['a' => 1]], $model->secret_array);
    }

    public function testEncryptedCollectionCast(): void
    {
        $model = new EncryptedModel();
        $model->secret_collection = ['item1', 'item2', 'item3'];

        // Verify it's encrypted in raw attributes
        $rawAttributes = $model->getAttributes();
        $this->assertIsString($rawAttributes['secret_collection']);

        // Verify it decrypts to collection
        $collection = $model->secret_collection;
        $this->assertInstanceOf(\Hyperf\Collection\Collection::class, $collection);
        $this->assertSame(['item1', 'item2', 'item3'], $collection->all());
    }

    public function testEncryptedObjectCast(): void
    {
        $model = new EncryptedModel();
        $model->secret_object = ['key' => 'value'];

        // Verify it decrypts to stdClass
        $object = $model->secret_object;
        $this->assertInstanceOf(stdClass::class, $object);
        $this->assertSame('value', $object->key);
    }

    public function testHashedCastHashesPlainTextOnSet(): void
    {
        $model = new HashedModel();
        $model->password = 'secret123';

        $rawAttributes = $model->getAttributes();

        // Should be hashed (starts with $2y$ for bcrypt)
        $this->assertStringStartsWith('$2y$', $rawAttributes['password']);
        // Should verify correctly
        $this->assertTrue(password_verify('secret123', $rawAttributes['password']));
    }

    public function testHashedCastPreservesAlreadyHashedValues(): void
    {
        $hasher = new BcryptHasher(['rounds' => 4]);
        $existingHash = $hasher->make('secret123');

        $model = new HashedModel();
        $model->password = $existingHash;

        $rawAttributes = $model->getAttributes();

        // Should keep the existing hash unchanged
        $this->assertSame($existingHash, $rawAttributes['password']);
    }

    /**
     * Note: Testing verifyConfiguration failure would require mocking the Hash facade
     * with a specific configuration. The verifyConfiguration logic is tested separately
     * in HasherTest. Here we just verify the cast works with valid hashes.
     */
    public function testHashedCastWorksWithValidExistingHash(): void
    {
        // Create a hash with the default hasher (bcrypt cost 10)
        $hasher = new BcryptHasher(['rounds' => 10]);
        $existingHash = $hasher->make('secret123');

        $model = new HashedModel();
        $model->password = $existingHash;

        $rawAttributes = $model->getAttributes();

        // Should keep the existing hash unchanged (since it's already hashed with valid config)
        $this->assertSame($existingHash, $rawAttributes['password']);
    }

    public function testEncryptedCastHandlesNullOnSet(): void
    {
        $model = new EncryptedModel();
        $model->secret = null;

        $rawAttributes = $model->getAttributes();

        // Null should remain null, not be encrypted
        $this->assertNull($rawAttributes['secret']);
    }

    public function testEncryptedCastHandlesNullOnGet(): void
    {
        $model = new EncryptedModel();
        $model->setRawAttributes([
            'secret' => null,
        ]);

        // Null should remain null, not throw decryption error
        $this->assertNull($model->secret);
    }

    public function testHashedCastHandlesNullOnSet(): void
    {
        $model = new HashedModel();
        $model->password = null;

        $rawAttributes = $model->getAttributes();

        // Null should remain null, not be hashed
        $this->assertNull($rawAttributes['password']);
    }

    public function testEncryptUsingAllowsCustomEncrypter(): void
    {
        // Create a different encrypter with a different key
        $customKey = str_repeat('b', 16);
        $customEncrypter = new Encrypter($customKey);

        // Set it on the model class
        EncryptedModel::encryptUsing($customEncrypter);

        $model = new EncryptedModel();
        $model->secret = 'custom-encrypted-value';

        // Verify the value was encrypted
        $rawAttributes = $model->getAttributes();
        $this->assertNotSame('custom-encrypted-value', $rawAttributes['secret']);

        // Verify it can be decrypted with the custom encrypter
        $decrypted = $customEncrypter->decrypt($rawAttributes['secret'], false);
        $this->assertSame('custom-encrypted-value', $decrypted);

        // Verify the model getter also decrypts correctly
        $this->assertSame('custom-encrypted-value', $model->secret);
    }

    public function testCurrentEncrypterReturnsCustomEncrypterWhenSet(): void
    {
        $customEncrypter = new Encrypter(str_repeat('c', 16));
        EncryptedModel::encryptUsing($customEncrypter);

        $this->assertSame($customEncrypter, EncryptedModel::currentEncrypter());
    }

    public function testEncryptUsingCanBeResetAndNewEncrypterSet(): void
    {
        $firstEncrypter = new Encrypter(str_repeat('d', 16));
        $secondEncrypter = new Encrypter(str_repeat('e', 16));

        // Set first encrypter
        EncryptedModel::encryptUsing($firstEncrypter);
        $this->assertSame($firstEncrypter, EncryptedModel::currentEncrypter());

        // Reset and set second encrypter
        EncryptedModel::encryptUsing(null);
        EncryptedModel::encryptUsing($secondEncrypter);

        // Should now use second encrypter
        $this->assertSame($secondEncrypter, EncryptedModel::currentEncrypter());
        $this->assertNotSame($firstEncrypter, EncryptedModel::currentEncrypter());
    }
}

class ImmutableDateModel extends Model
{
    use HasUuids;

    protected ?string $table = 'test_models';

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'formatted_at' => 'immutable_datetime:d/m/Y',
        ];
    }
}

class JsonUnicodeModel extends Model
{
    use HasUuids;

    protected ?string $table = 'test_models';

    protected function casts(): array
    {
        return [
            'data' => 'json:unicode',
        ];
    }
}

class EncryptedModel extends Model
{
    use HasUuids;

    protected ?string $table = 'test_models';

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'secret_array' => 'encrypted:array',
            'secret_collection' => 'encrypted:collection',
            'secret_json' => 'encrypted:json',
            'secret_object' => 'encrypted:object',
        ];
    }
}

class HashedModel extends Model
{
    use HasUuids;

    protected ?string $table = 'test_models';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
