<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation\References;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Attributes\Validation\Unique;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\Validation\References\AuthenticatedUserReference;
use Hypervel\Foundation\Auth\User;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\Rule;

class AuthenticatedUserReferenceTest extends TestCase
{
    /**
     * Get package providers for the authenticated user reference tests.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Define the additional guard used by the guard tests.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
    }

    public function testCanReferenceTheCurrentLoggedInUserAsValuesWithinRules(): void
    {
        $this->actingAs($user = $this->user(69));

        $rules = AuthenticatedUserIgnoreData::getValidationRules([]);

        $this->assertSame((string) Rule::unique('users')->ignore($user), (string) $rules['property'][0]);
    }

    public function testCanReferenceAScalarPropertyOfTheCurrentUser(): void
    {
        $this->actingAs($this->user(1, ['max_title_length' => 40]));

        $rules = AuthenticatedUserPropertyData::getValidationRules([]);

        $this->assertSame('max:40', $rules['title'][0]);
    }

    public function testResolvesToNullForAGuest(): void
    {
        $this->assertNull((new AuthenticatedUserReference)->getValue());
    }

    public function testUsesTheGivenGuard(): void
    {
        $this->actingAs($this->user(7), 'admin');

        $this->assertNull((new AuthenticatedUserReference('id', 'web'))->getValue());
        $this->assertSame(7, (new AuthenticatedUserReference('id', 'admin'))->getValue());
    }

    public function testEachValidationResolvesTheCurrentUser(): void
    {
        $this->actingAs($this->user(1, ['max_title_length' => 10]));
        $first = AuthenticatedUserPropertyData::getValidationRules([]);

        $this->actingAs($this->user(2, ['max_title_length' => 20]));
        $second = AuthenticatedUserPropertyData::getValidationRules([]);

        $this->assertSame('max:10', $first['title'][0]);
        $this->assertSame('max:20', $second['title'][0]);
    }

    /**
     * Create an unsaved user with the given key and attributes.
     *
     * @param array<string, mixed> $attributes
     */
    private function user(int $id, array $attributes = []): User
    {
        return (new User)->forceFill(['id' => $id, ...$attributes]);
    }
}

class AuthenticatedUserIgnoreData extends Data
{
    /**
     * Create a fixture that ignores the authenticated user.
     */
    public function __construct(
        #[Unique('users', ignore: new AuthenticatedUserReference)]
        public int $property,
    ) {
    }
}

class AuthenticatedUserPropertyData extends Data
{
    /**
     * Create a fixture limited by an authenticated user property.
     */
    public function __construct(
        #[Max(new AuthenticatedUserReference('max_title_length'))]
        public string $title,
    ) {
    }
}
