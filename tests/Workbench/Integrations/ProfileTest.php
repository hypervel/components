<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Schema;
use Workbench\Database\Factories\UserFactory;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function testProfilePageIsDisplayed(): void
    {
        $user = UserFactory::new()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function testProfileInformationCanBeUpdated(): void
    {
        $user = UserFactory::new()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function testEmailVerificationStatusIsUnchangedWhenTheEmailAddressIsUnchanged(): void
    {
        $user = UserFactory::new()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function testUserCanDeleteTheirAccount(): void
    {
        $user = UserFactory::new()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function testCorrectPasswordMustBeProvidedToDeleteAccount(): void
    {
        $user = UserFactory::new()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function testProfileEmailUniquenessUsesTheAuthenticatedUserModel(): void
    {
        Schema::create('profile_members', static function (Blueprint $table): void {
            $table->id('member_id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        $member = ProfileMember::forceCreate(['name' => 'Member', 'email' => 'member@example.com', 'password' => 'password']);
        ProfileMember::forceCreate(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password']);

        $this->actingAs($member)
            ->from('/profile')
            ->patch('/profile', ['name' => 'Member', 'email' => 'other@example.com'])
            ->assertSessionHasErrors('email');

        $this->actingAs($member)
            ->patch('/profile', ['name' => 'Renamed Member', 'email' => 'member@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertSame('Renamed Member', $member->refresh()->name);
    }
}

class ProfileMember extends Authenticatable
{
    protected ?string $table = 'profile_members';

    protected string $primaryKey = 'member_id';

    protected array $fillable = ['name', 'email'];
}
