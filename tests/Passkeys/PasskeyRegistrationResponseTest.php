<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Http\Request;
use Hypervel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
use Hypervel\Passkeys\Http\Responses\PasskeyRegistrationResponse;
use Hypervel\Passkeys\Passkey;
use PHPUnit\Framework\Attributes\DataProvider;

class PasskeyRegistrationResponseTest extends TestCase
{
    /**
     * @param class-string<PasskeyRegistrationResponseContract> $abstract
     */
    #[DataProvider('registrationResponseAbstracts')]
    public function testEachResolvedResponseRetainsItsOwnPasskey(string $abstract): void
    {
        $firstPasskey = new Passkey([
            'name' => 'First key',
        ]);
        $firstPasskey->id = 1;

        $secondPasskey = new Passkey([
            'name' => 'Second key',
        ]);
        $secondPasskey->id = 2;

        $firstResponse = $this->app->make($abstract);
        $secondResponse = $this->app->make($abstract);

        $firstResponse->withPasskey($firstPasskey);
        $secondResponse->withPasskey($secondPasskey);

        $this->assertSame(
            ['status' => 'passkey-registered', 'id' => '1', 'name' => 'First key'],
            json_decode($firstResponse->toResponse(Request::create('/', server: ['HTTP_ACCEPT' => 'application/json']))->getContent(), true)
        );

        $this->assertSame(
            ['status' => 'passkey-registered', 'id' => '2', 'name' => 'Second key'],
            json_decode($secondResponse->toResponse(Request::create('/', server: ['HTTP_ACCEPT' => 'application/json']))->getContent(), true)
        );
    }

    /**
     * Get the container keys that resolve the registration response.
     *
     * @return array<string, array{class-string<PasskeyRegistrationResponseContract>}>
     */
    public static function registrationResponseAbstracts(): array
    {
        return [
            'contract' => [PasskeyRegistrationResponseContract::class],
            'concrete response' => [PasskeyRegistrationResponse::class],
        ];
    }
}
