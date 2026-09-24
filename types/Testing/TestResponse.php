<?php

declare(strict_types=1);

use Hypervel\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function PHPStan\Testing\assertType;

$streamed = TestResponse::fromBaseResponse(new StreamedResponse);

assertType('Symfony\Component\HttpFoundation\StreamedResponse', $streamed->sendContent());

$assertResponseHelpers = static function (TestResponse $response): void {
    assertType('int', $response->status());
    assertType('mixed', $response->getOriginalContent());
};
