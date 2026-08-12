<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\BindingResolverTest;

use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Builder;
use Hypervel\Testbench\TestCase;
use Hypervel\Wayfinder\BindingResolver;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

class BindingResolverTest extends TestCase
{
    #[DataProvider('castTypes')]
    public function testPrimitiveCastEvidenceTakesPrecedence(string $cast, array $expected): void
    {
        $this->bindModel(['route_key' => $cast]);

        $this->assertSame(
            [$expected, 'route_key'],
            BindingResolver::resolveTypesAndKey(BindingResolverModel::class, 'route_key'),
        );
        $this->assertSame(0, BindingResolverModel::$connectionResolutions);
    }

    public static function castTypes(): array
    {
        return [
            'decimal arguments remain strings' => ['decimal:2', ['string']],
            'real numbers' => ['real', ['number']],
            'booleans' => ['boolean', ['boolean']],
        ];
    }

    #[DataProvider('schemaTypes')]
    public function testSchemaEvidenceUsesTruthfulPrimitiveTypes(string $type, array $expected): void
    {
        $this->bindModel([], [['name' => 'route_key', 'type_name' => $type]]);

        $this->assertSame(
            [$expected, 'route_key'],
            BindingResolver::resolveTypesAndKey(BindingResolverModel::class, 'route_key'),
        );
    }

    public static function schemaTypes(): array
    {
        return [
            'bare bigint remains numeric' => ['bigint', ['number']],
            'decimal columns remain strings' => ['decimal', ['string']],
        ];
    }

    public function testPhpDocEvidenceFillsFieldsMissingFromTheSchema(): void
    {
        $this->bindModel([], [['name' => 'other', 'type_name' => 'integer']]);

        $this->assertSame(
            [['boolean'], 'active'],
            BindingResolver::resolveTypesAndKey(BindingResolverModel::class, 'active'),
        );
        $this->assertSame(
            [['number', 'string'], 'reference'],
            BindingResolver::resolveTypesAndKey(BindingResolverModel::class, 'reference'),
        );
    }

    public function testNonIncrementingStringKeyWithoutCastRemainsSchemaDriven(): void
    {
        NonIncrementingBindingResolverModel::$configuredConnection = $this->mockConnection(
            'external_binding_models',
            [['name' => 'external_id', 'type_name' => 'varchar']],
        );
        $this->app->instance(
            NonIncrementingBindingResolverModel::class,
            new NonIncrementingBindingResolverModel,
        );

        $this->assertSame(
            [['string'], 'external_id'],
            BindingResolver::resolveTypesAndKey(NonIncrementingBindingResolverModel::class, null),
        );
    }

    public function testFlushStateClearsEveryMetadataCache(): void
    {
        $this->bindModel([], []);

        BindingResolver::resolveTypesAndKey(BindingResolverModel::class, 'active');

        $properties = (new ReflectionClass(BindingResolver::class))->getStaticProperties();

        $this->assertNotEmpty($properties['booted']);
        $this->assertNotEmpty($properties['columns']);
        $this->assertNotEmpty($properties['docBlocks']);
        $this->assertNotNull($properties['docParser']);
        $this->assertNotNull($properties['lexer']);

        BindingResolver::flushState();

        $properties = (new ReflectionClass(BindingResolver::class))->getStaticProperties();

        $this->assertSame([], $properties['booted']);
        $this->assertSame([], $properties['columns']);
        $this->assertSame([], $properties['docBlocks']);
        $this->assertNull($properties['docParser']);
        $this->assertNull($properties['lexer']);
    }

    private function bindModel(array $casts, ?array $columns = null): void
    {
        BindingResolverModel::$configuredCasts = $casts;
        BindingResolverModel::$configuredConnection = null;
        BindingResolverModel::$connectionResolutions = 0;

        if ($columns !== null) {
            BindingResolverModel::$configuredConnection = $this->mockConnection(
                'binding_resolver_models',
                $columns,
            );
        }

        $this->app->instance(BindingResolverModel::class, new BindingResolverModel);
    }

    private function mockConnection(string $table, array $columns): Connection
    {
        $schema = m::mock(Builder::class);
        $schema->shouldReceive('getColumns')->once()->with($table)->andReturn($columns);

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);

        return $connection;
    }
}

/**
 * @property bool $active
 * @property null|int|string $reference
 */
class BindingResolverModel extends Model
{
    public static array $configuredCasts = [];

    public static ?Connection $configuredConnection = null;

    public static int $connectionResolutions = 0;

    protected ?string $table = 'binding_resolver_models';

    public function getCasts(): array
    {
        return self::$configuredCasts;
    }

    public function getConnection(): Connection
    {
        ++self::$connectionResolutions;

        return self::$configuredConnection ?? throw new LogicException('No test connection was configured.');
    }
}

class NonIncrementingBindingResolverModel extends Model
{
    public static ?Connection $configuredConnection = null;

    protected ?string $table = 'external_binding_models';

    protected string $primaryKey = 'external_id';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    public function getConnection(): Connection
    {
        return self::$configuredConnection ?? throw new LogicException('No test connection was configured.');
    }
}
