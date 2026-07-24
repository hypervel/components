<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Encryption;

use Hypervel\Encryption\Encrypter;
use Hypervel\Encryption\EncryptionServiceProvider;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Native;
use Laravel\SerializableClosure\Serializers\Signed;
use RuntimeException;

#[WithConfig('app.key', 'base64:IUHRqAQ99pZ0A1MPjbuv1D6ff3jxv0GIvS2qIW4JNU4=')]
class EncryptionTest extends TestCase
{
    public function testEncryptionProviderBind()
    {
        $this->assertInstanceOf(Encrypter::class, $this->app->make('encrypter'));
    }

    public function testEncryptionWillNotBeInstantiableWhenMissingAppKey()
    {
        $this->expectException(RuntimeException::class);

        $this->app['config']->set('app.key', null);

        $this->app->make('encrypter');
    }

    public function testEncryptionProviderConfiguresSerializableClosureSigner(): void
    {
        (new EncryptionServiceProvider($this->app))->register();

        $serializable = new SerializableClosure(static fn (): string => 'value');

        $this->assertInstanceOf(Signed::class, $serializable->__serialize()['serializable']);
    }

    public function testEncryptionProviderClearsStaleSerializableClosureSignerWhenKeyIsMissing(): void
    {
        SerializableClosure::setSecretKey('stale-key');
        $this->app['config']->set('app.key', null);

        (new EncryptionServiceProvider($this->app))->register();

        $serializable = new SerializableClosure(static fn (): string => 'value');

        $this->assertInstanceOf(Native::class, $serializable->__serialize()['serializable']);
    }
}
