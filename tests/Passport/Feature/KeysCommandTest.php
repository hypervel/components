<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Filesystem\Filesystem;
use OpenSSLAsymmetricKey;

class KeysCommandTest extends PassportTestCase
{
    public function testPrivateAndPublicKeysAreGenerated(): void
    {
        (new Filesystem)->delete([
            $this->keysDirectory . '/oauth-public.key',
            $this->keysDirectory . '/oauth-private.key',
        ]);

        $this->artisan('passport:keys', ['--length' => 2048])
            ->expectsOutputToContain('Encryption keys generated successfully.')
            ->expectsOutputToContain('Restart the server')
            ->assertSuccessful();

        $this->assertFileExists($this->keysDirectory . '/oauth-public.key');
        $this->assertFileExists($this->keysDirectory . '/oauth-private.key');

        $privateKey = openssl_pkey_get_private(file_get_contents($this->keysDirectory . '/oauth-private.key'));
        $publicKey = openssl_pkey_get_public(file_get_contents($this->keysDirectory . '/oauth-public.key'));
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $privateKey);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $publicKey);
        $this->assertSame(2048, openssl_pkey_get_details($privateKey)['bits']);
        $this->assertTrue(openssl_sign('passport', $signature, $privateKey, OPENSSL_ALGO_SHA256));
        $this->assertSame(1, openssl_verify('passport', $signature, $publicKey, OPENSSL_ALGO_SHA256));
    }

    public function testPrivateAndPublicKeysShouldNotBeGeneratedTwice(): void
    {
        $this->artisan('passport:keys')
            ->assertFailed()
            ->expectsOutputToContain('Encryption keys already exist. Use the --force option to overwrite them.');
    }

    public function testForcedReplacementUsesTheIntendedFilePermissions(): void
    {
        $privatePath = $this->keysDirectory . '/oauth-private.key';
        $publicPath = $this->keysDirectory . '/oauth-public.key';
        $previousKey = file_get_contents($publicPath);
        chmod($privatePath, 0644);
        chmod($publicPath, 0644);

        $this->artisan('passport:keys', ['--force' => true, '--length' => 2048])->assertSuccessful();

        clearstatcache();
        $this->assertSame(0600, fileperms($privatePath) & 0777);
        $this->assertSame(0660, fileperms($publicPath) & 0777);
        $this->assertNotSame($previousKey, file_get_contents($publicPath));
    }
}
