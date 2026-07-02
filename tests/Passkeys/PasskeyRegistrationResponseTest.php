<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Http\Request;
use Hypervel\Passkeys\Http\Responses\PasskeyRegistrationResponse;
use Hypervel\Passkeys\Passkey;

class PasskeyRegistrationResponseTest extends TestCase
{
    public function testWithPasskeyReturnsCloneWithoutMutatingOriginalResponse(): void
    {
        $firstPasskey = new Passkey([
            'name' => 'First key',
        ]);
        $firstPasskey->id = 1;

        $secondPasskey = new Passkey([
            'name' => 'Second key',
        ]);
        $secondPasskey->id = 2;

        $response = new PasskeyRegistrationResponse;
        $firstResponse = $response->withPasskey($firstPasskey);
        $secondResponse = $response->withPasskey($secondPasskey);

        $this->assertNotSame($response, $firstResponse);
        $this->assertNotSame($response, $secondResponse);

        $this->assertSame(
            ['status' => 'passkey-registered', 'id' => '1', 'name' => 'First key'],
            json_decode($firstResponse->toResponse(Request::create('/', server: ['HTTP_ACCEPT' => 'application/json']))->getContent(), true)
        );

        $this->assertSame(
            ['status' => 'passkey-registered', 'id' => '2', 'name' => 'Second key'],
            json_decode($secondResponse->toResponse(Request::create('/', server: ['HTTP_ACCEPT' => 'application/json']))->getContent(), true)
        );
    }
}
