<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Fortify\Contracts\UpdatesUserProfileInformation;
use Mockery as m;

class ProfileInformationControllerTest extends TestCase
{
    public function testContactInformationCanBeUpdated(): void
    {
        $user = m::mock(Authenticatable::class);

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

        $user = m::mock(Authenticatable::class);

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
