<?php

declare(strict_types=1);

namespace Hypervel\Tests\Encryption;

use Hypervel\Contracts\Encryption\Encrypter as EncrypterContract;
use Hypervel\Contracts\Encryption\StringEncrypter as StringEncrypterContract;
use Hypervel\Encryption\Commands\KeyGenerateCommand;
use Hypervel\Encryption\Encrypter;
use Hypervel\Encryption\EncryptionServiceProvider;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;
use ReflectionMethod;
use SensitiveParameter;

class SensitiveParameterTest extends TestCase
{
    public function testEncryptHelperPlaintextIsRedacted(): void
    {
        $parameter = (new ReflectionFunction('encrypt'))->getParameters()[0];

        $this->assertSame('value', $parameter->getName());
        $this->assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
    }

    #[DataProvider('sensitiveParameters')]
    public function testSecretBearingParametersAreRedacted(
        string $class,
        string $method,
        string $parameterName
    ): void {
        $parameters = (new ReflectionMethod($class, $method))->getParameters();
        $parameter = null;

        foreach ($parameters as $candidate) {
            if ($candidate->getName() === $parameterName) {
                $parameter = $candidate;

                break;
            }
        }

        $this->assertNotNull($parameter);
        $this->assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
    }

    /**
     * Provide every named method parameter that can carry plaintext or key material.
     *
     * @return array<string, array{class-string, string, string}>
     */
    public static function sensitiveParameters(): array
    {
        return [
            'encrypter contract plaintext' => [EncrypterContract::class, 'encrypt', 'value'],
            'string encrypter contract plaintext' => [StringEncrypterContract::class, 'encryptString', 'value'],
            'constructor key' => [Encrypter::class, '__construct', 'key'],
            'supported key' => [Encrypter::class, 'supported', 'key'],
            'encrypt plaintext' => [Encrypter::class, 'encrypt', 'value'],
            'encrypt string plaintext' => [Encrypter::class, 'encryptString', 'value'],
            'hash iv' => [Encrypter::class, 'hash', 'iv'],
            'hash value' => [Encrypter::class, 'hash', 'value'],
            'hash key' => [Encrypter::class, 'hash', 'key'],
            'mac payload' => [Encrypter::class, 'validMacForKey', 'payload'],
            'mac key' => [Encrypter::class, 'validMacForKey', 'key'],
            'previous keys' => [Encrypter::class, 'previousKeys', 'keys'],
            'provider parse config' => [EncryptionServiceProvider::class, 'parseKey', 'config'],
            'provider key config' => [EncryptionServiceProvider::class, 'key', 'config'],
            'command set key' => [KeyGenerateCommand::class, 'setKeyInEnvironmentFile', 'key'],
            'command write key' => [KeyGenerateCommand::class, 'writeNewEnvironmentFileWith', 'key'],
            'atomic replacement contents' => [Filesystem::class, 'replace', 'content'],
            'crypt facade arguments' => [Crypt::class, '__callStatic', 'args'],
        ];
    }
}
