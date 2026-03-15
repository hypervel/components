<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Http;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Telescope\Http\Middleware\Authorize;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\LogWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 * @coversNothing
 */
class AvatarTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(Authorize::class);

        $this->app->make('config')
            ->set('telescope.watchers', [
                LogWatcher::class => true,
            ]);
        $this->app->make('config')
            ->set('logging.default', 'null');

        $this->startTelescope();

        $this->loadServiceProviders();
    }

    public function testItCanRegisterCustomAvatarPath()
    {
        $user = null;

        Telescope::withoutRecording(function () use (&$user) {
            $this->createUsersTable();

            $user = UserEloquent::create([
                'id' => 1,
                'name' => 'Telescope',
                'email' => 'telescope@laravel.com',
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
}
