<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Sanctum\Database;

use Carbon\CarbonInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Auth\User;
use Hypervel\Sanctum\HasApiTokens;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

abstract class PersonalAccessTokenExpiryTestCase extends DatabaseTestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SanctumServiceProvider::class,
        ];
    }

    public function testTokenExpiryPersistsBeyondTheTimestampRange(): void
    {
        $expiresAt = CarbonImmutable::create(2040, 1, 2, 3, 4, 5, 'UTC');
        $owner = new SanctumExpiryTestUser;

        // The token table has no tokenable foreign key, so an assigned morph key
        // exercises createToken() without requiring an unrelated owner schema.
        $owner->setAttribute($owner->getKeyName(), 1);

        $token = $owner->createToken('Long-lived token', expiresAt: $expiresAt)->accessToken;
        $persistedExpiresAt = $token->refresh()->expires_at;

        $this->assertInstanceOf(CarbonInterface::class, $persistedExpiresAt);
        $this->assertSame($expiresAt->getTimestamp(), $persistedExpiresAt->getTimestamp());
    }

    /**
     * Get the migration options for the shipped Sanctum schema.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => false,
            '--realpath' => true,
            '--path' => [__DIR__ . '/../../../../src/sanctum/database/migrations'],
        ];
    }
}

class SanctumExpiryTestUser extends User
{
    use HasApiTokens;
}
