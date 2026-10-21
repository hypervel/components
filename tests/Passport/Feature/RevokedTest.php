<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Passport\Bridge\AccessToken;
use Hypervel\Passport\Bridge\AccessTokenRepository as BridgeAccessTokenRepository;
use Hypervel\Passport\Bridge\AuthCode;
use Hypervel\Passport\Bridge\AuthCodeRepository as BridgeAuthCodeRepository;
use Hypervel\Passport\Bridge\Client;
use Hypervel\Passport\Bridge\DeviceCode;
use Hypervel\Passport\Bridge\DeviceCodeRepository as BridgeDeviceCodeRepository;
use Hypervel\Passport\Bridge\RefreshToken;
use Hypervel\Passport\Bridge\RefreshTokenRepository as BridgeRefreshTokenRepository;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;

class RevokedTest extends PassportTestCase
{
    use WithHypervelMigrations;

    public function testItCanDetermineIfAAccessTokenIsRevoked(): void
    {
        $repository = $this->accessTokenRepository();
        $this->persistNewAccessToken($repository, 'tokenId');

        $repository->revokeAccessToken('tokenId');

        $this->assertTrue($repository->isAccessTokenRevoked('tokenId'));
    }

    public function testAAccessTokenIsAlsoRevokedIfItCannotBeFound(): void
    {
        $repository = $this->accessTokenRepository();

        $this->assertTrue($repository->isAccessTokenRevoked('notExistingTokenId'));
    }

    public function testItCanDetermineIfAAccessTokenIsNotRevoked(): void
    {
        $repository = $this->accessTokenRepository();
        $this->persistNewAccessToken($repository, 'tokenId');

        $this->assertFalse($repository->isAccessTokenRevoked('tokenId'));
    }

    public function testItCanDetermineIfAAuthCodeIsRevoked(): void
    {
        $repository = $this->authCodeRepository();
        $this->persistNewAuthCode($repository, 'tokenId');

        $repository->revokeAuthCode('tokenId');

        $this->assertTrue($repository->isAuthCodeRevoked('tokenId'));
    }

    public function testAAuthCodeIsAlsoRevokedIfItCannotBeFound(): void
    {
        $repository = $this->authCodeRepository();

        $this->assertTrue($repository->isAuthCodeRevoked('notExistingTokenId'));
    }

    public function testItCanDetermineIfAAuthCodeIsNotRevoked(): void
    {
        $repository = $this->authCodeRepository();
        $this->persistNewAuthCode($repository, 'tokenId');

        $this->assertFalse($repository->isAuthCodeRevoked('tokenId'));
    }

    public function testItCanDetermineIfARefreshTokenIsRevoked(): void
    {
        $repository = $this->refreshTokenRepository();
        $this->persistNewRefreshToken($repository, 'tokenId');

        $repository->revokeRefreshToken('tokenId');

        $this->assertTrue($repository->isRefreshTokenRevoked('tokenId'));
    }

    public function testARefreshTokenIsAlsoRevokedIfItCannotBeFound(): void
    {
        $repository = $this->refreshTokenRepository();

        $this->assertTrue($repository->isRefreshTokenRevoked('notExistingTokenId'));
    }

    public function testItCanDetermineIfARefreshTokenIsNotRevoked(): void
    {
        $repository = $this->refreshTokenRepository();
        $this->persistNewRefreshToken($repository, 'tokenId');

        $this->assertFalse($repository->isRefreshTokenRevoked('tokenId'));
    }

    public function testItCanDetermineIfADeviceCodeIsRevoked(): void
    {
        $repository = $this->deviceCodeRepository();
        $this->persistNewDeviceCode($repository, 'deviceCodeId');

        $repository->revokeDeviceCode('deviceCodeId');

        $this->assertTrue($repository->isDeviceCodeRevoked('deviceCodeId'));
    }

    public function testADeviceCodeIsAlsoRevokedIfItCannotBeFound(): void
    {
        $repository = $this->deviceCodeRepository();

        $this->assertTrue($repository->isDeviceCodeRevoked('notExistingDeviceCodeId'));
    }

    public function testItCanDetermineIfADeviceCodeIsNotRevoked(): void
    {
        $repository = $this->deviceCodeRepository();
        $this->persistNewDeviceCode($repository, 'deviceCodeId');

        $this->assertFalse($repository->isDeviceCodeRevoked('deviceCodeId'));
    }

    /**
     * Create the access token repository.
     */
    private function accessTokenRepository(): BridgeAccessTokenRepository
    {
        return new BridgeAccessTokenRepository(app('events'));
    }

    /**
     * Store an access token for an active client.
     */
    private function persistNewAccessToken(BridgeAccessTokenRepository $repository, string $id): void
    {
        $client = ClientFactory::new()->create();
        $accessToken = new AccessToken('1', [], new Client($client->getKey()));
        $accessToken->setIdentifier($id);
        $accessToken->setExpiryDateTime(CarbonImmutable::now());

        $repository->persistNewAccessToken($accessToken);
    }

    /**
     * Create the authorization code repository.
     */
    private function authCodeRepository(): BridgeAuthCodeRepository
    {
        return new BridgeAuthCodeRepository;
    }

    /**
     * Store an authorization code.
     */
    private function persistNewAuthCode(BridgeAuthCodeRepository $repository, string $id): void
    {
        $authCode = new AuthCode;
        $authCode->setIdentifier($id);
        $authCode->setUserIdentifier('1');
        $authCode->setClient(new Client('clientId'));
        $authCode->setExpiryDateTime(CarbonImmutable::now());

        $repository->persistNewAuthCode($authCode);
    }

    /**
     * Create the refresh token repository.
     */
    private function refreshTokenRepository(): BridgeRefreshTokenRepository
    {
        return new BridgeRefreshTokenRepository(app('events'));
    }

    /**
     * Store a refresh token.
     */
    private function persistNewRefreshToken(BridgeRefreshTokenRepository $repository, string $id): void
    {
        $accessToken = new AccessToken('1', [], new Client('clientId'));
        $accessToken->setIdentifier('accessTokenId');

        $refreshToken = new RefreshToken;
        $refreshToken->setIdentifier($id);
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime(CarbonImmutable::now());

        $repository->persistNewRefreshToken($refreshToken);
    }

    /**
     * Create the device code repository.
     */
    private function deviceCodeRepository(): BridgeDeviceCodeRepository
    {
        return new BridgeDeviceCodeRepository;
    }

    /**
     * Store a device code.
     */
    private function persistNewDeviceCode(BridgeDeviceCodeRepository $repository, string $id): void
    {
        $deviceCode = new DeviceCode($id, clientIdentifier: 'clientId', expiryDateTime: CarbonImmutable::now());
        $deviceCode->setUserCode('userCode');

        $repository->persistDeviceCode($deviceCode);
    }
}
