<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Http\Cors;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use TypeError;

/**
 * @phpstan-type CorsNormalizedOptions array{
 *  'allowedOrigins': string[],
 *  'allowedOriginsPatterns': string[],
 *  'supportsCredentials': bool,
 *  'allowedHeaders': string[],
 *  'allowedMethods': string[],
 *  'exposedHeaders': string[],
 *  'maxAge': int|bool|null,
 *  'allowAllOrigins': bool,
 *  'allowAllHeaders': bool,
 *  'allowAllMethods': bool,
 * }
 * @internal
 * @coversNothing
 */
class CorsTest extends TestCase
{
    public function testCanHaveOptions(): void
    {
        $options = [
            'allowedOrigins' => ['localhost'],
            'allowedOriginsPatterns' => ['/something/'],
            'allowedHeaders' => ['x-custom'],
            'allowedMethods' => ['PUT'],
            'maxAge' => 684,
            'supportsCredentials' => true,
            'exposedHeaders' => ['x-custom-2'],
        ];

        $service = new Cors($options);

        $normalized = $this->getOptionsFromService($service);

        $this->assertEquals($options['allowedOrigins'], $normalized['allowedOrigins']);
        $this->assertEquals($options['allowedOriginsPatterns'], $normalized['allowedOriginsPatterns']);
        $this->assertEquals($options['allowedHeaders'], $normalized['allowedHeaders']);
        $this->assertEquals($options['allowedMethods'], $normalized['allowedMethods']);
        $this->assertEquals($options['maxAge'], $normalized['maxAge']);
        $this->assertEquals($options['supportsCredentials'], $normalized['supportsCredentials']);
        $this->assertEquals($options['exposedHeaders'], $normalized['exposedHeaders']);
    }

    public function testCanSetOptions(): void
    {
        $service = new Cors();
        $normalized = $this->getOptionsFromService($service);
        $this->assertEquals([], $normalized['allowedOrigins']);

        $options = [
            'allowedOrigins' => ['localhost'],
            'allowedOriginsPatterns' => ['/something/'],
            'allowedHeaders' => ['x-custom'],
            'allowedMethods' => ['PUT'],
            'maxAge' => 684,
            'supportsCredentials' => true,
            'exposedHeaders' => ['x-custom-2'],
        ];

        $service->setOptions($options);

        $normalized = $this->getOptionsFromService($service);

        $this->assertEquals($options['allowedOrigins'], $normalized['allowedOrigins']);
        $this->assertEquals($options['allowedOriginsPatterns'], $normalized['allowedOriginsPatterns']);
        $this->assertEquals($options['allowedHeaders'], $normalized['allowedHeaders']);
        $this->assertEquals($options['allowedMethods'], $normalized['allowedMethods']);
        $this->assertEquals($options['maxAge'], $normalized['maxAge']);
        $this->assertEquals($options['supportsCredentials'], $normalized['supportsCredentials']);
        $this->assertEquals($options['exposedHeaders'], $normalized['exposedHeaders']);
    }

    public function testCanOverwriteSetOptions(): void
    {
        $service = new Cors(['allowedOrigins' => ['example.com']]);
        $normalized = $this->getOptionsFromService($service);

        $this->assertEquals(['example.com'], $normalized['allowedOrigins']);

        $options = [
            'allowedOrigins' => ['localhost'],
            'allowedOriginsPatterns' => ['/something/'],
            'allowedHeaders' => ['x-custom'],
            'allowedMethods' => ['PUT'],
            'maxAge' => 684,
            'supportsCredentials' => true,
            'exposedHeaders' => ['x-custom-2'],
        ];

        $service->setOptions($options);

        $normalized = $this->getOptionsFromService($service);

        $this->assertEquals($options['allowedOrigins'], $normalized['allowedOrigins']);
        $this->assertEquals($options['allowedOriginsPatterns'], $normalized['allowedOriginsPatterns']);
        $this->assertEquals($options['allowedHeaders'], $normalized['allowedHeaders']);
        $this->assertEquals($options['allowedMethods'], $normalized['allowedMethods']);
        $this->assertEquals($options['maxAge'], $normalized['maxAge']);
        $this->assertEquals($options['supportsCredentials'], $normalized['supportsCredentials']);
        $this->assertEquals($options['exposedHeaders'], $normalized['exposedHeaders']);
    }

