<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Http\JsonResponse;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use JsonSerializable;

class JsonResponseTest extends TestCase
{
    public function testResponseWithInvalidJsonThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed UTF-8 characters, possibly incorrectly encoded');

        Route::get('/response', function () {
            return new JsonResponse(new class implements JsonSerializable {
                public function jsonSerialize(): string
                {
                    return "\xB1\x31";
                }
            });
        });

        $this->withoutExceptionHandling();

        $this->get('/response');
    }

    public function testResponseSetDataPassesWithPriorJsonErrors(): void
    {
        $response = new JsonResponse;

        // Trigger json_last_error() to have a non-zero value...
        json_encode(['a' => acos(2)]);

        $response->setData(new class implements Jsonable {
            public function toJson(int $options = 0): string
            {
                return '{}';
            }
        });

        $this->assertJson($response->getContent());
    }
}
