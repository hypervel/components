<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hyperf\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Http\Cors;
use Hypervel\Http\CorsOptions;
use Hypervel\Tests\TestCase;
use TypeError;

/**
 * @internal
 * @coversNothing
 */
class CorsTest extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Context key used by Cors class.
     */
    private const CORS_CONTEXT_KEY = '__cors.options';

    protected function tearDown(): void
    {
        // Clean up context between tests
        Context::destroy(self::CORS_CONTEXT_KEY);
        parent::tearDown();
    }

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

        $corsOptions = $this->getOptionsFromContext();

        $this->assertEquals($options['allowedOrigins'], $corsOptions->allowedOrigins);
        $this->assertEquals($options['allowedOriginsPatterns'], $corsOptions->allowedOriginsPatterns);
        $this->assertEquals(['x-custom'], $corsOptions->allowedHeaders); // lowercased
        $this->assertEquals($options['allowedMethods'], $corsOptions->allowedMethods);
        $this->assertEquals($options['maxAge'], $corsOptions->maxAge);
        $this->assertEquals($options['supportsCredentials'], $corsOptions->supportsCredentials);
        $this->assertEquals($options['exposedHeaders'], $corsOptions->exposedHeaders);
    }

    public function testCanSetOptions(): void
    {
        $service = new Cors();
        $corsOptions = $this->getOptionsFromContext();
        $this->assertEquals([], $corsOptions->allowedOrigins);

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

        $corsOptions = $this->getOptionsFromContext();

        $this->assertEquals($options['allowedOrigins'], $corsOptions->allowedOrigins);
        $this->assertEquals($options['allowedOriginsPatterns'], $corsOptions->allowedOriginsPatterns);
        $this->assertEquals(['x-custom'], $corsOptions->allowedHeaders); // lowercased
        $this->assertEquals($options['allowedMethods'], $corsOptions->allowedMethods);
        $this->assertEquals($options['maxAge'], $corsOptions->maxAge);
        $this->assertEquals($options['supportsCredentials'], $corsOptions->supportsCredentials);
        $this->assertEquals($options['exposedHeaders'], $corsOptions->exposedHeaders);
    }

    public function testCanOverwriteSetOptions(): void
    {
        $service = new Cors(['allowedOrigins' => ['example.com']]);
        $corsOptions = $this->getOptionsFromContext();

        $this->assertEquals(['example.com'], $corsOptions->allowedOrigins);

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

        $corsOptions = $this->getOptionsFromContext();

        $this->assertEquals($options['allowedOrigins'], $corsOptions->allowedOrigins);
        $this->assertEquals($options['allowedOriginsPatterns'], $corsOptions->allowedOriginsPatterns);
        $this->assertEquals(['x-custom'], $corsOptions->allowedHeaders); // lowercased
        $this->assertEquals($options['allowedMethods'], $corsOptions->allowedMethods);
        $this->assertEquals($options['maxAge'], $corsOptions->maxAge);
        $this->assertEquals($options['supportsCredentials'], $corsOptions->supportsCredentials);
        $this->assertEquals($options['exposedHeaders'], $corsOptions->exposedHeaders);
    }

    public function testCanHaveNoOptions(): void
    {
        $service = new Cors();
        $corsOptions = $this->getOptionsFromContext();

        $this->assertEquals([], $corsOptions->allowedOrigins);
        $this->assertEquals([], $corsOptions->allowedOriginsPatterns);
        $this->assertEquals([], $corsOptions->allowedHeaders);
        $this->assertEquals([], $corsOptions->allowedMethods);
        $this->assertEquals([], $corsOptions->exposedHeaders);
        $this->assertEquals(0, $corsOptions->maxAge);
        $this->assertFalse($corsOptions->supportsCredentials);
    }

    public function testCanHaveEmptyOptions(): void
    {
        $service = new Cors([]);
        $corsOptions = $this->getOptionsFromContext();

        $this->assertEquals([], $corsOptions->allowedOrigins);
        $this->assertEquals([], $corsOptions->allowedOriginsPatterns);
        $this->assertEquals([], $corsOptions->allowedHeaders);
        $this->assertEquals([], $corsOptions->allowedMethods);
        $this->assertEquals([], $corsOptions->exposedHeaders);
        $this->assertEquals(0, $corsOptions->maxAge);
        $this->assertFalse($corsOptions->supportsCredentials);
    }

    public function testNormalizesFalseExposedHeaders(): void
    {
        $service = new Cors(['exposedHeaders' => false]);
        $this->assertEquals([], $this->getOptionsFromContext()->exposedHeaders);
    }

    public function testAllowsNullMaxAge(): void
    {
        $service = new Cors(['maxAge' => null]);
        $this->assertNull($this->getOptionsFromContext()->maxAge);
    }

    public function testAllowsZeroMaxAge(): void
    {
        $service = new Cors(['maxAge' => 0]);
        $this->assertEquals(0, $this->getOptionsFromContext()->maxAge);
    }

    public function testThrowsExceptionOnInvalidExposedHeaders(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore argument.type */
        $service = new Cors(['exposedHeaders' => true]);
    }

    public function testThrowsExceptionOnInvalidOriginsArray(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore argument.type */
        $service = new Cors(['allowedOrigins' => 'string']);
    }

    public function testNormalizesWildcardOrigins(): void
    {
        $service = new Cors(['allowedOrigins' => ['*']]);
        $this->assertTrue($this->getOptionsFromContext()->allowAllOrigins);
    }

    public function testNormalizesWildcardHeaders(): void
    {
        $service = new Cors(['allowedHeaders' => ['*']]);
        $this->assertTrue($this->getOptionsFromContext()->allowAllHeaders);
    }

    public function testNormalizesWildcardMethods(): void
    {
        $service = new Cors(['allowedMethods' => ['*']]);
        $this->assertTrue($this->getOptionsFromContext()->allowAllMethods);
    }

    public function testConvertsWildcardOriginPatterns(): void
    {
        $service = new Cors(['allowedOrigins' => ['*.mydomain.com']]);

        $patterns = $this->getOptionsFromContext()->allowedOriginsPatterns;
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
        $corsOptions = $this->getOptionsFromContext();

        $this->assertEquals($options['allowed_origins'], $corsOptions->allowedOrigins);
        $this->assertEquals($options['allowed_origins_patterns'], $corsOptions->allowedOriginsPatterns);
        $this->assertEquals(['x-custom'], $corsOptions->allowedHeaders); // lowercased
        $this->assertEquals($options['allowed_methods'], $corsOptions->allowedMethods);
        $this->assertEquals($options['exposed_headers'], $corsOptions->exposedHeaders);
        $this->assertEquals($options['max_age'], $corsOptions->maxAge);
        $this->assertEquals($options['supports_credentials'], $corsOptions->supportsCredentials);
    }

    public function testOptionsAreIsolatedBetweenCoroutines(): void
    {
        $service = new Cors(['allowedOrigins' => ['main.com']]);

        $this->assertEquals(['main.com'], $this->getOptionsFromContext()->allowedOrigins);

        // Simulate another coroutine with different options
        \Hyperf\Coroutine\Coroutine::create(function () use ($service) {
            // In a new coroutine, options should be empty (defaults)
            $this->assertEquals([], $this->getOptionsFromContext()->allowedOrigins);

            // Set different options in this coroutine
            $service->setOptions(['allowedOrigins' => ['other.com']]);
            $this->assertEquals(['other.com'], $this->getOptionsFromContext()->allowedOrigins);
        });

        // Back in original coroutine, options should be unchanged
        $this->assertEquals(['main.com'], $this->getOptionsFromContext()->allowedOrigins);
    }

    public function testLowercasesAllowedHeaders(): void
    {
        $service = new Cors(['allowedHeaders' => ['X-Custom-Header', 'CONTENT-TYPE', 'Accept']]);

        $this->assertEquals(
            ['x-custom-header', 'content-type', 'accept'],
            $this->getOptionsFromContext()->allowedHeaders
        );
    }

    public function testUppercasesAllowedMethods(): void
    {
        $service = new Cors(['allowedMethods' => ['get', 'Post', 'DELETE']]);

        $this->assertEquals(
            ['GET', 'POST', 'DELETE'],
            $this->getOptionsFromContext()->allowedMethods
        );
    }

    public function testPartialOptionsUpdatePreservesExisting(): void
    {
        $service = new Cors([
            'allowedOrigins' => ['example.com'],
            'allowedMethods' => ['GET', 'POST'],
            'maxAge' => 600,
        ]);

        // Update only maxAge
        $service->setOptions(['maxAge' => 3600]);

        $options = $this->getOptionsFromContext();
        $this->assertEquals(['example.com'], $options->allowedOrigins);
        $this->assertEquals(['GET', 'POST'], $options->allowedMethods);
        $this->assertEquals(3600, $options->maxAge);
    }

    public function testIsOriginAllowedWithExactMatch(): void
    {
        $service = new Cors(['allowedOrigins' => ['https://example.com', 'https://other.com']]);

        $request = $this->createMockRequest('https://example.com');
        $this->assertTrue($service->isOriginAllowed($request));

        $request = $this->createMockRequest('https://other.com');
        $this->assertTrue($service->isOriginAllowed($request));

        $request = $this->createMockRequest('https://notallowed.com');
        $this->assertFalse($service->isOriginAllowed($request));
    }

    public function testIsOriginAllowedWithPatternMatch(): void
    {
        $service = new Cors(['allowedOrigins' => ['https://*.example.com']]);

        $request = $this->createMockRequest('https://sub.example.com');
        $this->assertTrue($service->isOriginAllowed($request));

        $request = $this->createMockRequest('https://deep.sub.example.com');
        $this->assertTrue($service->isOriginAllowed($request));

        $request = $this->createMockRequest('https://example.com');
        $this->assertFalse($service->isOriginAllowed($request));

        $request = $this->createMockRequest('https://other.com');
        $this->assertFalse($service->isOriginAllowed($request));
    }

    public function testIsOriginAllowedWithWildcard(): void
    {
        $service = new Cors(['allowedOrigins' => ['*']]);

        $request = $this->createMockRequest('https://anything.com');
        $this->assertTrue($service->isOriginAllowed($request));
    }

    public function testVaryHeaderAddsNewHeader(): void
    {
        $service = new Cors();
        $response = new \Hyperf\HttpMessage\Server\Response();

        $response = $service->varyHeader($response, 'Origin');

        $this->assertEquals('Origin', $response->getHeaderLine('Vary'));
    }

    public function testVaryHeaderAppendsToExisting(): void
    {
        $service = new Cors();
        $response = new \Hyperf\HttpMessage\Server\Response();
        $response = $response->withHeader('Vary', 'Accept-Encoding');

        $response = $service->varyHeader($response, 'Origin');

        $this->assertEquals('Accept-Encoding, Origin', $response->getHeaderLine('Vary'));
    }

    public function testVaryHeaderDoesNotDuplicate(): void
    {
        $service = new Cors();
        $response = new \Hyperf\HttpMessage\Server\Response();
        $response = $response->withHeader('Vary', 'Origin');

        $response = $service->varyHeader($response, 'Origin');

        $this->assertEquals('Origin', $response->getHeaderLine('Vary'));
    }

    /**
     * Get CORS options from Context.
     */
    private function getOptionsFromContext(): CorsOptions
    {
        return Context::get(self::CORS_CONTEXT_KEY) ?? new CorsOptions();
    }

    /**
     * Create a mock request with the given Origin header.
     */
    private function createMockRequest(string $origin): \Hypervel\Http\Contracts\RequestContract
    {
        $request = $this->createMock(\Hypervel\Http\Contracts\RequestContract::class);
        $request->expects($this->any())
            ->method('header')
            ->willReturnCallback(function ($name) use ($origin) {
                return $name === 'Origin' ? $origin : null;
            });
        $request->expects($this->any())
            ->method('hasHeader')
            ->willReturnCallback(function ($name) use ($origin) {
                return $name === 'Origin' && $origin !== '';
            });

        return $request;
    }
}
