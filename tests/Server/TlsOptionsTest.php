<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Server\TlsOptions;
use Hypervel\Tests\TestCase;

class TlsOptionsTest extends TestCase
{
    public function testLaravelStyleOptionsAreTranslatedWithoutDroppingFalseOrZero(): void
    {
        $options = TlsOptions::fromArray([
            'local_cert' => '/path/to/certificate.crt',
            'local_pk' => '/path/to/private.key',
            'passphrase' => 'secret',
            'verify_peer' => false,
            'allow_self_signed' => true,
            'cafile' => '/path/to/ca.pem',
            'ciphers' => 'HIGH:!aNULL:!MD5',
            'crypto_method' => 0,
        ]);

        $this->assertSame([
            'ssl_cert_file' => '/path/to/certificate.crt',
            'ssl_key_file' => '/path/to/private.key',
            'ssl_passphrase' => 'secret',
            'ssl_verify_peer' => false,
            'ssl_allow_self_signed' => true,
            'ssl_client_cert_file' => '/path/to/ca.pem',
            'ssl_ciphers' => 'HIGH:!aNULL:!MD5',
            'ssl_protocols' => 0,
        ], $options->settings());
        $this->assertTrue($options->enabled());
        $this->assertSame(SWOOLE_SOCK_TCP | SWOOLE_SSL, $options->socketType());
    }

    public function testNativeSwooleOptionsPassThroughAndUnknownOptionsAreIgnored(): void
    {
        $options = TlsOptions::fromArray([
            'ssl_cert_file' => '/path/to/certificate.crt',
            'ssl_key_file' => '/path/to/private.key',
            'ssl_ciphers' => 'TLS_AES_256_GCM_SHA384',
            'unknown' => 'ignored',
        ]);

        $this->assertSame([
            'ssl_cert_file' => '/path/to/certificate.crt',
            'ssl_key_file' => '/path/to/private.key',
            'ssl_ciphers' => 'TLS_AES_256_GCM_SHA384',
        ], $options->settings());
    }

    public function testNullOptionsAreFilteredWithoutEnablingTls(): void
    {
        $options = TlsOptions::fromArray([
            'local_cert' => null,
            'local_pk' => null,
            'verify_peer' => false,
        ]);

        $this->assertSame(['ssl_verify_peer' => false], $options->settings());
        $this->assertFalse($options->enabled());
        $this->assertSame(SWOOLE_SOCK_UDP, $options->socketType(SWOOLE_SOCK_UDP));
    }

    public function testEitherCertificateOrKeyEnablesTls(): void
    {
        $this->assertTrue(TlsOptions::fromArray(['local_cert' => 'certificate'])->enabled());
        $this->assertTrue(TlsOptions::fromArray(['local_pk' => 'key'])->enabled());
    }
}
