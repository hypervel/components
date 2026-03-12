<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Context\PropagatedContextIntegrationTest;

use ErrorException;
use Hypervel\Context\Context;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
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
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        Context::propagated()->handleUnserializeExceptionsUsing(null);
        Context::flush();

        parent::tearDown();
    }

    public function testItCanHydrateNull()
    {
        Context::propagated()->hydrate(null);

        $this->assertSame([], Context::propagated()->all());
    }

    public function testItHandlesEloquent()
    {
        $user = User::create(['name' => 'Tim', 'email' => 'tim@example.com', 'password' => 'secret']);

        Context::propagated()->add('model', $user);
        Context::propagated()->add('number', 55);

        $dehydrated = Context::propagated()->dehydrate();

        Context::propagated()->flush();
        $this->assertNull(Context::propagated()->get('model'));
        $this->assertNull(Context::propagated()->get('number'));

        Context::propagated()->hydrate($dehydrated);
        $this->assertTrue($user->is(Context::propagated()->get('model')));
        $this->assertNotSame($user, Context::propagated()->get('model'));
        $this->assertSame(55, Context::propagated()->get('number'));
    }

    public function testItIgnoresDeletedModelsWhenHydrating()
    {
        $user = User::create(['name' => 'Tim', 'email' => 'tim@example.com', 'password' => 'secret']);

        Context::propagated()->add('model', $user);
        Context::propagated()->add('number', 55);

        $dehydrated = Context::propagated()->dehydrate();
        $user->delete();

        Context::propagated()->flush();
        $this->assertNull(Context::propagated()->get('model'));
        $this->assertNull(Context::propagated()->get('number'));

        Context::propagated()->hydrate($dehydrated);
        $this->assertNull(Context::propagated()->get('model'));
        $this->assertSame(55, Context::propagated()->get('number'));
    }

    public function testItIgnoresDeletedModelsWithinCollectionsWhenHydrating()
    {
        $user = User::create(['name' => 'Tim', 'email' => 'tim@example.com', 'password' => 'secret']);

        Context::propagated()->add('models', User::all());
        Context::propagated()->add('number', 55);

        $dehydrated = Context::propagated()->dehydrate();
        $user->delete();

        Context::propagated()->flush();
        $this->assertNull(Context::propagated()->get('models'));
        $this->assertNull(Context::propagated()->get('number'));

        Context::propagated()->hydrate($dehydrated);
        $this->assertInstanceOf(EloquentCollection::class, Context::propagated()->get('models'));
        $this->assertCount(0, Context::propagated()->get('models'));
        $this->assertSame(55, Context::propagated()->get('number'));
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

        Context::propagated()->hydrate($dehydrated);
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

        Context::propagated()->hydrate($dehydrated);
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

        Context::propagated()->handleUnserializeExceptionsUsing(
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

        Context::propagated()->hydrate($dehydrated);

        $this->assertSame('replaced value 1', Context::propagated()->get('model'));
        $this->assertSame('replaced value 2', Context::propagated()->getHidden('other'));
    }
}
