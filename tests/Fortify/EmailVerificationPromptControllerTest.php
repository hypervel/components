<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Fortify\Fortify;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

#[WithMigration]
class EmailVerificationPromptControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testTheEmailVerificationPromptViewIsReturned(): void
    {
        Fortify::verifyEmailView(fn (): string => 'hello world');

        $user = User::forceCreate(UserFactory::new()->unverified()->raw());

        $response = $this->actingAs($user)->get('/email/verify');

        $response->assertStatus(200);
        $response->assertSeeText('hello world');
    }

    public function testUserIsRedirectHomeIfAlreadyVerified(): void
    {
        $user = User::forceCreate(UserFactory::new()->raw());

        $response = $this->actingAs($user)->get('/email/verify');

        $response->assertRedirect('/home');
    }

    public function testUserIsRedirectToIntendedUrlIfAlreadyVerified(): void
    {
        $user = User::forceCreate(UserFactory::new()->raw());

        $response = $this->actingAs($user)
            ->withSession(['url.intended' => 'http://foo.com/bar'])
            ->get('/email/verify');

        $response->assertRedirect('http://foo.com/bar');
    }
}
