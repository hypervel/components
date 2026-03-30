<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Context\PropagatedContextIntegrationTest;

use ErrorException;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use RuntimeException;

class User extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = ['name', 'email', 'password'];
}

/**
 * @internal
 * @coversNothing
 */
#[ResetRefreshDatabaseState]
#[WithMigration]
class PropagatedContextIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function testItCanHydrateNull()
    {
        CoroutineContext::propagated()->hydrate(null);

        $this->assertSame([], CoroutineContext::propagated()->all());
    }

    public function testItHandlesEloquent()
    {
        $user = User::create(['name' => 'Tim', 'email' => 'tim@example.com', 'password' => 'secret']);

        CoroutineContext::propagated()->add('model', $user);
        CoroutineContext::propagated()->add('number', 55);

        $dehydrated = CoroutineContext::propagated()->dehydrate();

        CoroutineContext::propagated()->flush();
        $this->assertNull(CoroutineContext::propagated()->get('model'));
        $this->assertNull(CoroutineContext::propagated()->get('number'));

        CoroutineContext::propagated()->hydrate($dehydrated);
        $this->assertTrue($user->is(CoroutineContext::propagated()->get('model')));
        $this->assertNotSame($user, CoroutineContext::propagated()->get('model'));
        $this->assertSame(55, CoroutineContext::propagated()->get('number'));
    }

    public function testItIgnoresDeletedModelsWhenHydrating()
    {
        $user = User::create(['name' => 'Tim', 'email' => 'tim@example.com', 'password' => 'secret']);

        CoroutineContext::propagated()->add('model', $user);
        CoroutineContext::propagated()->add('number', 55);

        $dehydrated = CoroutineContext::propagated()->dehydrate();
        $user->delete();

        CoroutineContext::propagated()->flush();
        $this->assertNull(CoroutineContext::propagated()->get('model'));
        $this->assertNull(CoroutineContext::propagated()->get('number'));

        CoroutineContext::propagated()->hydrate($dehydrated);
        $this->assertNull(CoroutineContext::propagated()->get('model'));
        $this->assertSame(55, CoroutineContext::propagated()->get('number'));
    }

    public function testItIgnoresDeletedModelsWithinCollectionsWhenHydrating()
    {
        $user = User::create(['name' => 'Tim', 'email' => 'tim@example.com', 'password' => 'secret']);

        CoroutineContext::propagated()->add('models', User::all());
        CoroutineContext::propagated()->add('number', 55);

        $dehydrated = CoroutineContext::propagated()->dehydrate();
        $user->delete();

        CoroutineContext::propagated()->flush();
        $this->assertNull(CoroutineContext::propagated()->get('models'));
        $this->assertNull(CoroutineContext::propagated()->get('number'));

        CoroutineContext::propagated()->hydrate($dehydrated);
        $this->assertInstanceOf(EloquentCollection::class, CoroutineContext::propagated()->get('models'));
        $this->assertCount(0, CoroutineContext::propagated()->get('models'));
        $this->assertSame(55, CoroutineContext::propagated()->get('number'));
    }

    public function testItThrowsOnIncompleteClasses()
    {
        $dehydrated = [
            'data' => [
                'model' => 'O:18:"App\MyContextClass":0:{}',
            ],
            'hidden' => [],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Value is incomplete class');

        CoroutineContext::propagated()->hydrate($dehydrated);
    }

    public function testItThrowsGenericUnserializeExceptions()
    {
        $dehydrated = [
            'data' => [
                'model' => 'bad data',
            ],
            'hidden' => [],
        ];

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('unserialize(): Error at offset 0 of 8 bytes');

        CoroutineContext::propagated()->hydrate($dehydrated);
    }

    public function testItCanHandleUnserializeExceptionsManually()
    {
        $dehydrated = [
            'data' => [
                'model' => 'bad data',
            ],
            'hidden' => [
                'other' => 'more bad data',
            ],
        ];

        CoroutineContext::propagated()->handleUnserializeExceptionsUsing(
            function ($exception, $key, $value, $hidden) {
                if ($key === 'model') {
                    $this->assertSame('bad data', $value);
                    $this->assertFalse($hidden);

                    return 'replaced value 1';
                }

                $this->assertSame('more bad data', $value);
                $this->assertTrue($hidden);

                return 'replaced value 2';
            }
        );

        CoroutineContext::propagated()->hydrate($dehydrated);

        $this->assertSame('replaced value 1', CoroutineContext::propagated()->get('model'));
        $this->assertSame('replaced value 2', CoroutineContext::propagated()->getHidden('other'));
    }
}
