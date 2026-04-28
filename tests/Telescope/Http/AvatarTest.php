<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Http;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Str;
use Hypervel\Telescope\Http\Middleware\Authorize;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\LogWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Psr\Log\LoggerInterface;

#[WithConfig('logging.default', 'null')]
#[WithConfig('telescope.watchers', [
    LogWatcher::class => true,
])]
class AvatarTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(Authorize::class);
    }

    public function testItCanGenerateAvatarUrl()
    {
        $user = null;

        Telescope::withoutRecording(function () use (&$user) {
            $user = UserEloquent::create([
                'id' => 1,
                'name' => 'Telescope',
                'email' => 'telescope@hypervel.org',
                'password' => 'secret',
            ]);
        });

        $this->actingAs($user);

        $this->app->make(LoggerInterface::class)
            ->error('Avatar path will be generated.', [
                'exception' => 'Some error message',
            ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->get("/telescope/telescope-api/logs/{$entry->uuid}")
            ->assertOk()
            ->assertJson([
                'entry' => [
                    'content' => [
                        'user' => [
                            'avatar' => 'https://www.gravatar.com/avatar/' . md5(Str::lower($user['email'])) . '?s=200',
                        ],
                    ],
                ],
            ]);
    }

    public function testItCanRegisterCustomAvatarPath()
    {
        $user = null;

        Telescope::withoutRecording(function () use (&$user) {
            $user = UserEloquent::create([
                'id' => 1,
                'name' => 'Telescope',
                'email' => 'telescope@hypervel.org',
                'password' => 'secret',
            ]);
        });

        Telescope::avatar(function ($id) {
            return "/images/{$id}.jpg";
        });

        $this->actingAs($user);

        $this->app->make(LoggerInterface::class)
            ->error('Avatar path will be generated.', [
                'exception' => 'Some error message',
            ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->get("/telescope/telescope-api/logs/{$entry->uuid}")
            ->assertOk()
            ->assertJson([
                'entry' => [
                    'content' => [
                        'user' => [
                            'avatar' => '/images/1.jpg',
                        ],
                    ],
                ],
            ]);
    }

    public function testItCanReadCustomAvatarPathOnNullEmail()
    {
        $user = null;

        Telescope::withoutRecording(function () use (&$user) {
            $user = UserEloquent::create([
                'id' => 1,
                'name' => 'Telescope',
                'email' => 'telescope@hypervel.org',
                'password' => 'secret',
            ]);
        });
        $user->email = null;

        Telescope::avatar(function ($id) {
            return "/images/{$id}.jpg";
        });

        $this->actingAs($user);

        $this->app->make(LoggerInterface::class)
            ->error('Avatar path will be generated.', [
                'exception' => 'Some error message',
            ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->get("/telescope/telescope-api/logs/{$entry->uuid}")
            ->assertOk()
            ->assertJson([
                'entry' => [
                    'content' => [
                        'user' => [
                            'avatar' => '/images/1.jpg',
                        ],
                    ],
                ],
            ]);
    }
}

class UserEloquent extends Model implements Authenticatable
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public function getAuthIdentifierName(): string
    {
        return $this->email;
    }

    public function getAuthIdentifier(): string
    {
        return (string) $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(string $value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
