<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Http\Response;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use JsonSerializable;

class ResponseTest extends TestCase
{
    public function testResponseWithInvalidJsonThrowsException(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Malformed UTF-8 characters, possibly incorrectly encoded'));

        Route::get('/response', function (): Response {
            return (new Response)->setContent(new class implements JsonSerializable {
                /**
                 * Return malformed UTF-8 text.
                 */
                public function jsonSerialize(): string
                {
                    return "\xB1\x31";
                }
            });
        });

        $this->withoutExceptionHandling();

        $this->get('/response');
    }
}
