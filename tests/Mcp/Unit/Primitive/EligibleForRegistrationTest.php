<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Primitive;

use Hypervel\Context\CoroutineContext;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server\Primitive;
use Hypervel\Tests\Mcp\TestCase;

class EligibleForRegistrationTest extends TestCase
{
    public function testReturnsTrueByDefaultWhenShouldRegisterIsNotDefined(): void
    {
        $primitive = new class extends Primitive {
            /**
             * Return the method call.
             */
            public function toMethodCall(): array
            {
                return [];
            }

            /**
             * Return the primitive data.
             */
            public function toArray(): array
            {
                return [];
            }
        };

        $this->assertTrue($primitive->eligibleForRegistration());
    }

    public function testCallsShouldRegisterWithTheRequestAndHonorsItsReturnValue(): void
    {
        $primitive = new class extends Primitive {
            public ?Request $received = null;

            /**
             * Return the method call.
             */
            public function toMethodCall(): array
            {
                return [];
            }

            /**
             * Return the primitive data.
             */
            public function toArray(): array
            {
                return [];
            }

            /**
             * Capture the request and reject registration.
             */
            public function shouldRegister(Request $request): bool
            {
                $this->received = $request;

                return false;
            }
        };

        $request = new Request(['foo' => 'bar']);

        CoroutineContext::set(Request::class, $request);
        $result = $primitive->eligibleForRegistration();

        $this->assertFalse($result);
        $this->assertSame($request->all(), $primitive->received->all());
    }
}
