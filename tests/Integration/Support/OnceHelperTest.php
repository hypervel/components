<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Support\OnceHelperTest;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Testbench\TestCase;

#[WithMigration]
class OnceHelperTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create verified and unverified users.
     */
    protected function afterRefreshingDatabase(): void
    {
        UserFactory::times(3)->create();
        UserFactory::times(2)->unverified()->create();
    }

    public function testItCanCacheStaticMethodWithoutParameters(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $verifiedUsers = User::verified();
        $unverifiedUsers = User::unverified();

        $this->assertCount(3, $verifiedUsers);
        $this->assertCount(2, $unverifiedUsers);
        $this->assertCount(2, DB::getQueryLog());

        $verifiedUsers2 = User::verified();

        $this->assertCount(2, DB::getQueryLog());

        $this->assertSame($verifiedUsers, $verifiedUsers2);

        DB::disableQueryLog();
    }

    public function testItCanCacheStaticMethodWithParameters(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $verifiedUsers = User::getByType('verified');
        $unverifiedUsers = User::getByType('unverified');

        $this->assertCount(3, $verifiedUsers);
        $this->assertCount(2, $unverifiedUsers);
        $this->assertCount(2, DB::getQueryLog());

        $verifiedUsers2 = User::getByType('verified');

        $this->assertCount(2, DB::getQueryLog());

        $this->assertSame($verifiedUsers, $verifiedUsers2);

        DB::disableQueryLog();
    }
}

class User extends Authenticatable
{
    /**
     * Get verified users once.
     *
     * @return Collection<int, static>
     */
    public static function verified(): Collection
    {
        return once(fn (): Collection => self::whereNotNull('email_verified_at')->get());
    }

    /**
     * Get unverified users once.
     *
     * @return Collection<int, static>
     */
    public static function unverified(): Collection
    {
        return once(fn (): Collection => self::whereNull('email_verified_at')->get());
    }

    /**
     * Get users once for each verification status.
     *
     * @return Collection<int, static>
     */
    public static function getByType(string $type): Collection
    {
        return once(function () use ($type): Collection {
            return match ($type) {
                'verified' => self::whereNotNull('email_verified_at')->get(),
                'unverified' => self::whereNull('email_verified_at')->get()
            };
        });
    }
}