    public function testCanHaveNoOptions(): void
    {
        $service = new Cors();
        $normalized = $this->getOptionsFromService($service);

        $this->assertEquals([], $normalized['allowedOrigins']);
        $this->assertEquals([], $normalized['allowedOriginsPatterns']);
        $this->assertEquals([], $normalized['allowedHeaders']);
        $this->assertEquals([], $normalized['allowedMethods']);
        $this->assertEquals([], $normalized['exposedHeaders']);
        $this->assertEquals(0, $normalized['maxAge']);
        $this->assertFalse($normalized['supportsCredentials']);
    }

    public function testCanHaveEmptyOptions(): void
    {
        $service = new Cors([]);
        $normalized = $this->getOptionsFromService($service);

        $this->assertEquals([], $normalized['allowedOrigins']);
        $this->assertEquals([], $normalized['allowedOriginsPatterns']);
        $this->assertEquals([], $normalized['allowedHeaders']);
        $this->assertEquals([], $normalized['allowedMethods']);
        $this->assertEquals([], $normalized['exposedHeaders']);
        $this->assertEquals(0, $normalized['maxAge']);
        $this->assertFalse($normalized['supportsCredentials']);
    }

    public function testNormalizesFalseExposedHeaders(): void
    {
        $service = new Cors(['exposedHeaders' => false]);
        $this->assertEquals([], $this->getOptionsFromService($service)['exposedHeaders']);
    }

    public function testAllowsNullMaxAge(): void
    {
        $service = new Cors(['maxAge' => null]);
        $this->assertNull($this->getOptionsFromService($service)['maxAge']);
    }

    public function testAllowsZeroMaxAge(): void
    {
        $service = new Cors(['maxAge' => 0]);
        $this->assertEquals(0, $this->getOptionsFromService($service)['maxAge']);
    }

    public function testThrowsExceptionOnInvalidExposedHeaders(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore-next-line */
        $service = new Cors(['exposedHeaders' => true]);
    }

    public function testThrowsExceptionOnInvalidOriginsArray(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore-next-line */
        $service = new Cors(['allowedOrigins' => 'string']);
    }

    public function testNormalizesWildcardOrigins(): void
    {
        $service = new Cors(['allowedOrigins' => ['*']]);
        $this->assertTrue($this->getOptionsFromService($service)['allowAllOrigins']);
    }

    public function testNormalizesWildcardHeaders(): void
    {
        $service = new Cors(['allowedHeaders' => ['*']]);
        $this->assertTrue($this->getOptionsFromService($service)['allowAllHeaders']);
    }

    public function testNormalizesWildcardMethods(): void
    {
        $service = new Cors(['allowedMethods' => ['*']]);
        $this->assertTrue($this->getOptionsFromService($service)['allowAllMethods']);
    }

    public function testConvertsWildcardOriginPatterns(): void
    {
        $service = new Cors(['allowedOrigins' => ['*.mydomain.com']]);

        $patterns = $this->getOptionsFromService($service)['allowedOriginsPatterns'];
        $this->assertEquals(['#^.*\.mydomain\.com\z#u'], $patterns);
    }

    public function testNormalizesUnderscoreOptions(): void
    {
        $options = [
            'allowed_origins' => ['localhost'],
            'allowed_origins_patterns' => ['/something/'],
            'allowed_headers' => ['x-custom'],
            'allowed_methods' => ['PUT'],
            'max_age' => 684,
            'supports_credentials' => true,
            'exposed_headers' => ['x-custom-2'],
        ];

        $service = new Cors($options);

        $this->assertEquals($options['allowed_origins'], $this->getOptionsFromService($service)['allowedOrigins']);
        $this->assertEquals(
            $options['allowed_origins_patterns'],
            $this->getOptionsFromService($service)['allowedOriginsPatterns']
        );
        $this->assertEquals($options['allowed_headers'], $this->getOptionsFromService($service)['allowedHeaders']);
        $this->assertEquals($options['allowed_methods'], $this->getOptionsFromService($service)['allowedMethods']);
        $this->assertEquals($options['exposed_headers'], $this->getOptionsFromService($service)['exposedHeaders']);
        $this->assertEquals($options['max_age'], $this->getOptionsFromService($service)['maxAge']);
        $this->assertEquals(
            $options['supports_credentials'],
            $this->getOptionsFromService($service)['supportsCredentials']
        );
    }

    /**
     * @return CorsNormalizedOptions
     */
    private function getOptionsFromService(Cors $service): array
    {
        $reflected = new ReflectionClass($service);

        $properties = $reflected->getProperties(ReflectionProperty::IS_PRIVATE);

        $options = [];
        foreach ($properties as $property) {
            $property->setAccessible(true);
            $options[$property->getName()] = $property->getValue($service);
        }

        /** @var CorsNormalizedOptions $options */
        return $options;
    }
}
