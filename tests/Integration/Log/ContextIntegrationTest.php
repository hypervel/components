<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Log\ContextIntegrationTest;

use ErrorException;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Log\Context\Repository;
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
class ContextIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function testItCanHydrateNull()
    {
        Repository::getInstance()->hydrate(null);

        $this->assertSame([], Repository::getInstance()->all());
    }

    public function testItHandlesEloquent()
    {
        $user = User::create(['name' => 'Tim', 'email' => 'tim@example.com', 'password' => 'secret']);

        Repository::getInstance()->add('model', $user);
        Repository::getInstance()->add('number', 55);

        $dehydrated = Repository::getInstance()->dehydrate();

        Repository::getInstance()->flush();
        $this->assertNull(Repository::getInstance()->get('model'));
        $this->assertNull(Repository::getInstance()->get('number'));

        Repository::getInstance()->hydrate($dehydrated);
        $this->assertTrue($user->is(Repository::getInstance()->get('model')));
        $this->assertNotSame($user, Repository::getInstance()->get('model'));
        $this->assertSame(55, Repository::getInstance()->get('number'));
    }

    public function testItIgnoresDeletedModelsWhenHydrating()
    {
        $user = User::create(['name' => 'Tim', 'email' => 'tim@example.com', 'password' => 'secret']);

        Repository::getInstance()->add('model', $user);
        Repository::getInstance()->add('number', 55);

        $dehydrated = Repository::getInstance()->dehydrate();
        $user->delete();

        Repository::getInstance()->flush();
        $this->assertNull(Repository::getInstance()->get('model'));
        $this->assertNull(Repository::getInstance()->get('number'));

        Repository::getInstance()->hydrate($dehydrated);
        $this->assertNull(Repository::getInstance()->get('model'));
        $this->assertSame(55, Repository::getInstance()->get('number'));
    }

    public function testItIgnoresDeletedModelsWithinCollectionsWhenHydrating()
    {
        $user = User::create(['name' => 'Tim', 'email' => 'tim@example.com', 'password' => 'secret']);

        Repository::getInstance()->add('models', User::all());
        Repository::getInstance()->add('number', 55);

        $dehydrated = Repository::getInstance()->dehydrate();
        $user->delete();

        Repository::getInstance()->flush();
        $this->assertNull(Repository::getInstance()->get('models'));
        $this->assertNull(Repository::getInstance()->get('number'));

        Repository::getInstance()->hydrate($dehydrated);
        $this->assertInstanceOf(EloquentCollection::class, Repository::getInstance()->get('models'));
        $this->assertCount(0, Repository::getInstance()->get('models'));
        $this->assertSame(55, Repository::getInstance()->get('number'));
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

        Repository::getInstance()->hydrate($dehydrated);
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

        Repository::getInstance()->hydrate($dehydrated);
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

        Repository::getInstance()->handleUnserializeExceptionsUsing(
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

        Repository::getInstance()->hydrate($dehydrated);

        $this->assertSame('replaced value 1', Repository::getInstance()->get('model'));
        $this->assertSame('replaced value 2', Repository::getInstance()->getHidden('other'));
    }
}
