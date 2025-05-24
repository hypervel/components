<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Model\FactoryBuilder;
use Hyperf\Testing\ModelFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithDatabaseTest extends TestCase
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

    public function testFactoryUsesConfiguredFakerLocale()
    {
        $locale = 'fr_FR';
        $this->app->get(ConfigInterface::class)
            ->set('app.faker_locale', $locale);

        $factory = $this->factory(User::class);
        // Use reflection to access the protected $faker property
        $reflectedClass = new ReflectionClass($factory);
        $fakerProperty = $reflectedClass->getProperty('faker');
        $fakerProperty->setAccessible(true);
        /** @var \Faker\Generator $faker */
        $faker = $fakerProperty->getValue($factory);
        $providerClasses = array_map(fn ($provider) => get_class($provider), $faker->getProviders());

        $this->assertTrue(
            Collection::make($providerClasses)->contains(fn ($class) => str_contains($class, $locale)),
            "Expected one of the Faker providers to contain the locale '{$locale}', but none did."
        );
    }

    protected function factory(string $class, mixed ...$arguments): FactoryBuilder
    {
        return $this->app->get(ModelFactory::class)
            ->factory($class, ...$arguments);
    }
}

class User extends Model
{
    protected array $guarded = [];
}
