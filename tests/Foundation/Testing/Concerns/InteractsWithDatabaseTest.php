<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hyperf\Database\Model\Factory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Tests\Foundation\Testing\ApplicationTestCase;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithDatabaseTest extends ApplicationTestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    public function testAssertDatabaseHas()
    {
        $user = $this->factory(User::class)->create();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    }

    public function testAssertDatabaseMissing()
    {
        $this->assertDatabaseMissing('users', [
            'id' => 1,
        ]);
    }

    public function testAssertDatabaseCount()
    {
        $this->assertDatabaseCount('users', 0);

        $this->factory(User::class)->create();

        $this->assertDatabaseCount('users', 1);
    }

    public function testAssertDatabaseEmpty()
    {
        $this->assertDatabaseEmpty('users');
    }

    public function testAssertModelExists()
    {
        $user = $this->factory(User::class)->create();

        $this->assertModelExists($user);
    }

    public function testAssertModelMissing()
    {
        $user = $this->factory(User::class)->create();
        $user->id = 2;

        $this->assertModelMissing($user);
    }

    protected function factory(string $class)
    {
        $factory = $this->app->get(Factory::class);
        $arguments = func_get_args();

        if (isset($arguments[1]) && is_string($arguments[1])) {
            return $factory->of($arguments[0], $arguments[1])->times($arguments[2] ?? null);
        }

        if (isset($arguments[1])) {
            return $factory->of($arguments[0])->times($arguments[1]);
        }

        return $factory->of($arguments[0]);
    }
}

class User extends Model
{
    protected array $guarded = [];
}
