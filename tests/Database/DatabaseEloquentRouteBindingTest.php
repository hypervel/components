<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Capsule\Manager as Capsule;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

class DatabaseEloquentRouteBindingTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('invalidIntegerKeyProvider')]
    public function testMalformedIntegerKeysProduceAnEmptyPredicate(mixed $value): void
    {
        $model = new RouteBindingIntKeyModel;
        $query = (new Builder((new SQLiteConnection(new PDO('sqlite::memory:')))->query()))->setModel($model);
        $binding = $model->resolveRouteBindingQuery($query, $value);

        $this->assertStringContainsString('where 0 = 1', $binding->toSql());
        $this->assertSame([], $binding->getBindings());
    }

    /**
     * Provide values that cannot address an integer key.
     */
    public static function invalidIntegerKeyProvider(): array
    {
        return [
            'null' => [null],
            'letters' => ['abc'],
            'trailing letters' => ['12abc'],
            'leading letters' => ['abc12'],
            'thousands separator' => ['1,234'],
            'decimal' => ['1.5'],
            'hex' => ['0x1A'],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'beyond php int range' => ['99999999999999999999'],
            'repeated identifier' => ['12341234123412341234'],
            'negative beyond range' => ['-99999999999999999999'],
        ];
    }

    #[DataProvider('validIntegerKeyProvider')]
    public function testItResolvesValuesTheDatabaseCanStillMatch(int|string $value): void
    {
        $database = $this->createLabelsDatabase();
        foreach ([12, 0, -5, 5, PHP_INT_MAX] as $key) {
            $database->getConnection()->table('labels')->insert(['id' => $key]);
        }

        $model = (new RouteBindingIntKeyModel)->setTable('labels');
        $this->assertSame((int) $value, $model->resolveRouteBinding($value)?->getKey());
    }

    /**
     * Provide values the database can still match against an integer key.
     */
    public static function validIntegerKeyProvider(): array
    {
        return [
            'numeric string' => ['12'],
            'integer' => [12],
            'leading zeros' => ['0012'],
            'surrounding whitespace' => [' 12 '],
            'zero' => ['0'],
            'padded zero' => ['000'],
            'negative' => ['-5'],
            'signed positive' => ['+5'],
            'php int max' => [(string) PHP_INT_MAX],
        ];
    }

    #[TestWith(['int'])]
    #[TestWith(['integer'])]
    public function testItValidatesQualifiedKeyColumnsFromChildBindings(string $keyType): void
    {
        $model = (new RouteBindingIntKeyModel)->setKeyType($keyType);
        $query = (new Builder((new SQLiteConnection(new PDO('sqlite::memory:')))->query()))->setModel($model);
        $binding = $model->resolveRouteBindingQuery($query, 'abc', 'route_binding_int_key_models.id');

        $this->assertStringContainsString('where 0 = 1', $binding->toSql());
        $this->assertSame([], $binding->getBindings());
    }

    public function testItDoesNotValidateNonKeyBindingFields(): void
    {
        $query = Double::for(Builder::class)->strict();
        $query->expects('where')->with('slug', 'abc');

        (new RouteBindingIntKeyModel)->resolveRouteBindingQuery($query, 'abc', 'slug');
    }

    public function testItDoesNotValidateModelsWithStringKeys(): void
    {
        $query = Double::for(Builder::class)->strict();
        $query->expects('where')->with('id', 'abc');

        (new RouteBindingStringKeyModel)->resolveRouteBindingQuery($query, 'abc');
    }

    public function testItDoesNotValidateNullValuesForModelsWithStringKeys(): void
    {
        $query = Double::for(Builder::class)->strict();
        $query->expects('where')->with('id', null);

        (new RouteBindingStringKeyModel)->resolveRouteBindingQuery($query, null);
    }

    public function testMalformedIntegerKeysAllowFallbackAndComposableBindings(): void
    {
        $database = $this->createLabelsDatabase();
        $database->getConnection()->table('labels')->insert(['id' => 12, 'slug' => 'green']);

        foreach ([new RouteBindingIntKeyModel, new RouteBindingNonIncrementingKeyModel] as $model) {
            $model->setTable('labels');
            $this->assertNull($model->resolveRouteBinding('12abc'));
            $this->assertNull($model->resolveRouteBinding('99999999999999999999'));
            $this->assertSame(12, $model->resolveRouteBindingQuery($model, 'green')->orWhere('slug', 'green')->firstOrFail()->getKey());
        }

        $this->assertSame(12, (new RouteBindingFallbackModel)->resolveRouteBinding('green')->getKey());
    }

    /**
     * Create the SQLite table used by the route binding models.
     */
    private function createLabelsDatabase(): Capsule
    {
        $database = new Capsule;
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->bootEloquent();
        $database->getConnection()->statement('create table labels (id integer primary key, slug text)');

        return $database;
    }

    /**
     * @param class-string<Model> $model
     */
    #[DataProvider('nullRouteKeyModelProvider')]
    public function testNullValuesCarryNoIdentifiers(string $model): void
    {
        $query = Double::for(Builder::class)->strict();
        $query->expects('where')->never();

        try {
            (new $model)->resolveRouteBindingQuery($query, null);
        } catch (ModelNotFoundException $e) {
            $this->assertSame([], $e->getIds());
            $this->assertSame("No query results for model [{$model}].", $e->getMessage());

            return;
        }

        $this->fail('No exception was thrown.');
    }

    /**
     * Provide models whose route key rejects null values.
     */
    public static function nullRouteKeyModelProvider(): array
    {
        return [
            'uuid key' => [RouteBindingUuidKeyModel::class],
            'ulid key' => [RouteBindingUlidKeyModel::class],
        ];
    }

    public function testItValidatesQualifiedUniqueIdColumnsFromChildBindings(): void
    {
        $query = Double::for(Builder::class)->strict();
        $query->expects('where')->never();

        $this->expectException(ModelNotFoundException::class);

        (new RouteBindingUuidKeyModel)->resolveRouteBindingQuery($query, 'abc', 'route_binding_uuid_key_models.id');
    }

    public function testItPassesQualifiedUniqueIdColumnsThroughForValidIds(): void
    {
        $uuid = '0190ecbd-0aa7-7cc8-9d51-4b4f6fb3a3c7';

        $query = Double::for(Builder::class)->strict();
        $query->expects('where')->with('route_binding_uuid_key_models.id', $uuid);

        $this->assertSame($query, (new RouteBindingUuidKeyModel)->resolveRouteBindingQuery($query, $uuid, 'route_binding_uuid_key_models.id'));
    }
}

class RouteBindingIntKeyModel extends Model
{
}

class RouteBindingUuidKeyModel extends Model
{
    use HasUuids;
}

class RouteBindingUlidKeyModel extends Model
{
    use HasUlids;
}

class RouteBindingStringKeyModel extends Model
{
    protected string $keyType = 'string';
}

class RouteBindingNonIncrementingKeyModel extends Model
{
    public bool $incrementing = false;
}

class RouteBindingFallbackModel extends Model
{
    protected ?string $table = 'labels';

    /**
     * Fall back to the slug when the primary key does not match.
     */
    public function resolveRouteBinding(mixed $value, ?string $field = null): ?Model
    {
        return parent::resolveRouteBinding($value, $field) ?? $this->newQuery()->where('slug', $value)->first();
    }
}
