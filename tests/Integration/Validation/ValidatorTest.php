<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\ValidatorTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\Validator;

/**
 * @internal
 * @coversNothing
 */
class ValidatorTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid');
            $table->string('first_name');
        });

        User::create(['uuid' => (string) Str::uuid(), 'first_name' => 'John']);
        User::create(['uuid' => (string) Str::uuid(), 'first_name' => 'Jim']);
    }

    public function testExists(): void
    {
        $validator = $this->getValidator(['first_name' => ['John', 'Taylor']], ['first_name' => 'exists:users']);
        $this->assertFalse($validator->passes());
    }

    public function testUnique(): void
    {
        $validator = $this->getValidator(['first_name' => 'John'], ['first_name' => 'unique:' . User::class]);
        $this->assertFalse($validator->passes());

        $validator = $this->getValidator(['first_name' => 'John'], ['first_name' => 'unique:' . User::class . ',first_name,1']);
        $this->assertTrue($validator->passes());

        $validator = $this->getValidator(['first_name' => 'Taylor'], ['first_name' => 'unique:' . User::class]);
        $this->assertTrue($validator->passes());
    }

    public function testUniqueWithCustomModelKey(): void
    {
        $_SERVER['CUSTOM_MODEL_KEY_NAME'] = 'uuid';

        $validator = $this->getValidator(['first_name' => 'John'], ['first_name' => 'unique:' . UserWithUuid::class]);
        $this->assertFalse($validator->passes());

        $user = UserWithUuid::where('first_name', 'John')->first();

        $validator = $this->getValidator(['first_name' => 'John'], ['first_name' => 'unique:' . UserWithUuid::class . ',first_name,' . $user->uuid]);
        $this->assertTrue($validator->passes());

        $validator = $this->getValidator(['first_name' => 'John'], ['first_name' => 'unique:users,first_name,' . $user->uuid . ',uuid']);
        $this->assertTrue($validator->passes());

        $validator = $this->getValidator(['first_name' => 'John'], ['first_name' => 'unique:users,first_name,' . $user->uuid . ',id']);
        $this->assertFalse($validator->passes());

        $validator = $this->getValidator(['first_name' => 'Taylor'], ['first_name' => 'unique:' . UserWithUuid::class]);
        $this->assertTrue($validator->passes());

        unset($_SERVER['CUSTOM_MODEL_KEY_NAME']);
    }

    public function testImplicitAttributeFormatting(): void
    {
        $translator = new Translator(new ArrayLoader(), 'en');
        $translator->addLines(['validation.string' => ':attribute must be a string!'], 'en');
        $validator = new Validator($translator, [['name' => 1]], ['*.name' => 'string']);

        $validator->setImplicitAttributesFormatter(function ($attribute) {
            [$line, $attribute] = explode('.', $attribute);

            return sprintf('%s at line %d', $attribute, $line + 1);
        });

        $validator->passes();

        $this->assertSame('name at line 1 must be a string!', $validator->getMessageBag()->all()[0]);
    }

    protected function getValidator(array $data, array $rules)
    {
        $translator = new Translator(new ArrayLoader(), 'en');
        $validator = new Validator($translator, $data, $rules);
        $validator->setPresenceVerifier(new DatabasePresenceVerifier($this->app->make('db')));

        return $validator;
    }
}

class User extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];
}

class UserWithUuid extends Model
{
    protected ?string $table = 'users';

    public bool $timestamps = false;

    protected array $guarded = [];

    protected string $keyType = 'string';

    public bool $incrementing = false;

    public function getKeyName(): string
    {
        return 'uuid';
    }
}
