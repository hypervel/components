<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations\Auth;

use Hypervel\Auth\Events\Verified;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\URL;
use Hypervel\Tests\Workbench\Integrations\TestCase;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function testEmailVerificationScreenCanBeRendered(): void
    {
        $user = UserFactory::new()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function testEmailCanBeVerified(): void
    {
        // Hypervel's Verified event requires a user that implements MustVerifyEmail.
        $user = VerifiableUser::query()->findOrFail(UserFactory::new()->unverified()->create()->getKey());

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false) . '?verified=1');
    }

    public function testEmailIsNotVerifiedWithInvalidHash(): void
    {
        $user = UserFactory::new()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}

class VerifiableUser extends User implements MustVerifyEmail
{
    protected ?string $table = 'users';
}
