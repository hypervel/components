<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Fortify\Contracts\UpdatesUserProfileInformation;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

#[WithMigration]
class ProfileInformationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testContactInformationCanBeUpdated(): void
    {
        $user = User::forceCreate(UserFactory::new()->raw());

        $this->mock(UpdatesUserProfileInformation::class)
            ->shouldReceive('update')
            ->once();

        $response = $this->withoutExceptionHandling()->actingAs($user)->putJson('/user/profile-information', [
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
        ]);

        $response->assertStatus(200);
    }

    public function testEmailAddressWillBeUpdatedCaseInsensitive(): void
    {
        $this->app->make('config')->set('fortify.lowercase_usernames', true);

        $user = User::forceCreate(UserFactory::new()->raw());

        $this->mock(UpdatesUserProfileInformation::class)
            ->shouldReceive('update')
            ->with($user, [
                'name' => 'Taylor Otwell',
                'email' => 'taylor@laravel.com',
            ])
            ->once();

        $response = $this->withoutExceptionHandling()->actingAs($user)->putJson('/user/profile-information', [
            'name' => 'Taylor Otwell',
            'email' => 'TAYLOR@LARAVEL.COM',
        ]);

        $response->assertStatus(200);
    }
}
