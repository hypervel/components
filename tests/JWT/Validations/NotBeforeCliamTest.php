<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Validations;

use Carbon\Carbon;
use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\JWT\Validations\NotBeforeCliam;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class NotBeforeCliamTest extends TestCase
{
    public function testValid()
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->expectNotToPerformAssertions();

        $validation = new NotBeforeCliam(['leeway' => 3600]);

        $validation->validate([]);
        $validation->validate(['nbf' => Carbon::now()->timestamp - 3600]);
        $validation->validate(['nbf' => Carbon::now()->timestamp + 3600]);
    }

    public function testInvalid()
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Not Before (nbf) timestamp cannot be in the future');

        $validation = new NotBeforeCliam();

        $validation->validate(['nbf' => Carbon::now()->timestamp + 3600]);
    }
}
