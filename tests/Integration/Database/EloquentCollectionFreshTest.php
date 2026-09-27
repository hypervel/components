<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Database\Fixtures\Models\Integration\User;

class EloquentCollectionFreshTest extends DatabaseTestCase
{
    /**
     * Create the test schema.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->timestamps();
        });
    }

    public function testEloquentCollectionFresh(): void
    {
        User::insert([
            ['email' => 'hypervel@framework.com'],
            ['email' => 'hypervel@hypervel.com'],
        ]);

        $collection = User::all();

        $collection->first()->delete();

        $freshCollection = $collection->fresh();

        $this->assertCount(1, $freshCollection);
        $this->assertInstanceOf(EloquentCollection::class, $freshCollection);
    }

    public function testFreshRejectsPersistedModelsMissingThePrimaryKeyWithoutQuerying(): void
    {
        Model::preventAccessingMissingAttributes(false);

        $user = User::create(['email' => 'partial@example.com']);
        $partialUser = User::query()->select('email')->findOrFail($user->id);

        DB::enableQueryLog();

        try {
            new EloquentCollection([$partialUser])->fresh();
            $this->fail('Expected a missing attribute exception.');
        } catch (MissingAttributeException $exception) {
            $this->assertStringContainsString('The attribute [id]', $exception->getMessage());
        }

        $this->assertSame([], DB::getQueryLog());
    }

    public function testFreshIgnoresUnsavedModelsWithAssignedKeysWithoutQuerying(): void
    {
        $user = new User;
        $user->id = 123;

        DB::enableQueryLog();

        $freshCollection = new EloquentCollection([$user])->fresh();

        $this->assertTrue($freshCollection->isEmpty());
        $this->assertSame([], DB::getQueryLog());
    }
}
