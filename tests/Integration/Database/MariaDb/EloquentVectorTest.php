<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MariaDb;

use Hypervel\Database\Eloquent\Casts\AsVector;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;

#[RequiresDatabase('mariadb', '>=11.7.0')]
class EloquentVectorTest extends MariaDbTestCase
{
    /**
     * Create the vector storage schema.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->increments('id');
            $table->vector('embedding', 3);
            $table->vectorIndex('embedding');
        });
    }

    /**
     * Remove the vector storage schema.
     */
    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropIfExists('documents');
    }

    public function testVectorsCanBeStoredAndRetrieved(): void
    {
        $document = VectorDocument::create(['embedding' => [0.5, -1.25, 3]]);

        $this->assertSame([0.5, -1.25, 3.0], $document->embedding);
        $this->assertSame([0.5, -1.25, 3.0], $document->fresh()->embedding);
    }

    public function testVectorsCanBeUpdated(): void
    {
        $document = VectorDocument::create(['embedding' => [0.5, -1.25, 3]]);

        $document->update(['embedding' => [1, 2, 3]]);

        $this->assertSame([1.0, 2.0, 3.0], $document->fresh()->embedding);
    }

    public function testVectorsCanBeQueriedByDistance(): void
    {
        $exact = VectorDocument::create(['embedding' => [1, 0, 0]]);
        $close = VectorDocument::create(['embedding' => [0.9, 0.1, 0]]);
        VectorDocument::create(['embedding' => [0, 1, 0]]);

        $results = VectorDocument::query()
            ->select('id')
            ->selectVectorDistance('embedding', [1, 0, 0])
            ->whereVectorSimilarTo('embedding', [1, 0, 0], minSimilarity: 0.5)
            ->get();

        $this->assertSame([$exact->id, $close->id], $results->pluck('id')->all());
        $this->assertEqualsWithDelta(0.0, (float) $results[0]->embedding_distance, 0.0001);
        $this->assertGreaterThan(0.0, (float) $results[1]->embedding_distance);
    }
}

class VectorDocument extends Model
{
    protected ?string $table = 'documents';

    public bool $timestamps = false;

    protected array $guarded = [];

    protected array $casts = [
        'embedding' => AsVector::class,
    ];
}
