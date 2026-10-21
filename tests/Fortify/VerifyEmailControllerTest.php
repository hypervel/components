<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Auth\Events\Verified;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\URL;
use Hypervel\Testbench\Attributes\WithMigration;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

#[WithMigration]
class VerifyEmailControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testTheEmailCanBeVerified(): void
    {
        $this->assertEmailCanBeVerified();
    }

    public function testTheEmailCanBeVerifiedUsingFailedOnUnknownFields(): void
    {
        FormRequest::failOnUnknownFields(true);

        $this->assertEmailCanBeVerified();
    }

    protected function assertEmailCanBeVerified(): void
    {
        $user = $this->createUnverifiedUser([
            'email' => 'taylor@laravel.com',
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->email),
            ]
        );

        $response = $this->actingAs($user)
            ->withSession(['url.intended' => 'http://foo.com/bar'])
            ->get($url);

        $response->assertRedirect('http://foo.com/bar');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function testRedirectedIfEmailIsAlreadyVerified(): void
    {
        Event::fake([Verified::class]);

        $user = User::forceCreate(UserFactory::new()->raw([
            'email' => 'taylor@hypervel.org',
        ]));

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1('taylor@hypervel.org'),
            ]
        );

        $response = $this->actingAs($user)->get($url);

        $response->assertStatus(302);
        Event::assertNotDispatched(Verified::class);
    }

    public function testEmailIsNotVerifiedIfIdDoesNotMatch(): void
    {
        $user = $this->createUnverifiedUser([
            'email' => 'taylor@hypervel.org',
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey() + 1,
                'hash' => sha1('taylor@hypervel.org'),
            ]
        );

        $response = $this->actingAs($user)->get($url);

        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function testEmailIsNotVerifiedIfEmailDoesNotMatch(): void
    {
        $user = $this->createUnverifiedUser([
            'email' => 'taylor@hypervel.org',
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1('abigail@hypervel.org'),
            ]
        );

        $response = $this->actingAs($user)->get($url);

        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createUnverifiedUser(array $attributes = []): User
    {
        return User::forceCreate(UserFactory::new()->unverified()->raw($attributes));
    }
}
