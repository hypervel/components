<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('app.debug', false)]
class LatestResponseExceptionTest extends TestCase
{
    #[Test]
    public function itRendersAuthorizationExceptions(): void
    {
        Route::get('test-route', fn () => Response::deny('expected message', 321)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(403)
            ->assertSeeText('expected message');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'expected message',
            ]);
    }

    #[Test]
    public function itRendersAuthorizationExceptionsWithCustomStatusCode(): void
    {
        Route::get('test-route', fn () => Response::deny('expected message', 321)->withStatus(404)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(404)
            ->assertSeeText('Not Found');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(404)
            ->assertExactJson([
                'message' => 'expected message',
            ]);
    }

    #[Test]
    public function itRendersAuthorizationExceptionsWithStatusCodeTextWhenNoMessageIsSet(): void
    {
        Route::get('test-route', fn () => Response::denyWithStatus(404)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(404)
            ->assertSeeText('Not Found');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(404)
            ->assertExactJson([
                'message' => 'Not Found',
            ]);

        Route::get('test-route', fn () => Response::denyWithStatus(418)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(418)
            ->assertSeeText("I'm a teapot", escape: false);

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(418)
            ->assertExactJson([
                'message' => "I'm a teapot",
            ]);
    }

    #[Test]
    public function itRendersAuthorizationExceptionsWithStatusButWithoutResponse(): void
    {
        Route::get('test-route', fn () => throw (new AuthorizationException)->withStatus(418));

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(418)
            ->assertSeeText("I'm a teapot", escape: false);

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(418)
            ->assertExactJson([
                'message' => "I'm a teapot",
            ]);
    }

    #[Test]
    public function itHasFallbackErrorMessageForUnknownStatusCodes(): void
    {
        Route::get('test-route', fn () => throw (new AuthorizationException)->withStatus(399));

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(399)
            ->assertSeeText('Whoops, looks like something went wrong.');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(399)
            ->assertExactJson([
                'message' => 'Whoops, looks like something went wrong.',
            ]);
    }
}
