<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Resources\AssertActingAsTest;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Foundation\Auth\User;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

class AirportR extends Server
{
    protected array $resources = [
        TicketResource::class,
    ];
}

class TicketResource extends Resource
{
    /**
     * Return a ticket for an authenticated user.
     */
    public function handle(Request $request): string
    {
        return $request->user() instanceof Authenticatable
            ? 'Here is your ticket!'
            : 'You must be logged in to get a ticket.';
    }
}

class AssertActingAsTest extends TestCase
{
    public function testMayAssertTheUserIsActingAsTheGivenUser(): void
    {
        $user = new class extends User {};

        $response = AirportR::actingAs($user)
            ->resource(TicketResource::class);

        $response->assertSee('Here is your ticket!');
    }

    public function testMayAssertTheUserIsNotActingAsAUser(): void
    {
        $response = AirportR::resource(TicketResource::class);

        $response->assertSee('You must be logged in to get a ticket.');
    }

    public function testMayAssertAuthenticatedAndAuthenticatedAsASpecificUser(): void
    {
        $user = new class extends User {
            public int $id = 1;
        };

        $response = AirportR::actingAs($user)
            ->resource(TicketResource::class);

        $response->assertAuthenticated()
            ->assertAuthenticatedAs($user);
    }

    public function testMayAssertGuestWhenNoUserIsAuthenticated(): void
    {
        $response = AirportR::resource(TicketResource::class);

        $response->assertGuest();
    }

    public function testFailsWhenAssertingAuthenticatedAsADifferentUser(): void
    {
        $userA = new class extends User {
            public int $id = 1;
        };

        $userB = new class extends User {
            public int $id = 2;
        };

        $response = AirportR::actingAs($userA)
            ->resource(TicketResource::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertAuthenticatedAs($userB);
    }
}
