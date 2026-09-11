<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Casts\AsVector;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Grammars\MariaDbGrammar;
use Hypervel\Database\Query\Grammars\PostgresGrammar;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseEloquentAsVectorCastTest extends TestCase
{
    public function testGetDecodesBinaryVector(): void
    {
        $this->useGrammar(MariaDbGrammar::class);

        $model = new AsVectorTestModel;
        $model->setRawAttributes(['embedding' => pack('g*', 0.5, -1.25, 3)]);

        $this->assertSame([0.5, -1.25, 3.0], $model->embedding);
    }

    public function testGetDecodesBinaryVectorBeginningWithOpeningBracketByte(): void
    {
        $this->useGrammar(MariaDbGrammar::class);

        $model = new AsVectorTestModel;
        $model->setRawAttributes(['embedding' => pack('g*', 1.0000108480453491)]);

        $this->assertSame([1.0000108480453491], $model->embedding);
    }

    public function testGetDecodesTextVector(): void
    {
        $this->useGrammar(PostgresGrammar::class);

        $model = new AsVectorTestModel;
        $model->setRawAttributes(['embedding' => '[0.5,-1.25,3]']);

        $this->assertSame([0.5, -1.25, 3.0], $model->embedding);
    }

    public function testGetReturnsNullForNullValue(): void
    {
        $this->useGrammar(MariaDbGrammar::class);

        $model = new AsVectorTestModel;
        $model->setRawAttributes(['embedding' => null]);

        $this->assertNull($model->embedding);
    }

    public function testSetOnMariaDbWrapsVectorInVecFromText(): void
    {
        $grammar = $this->useGrammar(MariaDbGrammar::class);

        $model = new AsVectorTestModel;
        $model->embedding = [0.5, -1.25, 3.75];

        $attribute = $model->getAttributes()['embedding'];

        $this->assertInstanceOf(Expression::class, $attribute);
        $this->assertSame("vec_fromtext('[0.5,-1.25,3.75]')", $attribute->getValue($grammar));
    }

    public function testSetOnPostgresStoresJson(): void
    {
        $this->useGrammar(PostgresGrammar::class);

        $model = new AsVectorTestModel;
        $model->embedding = [0.5, -1.25, 3.75];

        $this->assertSame('[0.5,-1.25,3.75]', $model->getAttributes()['embedding']);
    }

    public function testSetAcceptsArrayable(): void
    {
        $this->useGrammar(PostgresGrammar::class);

        $model = new AsVectorTestModel;
        $model->embedding = new Collection([0.5, -1.25, 3.75]);

        $this->assertSame('[0.5,-1.25,3.75]', $model->getAttributes()['embedding']);
        $this->assertSame([0.5, -1.25, 3.75], $model->embedding);
    }

    public function testSetAcceptsArrayableOnMariaDb(): void
    {
        $this->useGrammar(MariaDbGrammar::class);

        $model = new AsVectorTestModel;
        $model->embedding = new Collection([0.5, -1.25, 3]);

        $this->assertSame([0.5, -1.25, 3.0], $model->embedding);
    }

    public function testSetStoresNullAsNull(): void
    {
        $this->useGrammar(MariaDbGrammar::class);

        $model = new AsVectorTestModel;
        $model->embedding = null;

        $this->assertNull($model->getAttributes()['embedding']);
    }

    public function testSetRejectsNonArrayValues(): void
    {
        $this->useGrammar(MariaDbGrammar::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [embedding] attribute must be an array of floats or an Arrayable instance.');

        $model = new AsVectorTestModel;
        $model->embedding = 'not a vector';
    }

    public function testVectorCanBeReadBackBeforeSavingOnMariaDb(): void
    {
        $this->useGrammar(MariaDbGrammar::class);

        $model = new AsVectorTestModel;
        $model->embedding = [0.5, -1.25, 3];

        $this->assertSame([0.5, -1.25, 3.0], $model->embedding);
    }

    public function testVectorCanBeReadBackBeforeSavingOnPostgres(): void
    {
        $this->useGrammar(PostgresGrammar::class);

        $model = new AsVectorTestModel;
        $model->embedding = [0.5, -1.25, 3];

        $this->assertSame([0.5, -1.25, 3.0], $model->embedding);
    }

    #[DataProvider('storedVectorProvider')]
    public function testDirtyTrackingUsesStoredVectorPrecision(string $grammar, string $stored): void
    {
        $this->useGrammar($grammar);

        $model = new AsVectorTestModel;
        $model->setRawAttributes(['embedding' => $stored], true);

        $model->embedding = $model->embedding;
        $this->assertFalse($model->isDirty('embedding'));

        // Recomputing the same embedding must compare at the database's float32 precision.
        $model->embedding = [0.1, 0.2, 0.30000001];
        $this->assertFalse($model->isDirty('embedding'));

        // The next representable float32 value must still count as a change.
        $model->embedding = [0.1000000089407, 0.2, 0.30000001];
        $this->assertTrue($model->isDirty('embedding'));

        $model->embedding = [0.1, 0.2];
        $this->assertTrue($model->isDirty('embedding'));

        $model->embedding = null;
        $this->assertTrue($model->isDirty('embedding'));

        $model->syncOriginal();
        $model->embedding = [0.1, 0.2, 0.30000001];
        $this->assertTrue($model->isDirty('embedding'));
    }

    /**
     * Provide the database representations of the same vector.
     */
    public static function storedVectorProvider(): array
    {
        return [
            'MariaDB' => [MariaDbGrammar::class, pack('g*', 0.1, 0.2, 0.30000001)],
            'PostgreSQL' => [PostgresGrammar::class, '[0.1,0.2,0.3]'],
        ];
    }

    /**
     * Use the given query grammar for model connections.
     *
     * @param class-string<Grammar> $grammar
     */
    protected function useGrammar(string $grammar): Grammar
    {
        $connection = m::mock(Connection::class);
        $grammar = new $grammar($connection);
        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar);

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);

        Model::setConnectionResolver($resolver);

        return $grammar;
    }
}

class AsVectorTestModel extends Model
{
    protected array $guarded = [];

    protected array $casts = [
        'embedding' => AsVector::class,
    ];
}
