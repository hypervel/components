<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use ReflectionClass;
use Workbench\App\Models\User;

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
        $user = User::factory()->create();

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

        User::factory()->create();

        $this->assertDatabaseCount('users', 1);
    }

    public function testAssertDatabaseEmpty()
    {
        $this->assertDatabaseEmpty('users');
    }

    public function testAssertModelExists()
    {
        $user = User::factory()->create();

        $this->assertModelExists($user);
    }

    public function testAssertModelMissing()
    {
        $user = User::factory()->create();
        $user->id = 2;

        $this->assertModelMissing($user);
    }

    public function testFactoryUsesConfiguredFakerLocale()
    {
        $locale = 'fr_FR';
        $this->app->get(ConfigInterface::class)
            ->set('app.faker_locale', $locale);

        $factory = User::factory();

        // Use reflection to access the protected $faker property
        $reflectedClass = new ReflectionClass($factory);
        $fakerProperty = $reflectedClass->getProperty('faker');
        $fakerProperty->setAccessible(true);

        // Faker is lazy-loaded, so we need to trigger it by calling definition
        // or accessing it through a state callback
        $factory->state(function (array $attributes) use ($fakerProperty, $factory, $locale) {
            /** @var \Faker\Generator $faker */
            $faker = $fakerProperty->getValue($factory);
            $providerClasses = array_map(fn ($provider) => get_class($provider), $faker->getProviders());

            $this->assertTrue(
                Collection::make($providerClasses)->contains(fn ($class) => str_contains($class, $locale)),
                "Expected one of the Faker providers to contain the locale '{$locale}', but none did."
            );

            return [];
        })->make();
    }
}
