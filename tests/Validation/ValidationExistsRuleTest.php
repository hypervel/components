<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use UnitEnum;

class ValidationExistsRuleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/Fixtures/migrations',
        ];
    }

    public function testItCorrectlyFormatsAStringVersionOfTheRule(): void
    {
        $rule = new Exists('table');
        $rule->where('foo', 'bar');
        $this->assertSame('exists:table,NULL,foo,"bar"', (string) $rule);

        $rule = (new Exists('table', 'id'))
            ->where('data->active', false)
            ->whereNot('data->hidden', false);
        $this->assertSame('exists:table,id,data->active,"0",data->hidden,"!0"', (string) $rule);

        $rule = new Exists(User::class);
        $rule->where('foo', 'bar');
        $this->assertSame('exists:table,NULL,foo,"bar"', (string) $rule);

        $rule = new Exists(UserWithPrefixedTable::class);
        $rule->where('foo', 'bar');
        $this->assertSame('exists:' . UserWithPrefixedTable::class . ',NULL,foo,"bar"', (string) $rule);

        $rule = new Exists('table', 'column');
        $rule->where('foo', 'bar');
        $this->assertSame('exists:table,column,foo,"bar"', (string) $rule);

        $rule = new Exists(User::class, 'column');
        $rule->where('foo', 'bar');
        $this->assertSame('exists:table,column,foo,"bar"', (string) $rule);

        $rule = new Exists(UserWithConnection::class, 'column');
        $rule->where('foo', 'bar');
        $this->assertSame('exists:mysql.table,column,foo,"bar"', (string) $rule);

        $rule = new Exists('Hypervel\Tests\Validation\User', 'column');
        $rule->where('foo', 'bar');
        $this->assertSame('exists:table,column,foo,"bar"', (string) $rule);

        $rule = new Exists(NoTableNameModel::class, 'column');
        $rule->where('foo', 'bar');
        $this->assertSame('exists:no_table_name_models,column,foo,"bar"', (string) $rule);

        $rule = new Exists(ClassWithRequiredConstructorParameters::class, 'column');
        $rule->where('foo', 'bar');
        $this->assertSame('exists:' . ClassWithRequiredConstructorParameters::class . ',column,foo,"bar"', (string) $rule);
    }

    public function testItChoosesValidRecordsUsingWhereInRule(): void
    {
        $rule = new Exists('table', 'id_column');
        $rule->whereIn('type', ['foo', 'bar']);

        User::create(['id_column' => '1', 'type' => 'foo']);
        User::create(['id_column' => '2', 'type' => 'bar']);
        User::create(['id_column' => '3', 'type' => 'baz']);
        User::create(['id_column' => '4', 'type' => 'other']);

        $trans = $this->getArrayTranslator();
        $v = new Validator($trans, [], ['id_column' => $rule]);
        $v->setPresenceVerifier(new DatabasePresenceVerifier(
            $this->app->make('db')
        ));

        $v->setData(['id_column' => 1]);
        $this->assertTrue($v->passes());
        $v->setData(['id_column' => 2]);
        $this->assertTrue($v->passes());
        $v->setData(['id_column' => 3]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => 4]);
        $this->assertFalse($v->passes());

        // array values
        $v->setData(['id_column' => [1, 2]]);
        $this->assertTrue($v->passes());
        $v->setData(['id_column' => [3, 4]]);
        $this->assertFalse($v->passes());
    }

    public function testItChoosesValidRecordsUsingWhereNotInRule(): void
    {
        $rule = new Exists('table', 'id_column');
        $rule->whereNotIn('type', ['foo', 'bar']);

        User::create(['id_column' => '1', 'type' => 'foo']);
        User::create(['id_column' => '2', 'type' => 'bar']);
        User::create(['id_column' => '3', 'type' => 'baz']);
        User::create(['id_column' => '4', 'type' => 'other']);

        $trans = $this->getArrayTranslator();
        $v = new Validator($trans, [], ['id_column' => $rule]);
        $v->setPresenceVerifier(new DatabasePresenceVerifier(
            $this->app->make('db')
        ));

        $v->setData(['id_column' => 1]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => 2]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => 3]);
        $this->assertTrue($v->passes());
        $v->setData(['id_column' => 4]);
        $this->assertTrue($v->passes());

        // array values
        $v->setData(['id_column' => [1, 2]]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => [3, 4]]);
        $this->assertTrue($v->passes());
    }

    public function testItChoosesValidRecordsUsingConditionalModifiers(): void
    {
        $rule = new Exists('table', 'id_column');
        $rule->when(true, function ($rule) {
            $rule->whereNotIn('type', ['foo', 'bar']);
        });
        $rule->unless(true, function ($rule) {
            $rule->whereNotIn('type', ['baz', 'other']);
        });

        User::create(['id_column' => '1', 'type' => 'foo']);
        User::create(['id_column' => '2', 'type' => 'bar']);
        User::create(['id_column' => '3', 'type' => 'baz']);
        User::create(['id_column' => '4', 'type' => 'other']);

        $trans = $this->getArrayTranslator();
        $v = new Validator($trans, [], ['id_column' => $rule]);
        $v->setPresenceVerifier(new DatabasePresenceVerifier(
            $this->app->make('db')
        ));

        $v->setData(['id_column' => 1]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => 2]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => 3]);
        $this->assertTrue($v->passes());
        $v->setData(['id_column' => 4]);
        $this->assertTrue($v->passes());

        // array values
        $v->setData(['id_column' => [1, 2]]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => [3, 4]]);
        $this->assertTrue($v->passes());
    }

    public function testItChoosesValidRecordsUsingWhereNotInAndWhereNotInRulesTogether(): void
    {
        $rule = new Exists('table', 'id_column');
        $rule->whereIn('type', ['foo', 'bar', 'baz'])->whereNotIn('type', ['foo', 'bar']);

        User::create(['id_column' => '1', 'type' => 'foo']);
        User::create(['id_column' => '2', 'type' => 'bar']);
        User::create(['id_column' => '3', 'type' => 'baz']);
        User::create(['id_column' => '4', 'type' => 'other']);
        User::create(['id_column' => '5', 'type' => 'baz']);

        $trans = $this->getArrayTranslator();
        $v = new Validator($trans, [], ['id_column' => $rule]);
        $v->setPresenceVerifier(new DatabasePresenceVerifier(
            $this->app->make('db')
        ));

        $v->setData(['id_column' => 1]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => 2]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => 3]);
        $this->assertTrue($v->passes());
        $v->setData(['id_column' => 4]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => 5]);
        $this->assertTrue($v->passes());

        // array values
        $v->setData(['id_column' => [1, 2, 4]]);
        $this->assertFalse($v->passes());
        $v->setData(['id_column' => [3, 5]]);
        $this->assertTrue($v->passes());
    }

    public function testItChoosesValidRecordsUsingWhereNotRule(): void
    {
        $rule = new Exists('table', 'id_column');

        $rule->whereNot('type', 'baz');

        User::create(['id_column' => '1', 'type' => 'foo']);
        User::create(['id_column' => '2', 'type' => 'bar']);
        User::create(['id_column' => '3', 'type' => 'baz']);
        User::create(['id_column' => '4', 'type' => 'other']);
        User::create(['id_column' => '5', 'type' => 'baz']);

        $trans = $this->getArrayTranslator();
        $v = new Validator($trans, [], ['id_column' => $rule]);
        $v->setPresenceVerifier(new DatabasePresenceVerifier(
            $this->app->make('db')
        ));

        $v->setData(['id_column' => 3]);
        $this->assertFalse($v->passes());

        $v->setData(['id_column' => 4]);
        $this->assertTrue($v->passes());
    }

    public function testItAppliesEveryWhereNotOnTheSameColumn(): void
    {
        $rule = new Exists('table', 'id_column');

        $rule->whereNot('type', 'foo')->whereNot('type', 'bar');

        User::create(['id_column' => '1', 'type' => 'foo']);
        User::create(['id_column' => '2', 'type' => 'bar']);
        User::create(['id_column' => '3', 'type' => 'baz']);

        $trans = $this->getArrayTranslator();
        $v = new Validator($trans, [], ['id_column' => $rule]);
        $v->setPresenceVerifier(new DatabasePresenceVerifier($this->app->make('db')));

        $v->setData(['id_column' => 1]);
        $this->assertFalse($v->passes());

        $v->setData(['id_column' => 2]);
        $this->assertFalse($v->passes());

        $v->setData(['id_column' => 3]);
        $this->assertTrue($v->passes());
    }

    public function testStringRuleAppliesEveryConditionOnTheSameColumn(): void
    {
        User::create(['id_column' => '1', 'type' => 'foo']);
        User::create(['id_column' => '2', 'type' => 'bar']);
        User::create(['id_column' => '3', 'type' => 'baz']);

        $trans = $this->getArrayTranslator();
        $v = new Validator($trans, [], ['id_column' => 'exists:table,id_column,type,!foo,type,!bar']);
        $v->setPresenceVerifier(new DatabasePresenceVerifier($this->app->make('db')));

        $v->setData(['id_column' => 1]);
        $this->assertFalse($v->passes());

        $v->setData(['id_column' => 2]);
        $this->assertFalse($v->passes());

        $v->setData(['id_column' => 3]);
        $this->assertTrue($v->passes());
    }

    public function testRepeatedArrayRuleConditionsAcceptNumericValues(): void
    {
        User::create(['id_column' => '1', 'type' => '0']);

        $validator = new Validator($this->getArrayTranslator(), ['id_column' => 1], [
            'id_column' => [['exists', 'table', 'id_column', 'type', 0, 'type', 0]],
        ]);
        $validator->setPresenceVerifier(new DatabasePresenceVerifier($this->app->make('db')));

        $this->assertTrue($validator->passes());
    }

    #[DataProvider('literalWhereValuesProvider')]
    public function testWhereComparesValuesLiterally(Exists $rule, int $id, bool $passes): void
    {
        User::create(['id_column' => '1', 'type' => 'foo']);
        User::create(['id_column' => '2', 'type' => 'NOT_NULL']);

        $v = new Validator($this->getArrayTranslator(), ['id_column' => $id], ['id_column' => $rule]);
        $v->setPresenceVerifier(new DatabasePresenceVerifier($this->app->make('db')));

        $this->assertSame($passes, $v->passes());
    }

    public static function literalWhereValuesProvider(): array
    {
        $breakout = 'x\",id_column,NOT_NULL,type,"';

        return [
            'NOT_NULL is not "is not null"' => [(new Exists('table', 'id_column'))->where('type', 'NOT_NULL'), 1, false],
            'NOT_NULL matches itself' => [(new Exists('table', 'id_column'))->where('type', 'NOT_NULL'), 2, true],
            'leading ! is not "not equal"' => [(new Exists('table', 'id_column'))->where('type', '!bar'), 1, false],
            'Stringable NOT_NULL' => [(new Exists('table', 'id_column'))->where('type', Str::of('NOT_NULL')), 1, false],
            'backslash cannot override another where' => [(new Exists('table', 'id_column'))->where('id_column', 2)->where('type', $breakout . '!'), 1, false],
            'whereNot backslash cannot add wheres' => [(new Exists('table', 'id_column'))->whereNot('type', $breakout . 'foo'), 2, true],
        ];
    }

    public function testItIgnoresSoftDeletes(): void
    {
        $rule = new Exists('table');
        $rule->withoutTrashed();
        $this->assertSame('exists:table,NULL,deleted_at,"NULL"', (string) $rule);

        $rule = new Exists('table');
        $rule->withoutTrashed('softdeleted_at');
        $this->assertSame('exists:table,NULL,softdeleted_at,"NULL"', (string) $rule);
    }

    public function testItOnlyTrashedSoftDeletes(): void
    {
        $rule = new Exists('table');
        $rule->onlyTrashed();
        $this->assertSame('exists:table,NULL,deleted_at,"NOT_NULL"', (string) $rule);

        $rule = new Exists('table');
        $rule->onlyTrashed('softdeleted_at');
        $this->assertSame('exists:table,NULL,softdeleted_at,"NOT_NULL"', (string) $rule);
    }

    public function testItIsAPartOfListRules(): void
    {
        $rule = new Exists('table', 'id_column');

        User::create(['id_column' => '1', 'type' => 'foo']);
        User::create(['id_column' => '2', 'type' => 'bar']);
        User::create(['id_column' => '3', 'type' => 'baz']);

        $trans = $this->getArrayTranslator();
        $v = new Validator($trans, [], ['id_column' => ['required', $rule]]);
        $v->setPresenceVerifier(new DatabasePresenceVerifier(
            $this->app->make('db')
        ));

        $v->setData(['id_column' => 1]);
        $this->assertTrue($v->passes());
        $v->setData(['id_column' => 2]);
        $this->assertTrue($v->passes());
    }

    public function getArrayTranslator()
    {
        return new Translator(
            new ArrayLoader,
            'en'
        );
    }
}

/**
 * Eloquent Models.
 */
class User extends Eloquent
{
    protected ?string $table = 'table';

    protected array $guarded = [];

    public bool $timestamps = false;
}

class UserWithPrefixedTable extends Eloquent
{
    protected ?string $table = 'public.table';

    protected array $guarded = [];

    public bool $timestamps = false;
}

class UserWithConnection extends User
{
    protected UnitEnum|string|null $connection = 'mysql';
}

class NoTableNameModel extends Eloquent
{
    protected array $guarded = [];

    public bool $timestamps = false;
}

class ClassWithRequiredConstructorParameters
{
    private $bar;

    private $baz;

    public function __construct($bar, $baz)
    {
        $this->bar = $bar;
        $this->baz = $baz;
    }
}
