<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Passport\Passport;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;

class TransientTokenControllerTest extends PassportTestCase
{
    use WithHypervelMigrations;

    public function testTokenCanBeRefreshed(): void
    {
        $this->actingAs(UserFactory::new()->create())
            ->post('/oauth/token/refresh')
            ->assertOk()
            ->assertSee('Refreshed.')
            ->assertCookie(Passport::cookie());
    }
}
