<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class DatabaseEloquentRouteBindingTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('invalidIntegerKeyProvider')]
    public function testItRejectsValuesThatCannotAddressAnIntegerKeyWithoutQuerying(mixed $value): void
    {
        $query = Double::for(Builder::class);
        $query->expects('where')->never();

        $this->expectException(ModelNotFoundException::class);

        (new RouteBindingIntKeyModel)->resolveRouteBindingQuery($query, $value);
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
    public function testItPassesThroughValuesTheDatabaseCanStillMatch(int|string $value): void
    {
        $query = Double::for(Builder::class);
        $query->expects('where')->with('id', $value);

        $this->assertSame($query, (new RouteBindingIntKeyModel)->resolveRouteBindingQuery($query, $value));
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
        $query = Double::for(Builder::class);
        $query->expects('where')->never();

        $this->expectException(ModelNotFoundException::class);

        (new RouteBindingIntKeyModel)->setKeyType($keyType)->resolveRouteBindingQuery($query, 'abc', 'route_binding_int_key_models.id');
    }

    public function testItDoesNotValidateNonKeyBindingFields(): void
    {
        $query = Double::for(Builder::class);
        $query->expects('where')->with('slug', 'abc');

        (new RouteBindingIntKeyModel)->resolveRouteBindingQuery($query, 'abc', 'slug');
    }

    public function testItDoesNotValidateModelsWithStringKeys(): void
    {
        $query = Double::for(Builder::class);
        $query->expects('where')->with('id', 'abc');

        (new RouteBindingStringKeyModel)->resolveRouteBindingQuery($query, 'abc');
    }

    // REMOVED: Laravel's testItDoesNotValidateNonIncrementingModelsWithTheDefaultKeyType.
    // Integer keys stay validated without auto-increment; string keys declare keyType 'string'.

    public function testItValidatesNonIncrementingModelsWithTheDefaultKeyType(): void
    {
        $query = Double::for(Builder::class);
        $query->expects('where')->never();

        $this->expectException(ModelNotFoundException::class);

        (new RouteBindingNonIncrementingKeyModel)->resolveRouteBindingQuery($query, '12abc');
    }

    public function testItDoesNotValidateNullValuesForModelsWithStringKeys(): void
    {
        $query = Double::for(Builder::class);
        $query->expects('where')->with('id', null);

        (new RouteBindingStringKeyModel)->resolveRouteBindingQuery($query, null);
    }

    public function testTheInvalidKeyHandlerMayBeOverridden(): void
    {
        $query = Double::for(Builder::class);
        $query->expects('where')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Invalid id: abc');

        (new RouteBindingCustomExceptionModel)->resolveRouteBindingQuery($query, 'abc');
    }

    public function testTheExceptionCarriesTheModelAndValue(): void
    {
        $query = Double::for(Builder::class);

        try {
            (new RouteBindingIntKeyModel)->resolveRouteBindingQuery($query, 'abc');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(RouteBindingIntKeyModel::class, $e->getModel());
            $this->assertSame(['abc'], $e->getIds());

            return;
        }

        $this->fail('No exception was thrown.');
    }

    /**
     * @param class-string<Model> $model
     */
    #[DataProvider('nullRouteKeyModelProvider')]
    public function testNullValuesCarryNoIdentifiers(string $model): void
    {
        $query = Double::for(Builder::class);
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
            'integer key' => [RouteBindingIntKeyModel::class],
            'uuid key' => [RouteBindingUuidKeyModel::class],
            'ulid key' => [RouteBindingUlidKeyModel::class],
        ];
    }

    public function testItValidatesQualifiedUniqueIdColumnsFromChildBindings(): void
    {
        $query = Double::for(Builder::class);
        $query->expects('where')->never();

        $this->expectException(ModelNotFoundException::class);

        (new RouteBindingUuidKeyModel)->resolveRouteBindingQuery($query, 'abc', 'route_binding_uuid_key_models.id');
    }

    public function testItPassesQualifiedUniqueIdColumnsThroughForValidIds(): void
    {
        $uuid = '0190ecbd-0aa7-7cc8-9d51-4b4f6fb3a3c7';

        $query = Double::for(Builder::class);
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

class RouteBindingCustomExceptionModel extends Model
{
    /**
     * Throw an exception for the given invalid route key.
     */
    protected function handleInvalidRouteKey(mixed $value, string $field): never
    {
        throw new RuntimeException("Invalid {$field}: {$value}");
    }
}
