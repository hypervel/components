<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Auth\Notifications\VerifyEmail;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Notification;
use Hypervel\Testbench\Attributes\WithMigration;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

#[WithMigration]
class EmailVerificationNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testEmailVerificationNotificationCanBeSent(): void
    {
        Notification::fake();

        $user = User::forceCreate(UserFactory::new()->unverified()->raw());

        $response = $this->from('/email/verify')
            ->actingAs($user)
            ->post('/email/verification-notification');

        $response->assertRedirect('/email/verify');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function testUserIsRedirectIfAlreadyVerified(): void
    {
        Notification::fake();

        $user = User::forceCreate(UserFactory::new()->raw());

        $response = $this->from('/email/verify')
            ->actingAs($user)
            ->post('/email/verification-notification');

        $response->assertRedirect('/home');
        Notification::assertNothingSent();
    }

    public function testUserIsRedirectToIntendedUrlIfAlreadyVerified(): void
    {
        Notification::fake();

        $user = User::forceCreate(UserFactory::new()->raw());

        $response = $this->from('/email/verify')
            ->actingAs($user)
            ->withSession(['url.intended' => 'http://foo.com/bar'])
            ->post('/email/verification-notification');

        $response->assertRedirect('http://foo.com/bar');
        Notification::assertNothingSent();
    }
}
