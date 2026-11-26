<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\ValidatorTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Validator;

class ValidatorTest extends DatabaseTestCase
{
    /**
     * Create the validation fixtures after the database has been refreshed.
     */
    protected function afterRefreshingDatabase(): void
    {
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

    public function testExistsAndUniqueCompareJsonPathValuesAsStrings(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->json('meta');
        });

        Profile::create(['meta' => ['external_id' => 7, 'code' => '007']]);

        foreach ([7, '7', [7], ['7']] as $value) {
            $validator = $this->getValidator(['value' => $value], ['value' => 'exists:profiles,meta->external_id']);
            $this->assertTrue($validator->passes());
        }

        $validator = $this->getValidator(['value' => [7, 8]], ['value' => 'exists:profiles,meta->external_id']);
        $this->assertFalse($validator->passes());

        $validator = $this->getValidator(['value' => 7], ['value' => 'exists:profiles,meta->code']);
        $this->assertFalse($validator->passes());

        $validator = $this->getValidator(['value' => 7], ['value' => 'unique:profiles,meta->external_id']);
        $this->assertFalse($validator->passes());

        $validator = $this->getValidator(['value' => 8], ['value' => 'unique:profiles,meta->external_id']);
        $this->assertTrue($validator->passes());

        Profile::create(['meta' => ['external_id' => '7']]);

        $validator = $this->getValidator(['value' => [7, 8]], ['value' => 'exists:profiles,meta->external_id']);
        $this->assertFalse($validator->passes());
    }

    public function testJsonPathConditionsAndExclusionsCompareAsStrings(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->json('meta');
        });

        $profile = Profile::create(['meta' => ['id' => 5, 'tenant' => 7]]);

        $this->assertFalse($this->getValidator(['value' => $profile->id], ['value' => Rule::unique('profiles', 'id')->where('meta->tenant', 7)])->passes());
        $this->assertTrue($this->getValidator(['value' => $profile->id], ['value' => Rule::exists('profiles', 'id')->where('meta->tenant', 7)])->passes());
        $this->assertFalse($this->getValidator(['value' => $profile->id], ['value' => Rule::exists('profiles', 'id')->whereNot('meta->tenant', 7)])->passes());
        $this->assertTrue($this->getValidator(['value' => $profile->id], ['value' => Rule::exists('profiles', 'id')->whereIn('meta->tenant', ['7'])])->passes());
        $this->assertFalse($this->getValidator(['value' => $profile->id], ['value' => Rule::exists('profiles', 'id')->whereNotIn('meta->tenant', ['7'])])->passes());
        $this->assertTrue($this->getValidator(['value' => $profile->id], ['value' => Rule::unique('profiles', 'id')->ignore(5, 'meta->id')])->passes());
    }

    #[RequiresDatabase('sqlite')]
    public function testJsonTextComparisonsKeepBooleansRealsAndNullMembersDistinct(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->json('meta');
        });

        // Insert raw JSON, since json_encode() writes 7.0 as 7.
        DB::table('profiles')->insert([['meta' => '{"flag":true,"real":7.0,"price":7.5}'], ['meta' => '{"flag":1.0,"big":-9223372036854775809}']]);

        $this->assertFalse($this->getValidator(['value' => '1'], ['value' => 'exists:profiles,meta->flag'])->passes());
        $this->assertTrue($this->getValidator(['value' => true], ['value' => 'exists:profiles,meta->flag'])->passes());
        $this->assertFalse($this->getValidator(['value' => [true, false]], ['value' => 'exists:profiles,meta->flag'])->passes());
        $this->assertFalse($this->getValidator(['value' => 7], ['value' => 'exists:profiles,meta->real'])->passes());
        $this->assertFalse($this->getValidator(['value' => PHP_INT_MIN], ['value' => 'exists:profiles,meta->big'])->passes());
        $this->assertTrue($this->getValidator(['value' => '7.5'], ['value' => 'exists:profiles,meta->price'])->passes());
        $this->assertTrue($this->getValidator(['value' => '1'], ['value' => Rule::exists('profiles', 'id')->whereNotIn('meta->flag', ['1'])])->passes());
        $this->assertFalse($this->getValidator(['value' => '1'], ['value' => Rule::exists('profiles', 'id')->whereNotIn('meta->flag', ['1', null])])->passes());

        Profile::create(['meta' => ['flag' => 1]]);
        Profile::create(['meta' => ['flag' => '1']]);

        $this->assertTrue($this->getValidator(['value' => ['1']], ['value' => 'exists:profiles,meta->flag'])->passes());
        $this->assertFalse($this->getValidator(['value' => '1'], ['value' => 'unique:profiles,meta->flag'])->passes());
    }

    #[RequiresDatabase('sqlite')]
    public function testJsonPathCandidatesBindOnceEach(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->json('meta');
        });

        DB::enableQueryLog();
        $this->getValidator(['value' => [7, '8', 'abc']], ['value' => 'exists:profiles,meta->external_id'])->passes();

        // Long candidate lists must stay within SQLite's bound-variable limit.
        $this->assertSame(['7', '8', 'abc'], DB::getQueryLog()[0]['bindings']);
    }

    public function testUniqueWithCustomModelKey(): void
    {
        $validator = $this->getValidator(['first_name' => 'John'], ['first_name' => 'unique:' . UserWithUuid::class]);
        $this->assertFalse($validator->passes());

        $user = UserWithUuid::where('first_name', 'John')->first();

        $validator = $this->getValidator(['first_name' => 'John'], ['first_name' => 'unique:' . UserWithUuid::class . ',first_name,' . $user->uuid]);
        $this->assertTrue($validator->passes());

        $validator = $this->getValidator(['first_name' => 'John'], ['first_name' => 'unique:users,first_name,' . $user->uuid . ',uuid']);
        $this->assertTrue($validator->passes());

        // Keep the wrong-column check independent of database-specific UUID-to-integer coercion.
        $validator = $this->getValidator(['first_name' => 'John'], ['first_name' => 'unique:users,first_name,' . $user->uuid . ',first_name']);
        $this->assertFalse($validator->passes());

        $validator = $this->getValidator(['first_name' => 'Taylor'], ['first_name' => 'unique:' . UserWithUuid::class]);
        $this->assertTrue($validator->passes());
    }

    public function testImplicitAttributeFormatting(): void
    {
        $translator = new Translator(new ArrayLoader, 'en');
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
        $translator = new Translator(new ArrayLoader, 'en');
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

class Profile extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    protected array $casts = ['meta' => 'array'];
}
