<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Validator;

/**
 * @internal
 * @coversNothing
 */
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
            '--path' => __DIR__ . '/migrations',
        ];
    }

    public function testItCorrectlyFormatsAStringVersionOfTheRule()
    {
        $rule = new Exists('table');
        $rule->where('foo', 'bar');
        $this->assertSame('exists:table,NULL,foo,"bar"', (string) $rule);

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

    public function testItChoosesValidRecordsUsingWhereInRule()
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
            $this->app->get(ConnectionResolverInterface::class)
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

    public function testItChoosesValidRecordsUsingWhereNotInRule()
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
            $this->app->get(ConnectionResolverInterface::class)
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

    public function testItChoosesValidRecordsUsingConditionalModifiers()
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
            $this->app->get(ConnectionResolverInterface::class)
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

    public function testItChoosesValidRecordsUsingWhereNotInAndWhereNotInRulesTogether()
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
            $this->app->get(ConnectionResolverInterface::class)
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

    public function testItChoosesValidRecordsUsingWhereNotRule()
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
            $this->app->get(ConnectionResolverInterface::class)
        ));

        $v->setData(['id_column' => 3]);
        $this->assertFalse($v->passes());

        $v->setData(['id_column' => 4]);
        $this->assertTrue($v->passes());
    }

    public function testItIgnoresSoftDeletes()
    {
        $rule = new Exists('table');
        $rule->withoutTrashed();
        $this->assertSame('exists:table,NULL,deleted_at,"NULL"', (string) $rule);

        $rule = new Exists('table');
        $rule->withoutTrashed('softdeleted_at');
        $this->assertSame('exists:table,NULL,softdeleted_at,"NULL"', (string) $rule);
    }

    public function testItOnlyTrashedSoftDeletes()
    {
        $rule = new Exists('table');
        $rule->onlyTrashed();
        $this->assertSame('exists:table,NULL,deleted_at,"NOT_NULL"', (string) $rule);

        $rule = new Exists('table');
        $rule->onlyTrashed('softdeleted_at');
        $this->assertSame('exists:table,NULL,softdeleted_at,"NOT_NULL"', (string) $rule);
    }

    public function testItIsAPartOfListRules()
    {
        $rule = new Exists('table', 'id_column');

        User::create(['id_column' => '1', 'type' => 'foo']);
        User::create(['id_column' => '2', 'type' => 'bar']);
        User::create(['id_column' => '3', 'type' => 'baz']);

        $trans = $this->getArrayTranslator();
        $v = new Validator($trans, [], ['id_column' => ['required', $rule]]);
        $v->setPresenceVerifier(new DatabasePresenceVerifier(
            $this->app->get(ConnectionResolverInterface::class)
        ));

        $v->setData(['id_column' => 1]);
        $this->assertTrue($v->passes());
        $v->setData(['id_column' => 2]);
        $this->assertTrue($v->passes());
    }

    public function getArrayTranslator()
    {
        return new Translator(
            new ArrayLoader(),
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
    protected \UnitEnum|string|null $connection = 'mysql';
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
