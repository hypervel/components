<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Foundation\Stubs\User;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => dirname(__DIR__, 2) . '/migrations',
        ];
    }

    public function testAssertDatabaseHas()
    {
        $user = User::factory()->create();

        $this->assertDatabaseHas('foundation_test_users', [
            'id' => $user->id,
        ]);
    }

    public function testAssertDatabaseMissing()
    {
        $this->assertDatabaseMissing('foundation_test_users', [
            'id' => 1,
        ]);
    }

    public function testAssertDatabaseCount()
    {
        $this->assertDatabaseCount('foundation_test_users', 0);

        User::factory()->create();

        $this->assertDatabaseCount('foundation_test_users', 1);
    }

    public function testAssertDatabaseEmpty()
    {
        $this->assertDatabaseEmpty('foundation_test_users');
    }

    public function testAssertModelExists()
    {
        $user = User::factory()->create();

        $this->assertModelExists($user);
    }

    public function testAssertModelMissing()
    {
        $user = User::factory()->create();
        $user->id = 999;

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

        // Trigger faker initialization by calling make()
        $factory->make();

        /** @var \Faker\Generator $faker */
        $faker = $fakerProperty->getValue($factory);
        $providerClasses = array_map(fn ($provider) => get_class($provider), $faker->getProviders());

        $this->assertTrue(
            Collection::make($providerClasses)->contains(fn ($class) => str_contains($class, $locale)),
            "Expected one of the Faker providers to contain the locale '{$locale}', but none did."
        );
    }
}
