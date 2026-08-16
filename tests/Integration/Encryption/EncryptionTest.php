<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Encryption;

use Hypervel\Contracts\Encryption\DecryptException;
use Hypervel\Encryption\Encrypter;
use Hypervel\Encryption\EncryptionServiceProvider;
use Hypervel\Encryption\MissingAppKeyException;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Native;
use Laravel\SerializableClosure\Serializers\Signed;

#[WithConfig('app.key', 'base64:IUHRqAQ99pZ0A1MPjbuv1D6ff3jxv0GIvS2qIW4JNU4=')]
class EncryptionTest extends TestCase
{
    public function testEncryptionProviderBind(): void
    {
        $this->assertInstanceOf(Encrypter::class, $this->app->make('encrypter'));
    }

    public function testEncryptionWillNotBeInstantiableWhenMissingAppKey(): void
    {
        $this->expectExceptionObject(new MissingAppKeyException);

        $this->app->make('config')->set('app.key', null);

        $this->app->make('encrypter');
    }

    public function testEncryptionWillNotBeInstantiableWhenAppKeyConfigurationIsAbsent(): void
    {
        $config = $this->app->make('config');
        $appConfig = $config->array('app');
        unset($appConfig['key']);

        $config->set('app', $appConfig);

        $this->expectExceptionObject(new MissingAppKeyException);

        $this->app->make('encrypter');
    }

    public function testEncryptionWillNotBeInstantiableWhenCipherConfigurationIsAbsent(): void
    {
        $config = $this->app->make('config');
        $appConfig = $config->array('app');
        unset($appConfig['cipher']);

        $config->set('app', $appConfig);

        $this->expectExceptionObject(new InvalidArgumentException(
            'Configuration value for key [app.cipher] must be a string, NULL given.'
        ));

        $this->app->make('encrypter');
    }

    public function testEncryptionWillNotBeInstantiableWhenPreviousKeysConfigurationIsAbsent(): void
    {
        $config = $this->app->make('config');
        $appConfig = $config->array('app');
        unset($appConfig['previous_keys']);

        $config->set('app', $appConfig);

        $this->expectExceptionObject(new InvalidArgumentException(
            'Configuration value for key [app.previous_keys] must be an array, NULL given.'
        ));

        $this->app->make('encrypter');
    }

    public function testEncryptionProviderConfiguresSerializableClosureSigner(): void
    {
        (new EncryptionServiceProvider($this->app))->register();

        $serializable = new SerializableClosure(static fn (): string => 'value');

        $this->assertInstanceOf(Signed::class, $serializable->__serialize()['serializable']);
    }

    public function testReloadConfigurationReplacesKeyDerivedEncryptionState(): void
    {
        $provider = new EncryptionServiceProvider($this->app);
        $encrypter = $this->app->make('encrypter');
        $encrypted = $encrypter->encryptString('value');
        $closure = static fn (): string => 'value';
        $signedClosure = serialize(new SerializableClosure($closure));

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('b', 32)),
            'app.previous_keys' => [],
        ]);
        $provider->reloadConfiguration();

        $refreshedEncrypter = $this->app->make('encrypter');
        $this->assertNotSame($encrypter, $refreshedEncrypter);
        $this->assertNotSame($signedClosure, serialize(new SerializableClosure($closure)));
        $this->assertSame('fresh', $refreshedEncrypter->decryptString(
            $refreshedEncrypter->encryptString('fresh'),
        ));

        $this->expectException(DecryptException::class);

        $refreshedEncrypter->decryptString($encrypted);
    }

    public function testReloadConfigurationClearsStaleSerializableClosureSignerWhenKeyIsMissing(): void
    {
        SerializableClosure::setSecretKey('stale-key');
        $this->app->make('config')->set('app.key', null);

        (new EncryptionServiceProvider($this->app))->reloadConfiguration();

        $serializable = new SerializableClosure(static fn (): string => 'value');

        $this->assertInstanceOf(Native::class, $serializable->__serialize()['serializable']);
    }
}
