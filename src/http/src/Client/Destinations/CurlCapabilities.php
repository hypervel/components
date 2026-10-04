<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Destinations;

class CurlCapabilities
{
    protected const string MINIMUM_PINNING_VERSION = '7.75.0';

    protected const string MINIMUM_TLS_1_2_VERSION = '7.34.0';

    protected const string MINIMUM_HTTPS_PROXY_VERSION = '7.52.0';

    /**
     * Indicates whether the cURL transport has passed the pinning check.
     */
    protected static bool $pinningSupported = false;

    /**
     * The libcurl version and feature bits of this process.
     *
     * @var null|array{version: string, features: int}|false
     */
    protected static array|false|null $versionInfo = null;

    /**
     * Ensure the cURL transport can pin connections to vetted addresses.
     *
     * @throws DestinationPolicyException
     */
    public static function ensurePinningSupported(): void
    {
        if (static::$pinningSupported) {
            return;
        }

        $version = static::versionInfo();

        if ($version === false) {
            static::fail('a usable cURL extension and curl_version() result', null);
        }

        if (version_compare($version['version'], self::MINIMUM_PINNING_VERSION, '<')) {
            static::fail('libcurl ' . self::MINIMUM_PINNING_VERSION . ' or newer', $version['version']);
        }

        if (! function_exists('curl_exec') && ! function_exists('curl_multi_exec')) {
            static::fail('curl_exec() or curl_multi_exec()', $version['version']);
        }

        foreach (['CURLOPT_CONNECT_TO', 'CURLOPT_RESOLVE'] as $constant) {
            if (static::integerConstant($constant) === null) {
                static::fail("the {$constant} cURL option", $version['version']);
            }
        }

        $sslFeature = static::integerConstant('CURL_VERSION_SSL');

        if ($sslFeature === null || ($sslFeature & $version['features']) === 0) {
            static::fail('cURL SSL support', $version['version']);
        }

        if (version_compare($version['version'], self::MINIMUM_TLS_1_2_VERSION, '<')
            || static::integerConstant('CURL_SSLVERSION_TLSv1_2') === null) {
            static::fail('cURL TLS 1.2 support', $version['version']);
        }

        static::$pinningSupported = true;
    }

    /**
     * Ensure an HTTPS proxy cannot be silently downgraded to plaintext.
     *
     * @throws DestinationPolicyException
     */
    public static function ensureHttpsProxySupported(string $host, int $port): void
    {
        $version = static::versionInfo();
        $feature = static::integerConstant('CURL_VERSION_HTTPS_PROXY') ?? 1 << 21;

        if ($version === false
            || version_compare($version['version'], self::MINIMUM_HTTPS_PROXY_VERSION, '<')
            || ($feature & $version['features']) === 0) {
            static::fail(
                "HTTPS proxy support for [https://{$host}:{$port}]",
                $version === false ? null : $version['version'],
            );
        }
    }

    /**
     * Read the process's libcurl version information once per worker.
     *
     * @return array{version: string, features: int}|false
     */
    protected static function versionInfo(): array|false
    {
        if (static::$versionInfo !== null) {
            return static::$versionInfo;
        }

        if (! function_exists('curl_version')) {
            return static::$versionInfo = false;
        }

        $version = curl_version();

        if (! is_array($version)
            || ! isset($version['version'], $version['features'])
            || ! is_string($version['version'])
            || ! is_int($version['features'])) {
            return static::$versionInfo = false;
        }

        return static::$versionInfo = [
            'version' => $version['version'],
            'features' => $version['features'],
        ];
    }

    /**
     * Return an integer cURL constant when the extension exposes it correctly.
     */
    protected static function integerConstant(string $name): ?int
    {
        if (! defined($name)) {
            return null;
        }

        $value = constant($name);

        return is_int($value) ? $value : null;
    }

    /**
     * Throw an error naming the missing capability.
     *
     * @throws DestinationPolicyException
     */
    protected static function fail(string $capability, ?string $version): never
    {
        throw new DestinationPolicyException(sprintf(
            'Destination-restricted HTTP requests require %s; the observed libcurl version is [%s].',
            $capability,
            $version ?? 'unavailable',
        ));
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$pinningSupported = false;
        static::$versionInfo = null;
    }
}
