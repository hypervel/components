<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Http\RequestQueryStringPartialsResolverTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Lazy;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;
use stdClass;

class RequestQueryStringPartialsResolverTest extends TestCase
{
    /**
     * Get package providers for the query string partials test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testIncludeAppliesAllowedRootAndNestedLazyProperties(): void
    {
        $response = $this->makeData()->toResponse($this->request([
            'include' => 'secret,nested.secret',
        ]));

        $this->assertSame([
            'id' => 1,
            'display_name' => 'Taylor',
            'secret' => 'root-secret',
            'default_secret' => 'root-default-secret',
            'nested' => [
                'id' => 2,
                'display_name' => 'Abigail',
                'secret' => 'nested-secret',
                'default_secret' => 'nested-default-secret',
            ],
        ], $response->getData(true));
    }

    public function testOnlyAcceptsMappedOutputNamesAndNestedPaths(): void
    {
        $response = $this->makeData()->toResponse($this->request([
            'only' => 'display_name,nested.display_name',
        ]));

        $this->assertSame([
            'display_name' => 'Taylor',
            'nested' => ['display_name' => 'Abigail'],
        ], $response->getData(true));
    }

    public function testExcludeAcceptsMappedOutputNamesAndNestedPaths(): void
    {
        $response = $this->makeData()->toResponse($this->request([
            'exclude' => 'default_secret,nested.default_secret',
        ]));

        $this->assertSame([
            'id' => 1,
            'display_name' => 'Taylor',
            'nested' => [
                'id' => 2,
                'display_name' => 'Abigail',
            ],
        ], $response->getData(true));
    }

    public function testExceptAcceptsMappedOutputNamesAndNestedPaths(): void
    {
        $response = $this->makeData()->toResponse($this->request([
            'except' => 'display_name,nested.display_name',
        ]));

        $this->assertSame([
            'id' => 1,
            'default_secret' => 'root-default-secret',
            'nested' => [
                'id' => 2,
                'default_secret' => 'nested-default-secret',
            ],
        ], $response->getData(true));
    }

    public function testDisallowedAndMalformedPathsAreIgnored(): void
    {
        $response = $this->makeData()->toResponse($this->request([
            'only' => ['secret', 'unknown', 123],
            'include' => new stdClass,
        ]));

        $this->assertSame([
            'id' => 1,
            'display_name' => 'Taylor',
            'default_secret' => 'root-default-secret',
            'nested' => [
                'id' => 2,
                'display_name' => 'Abigail',
                'default_secret' => 'nested-default-secret',
            ],
        ], $response->getData(true));
    }

    public function testInvalidNestedChildFallsBackToTheAllowedParent(): void
    {
        $response = $this->makeData()->toResponse($this->request([
            'only' => 'nested.unknown',
        ]));

        $this->assertSame([
            'nested' => [
                'id' => 2,
                'display_name' => 'Abigail',
                'default_secret' => 'nested-default-secret',
            ],
        ], $response->getData(true));
    }

    public function testNullAllowlistPermitsWildcardSelection(): void
    {
        $data = new UnrestrictedRequestPartialData(
            1,
            Lazy::create(static fn (): string => 'secret'),
        );

        $this->assertSame([
            'id' => 1,
            'secret' => 'secret',
        ], $data->toResponse($this->request(['include' => '*']))->getData(true));
    }

    /**
     * Create the nested data graph used by resolver tests.
     */
    private function makeData(): RequestPartialData
    {
        return new RequestPartialData(
            1,
            'Taylor',
            Lazy::create(static fn (): string => 'root-secret'),
            Lazy::create(static fn (): string => 'root-default-secret')->defaultIncluded(),
            new RequestPartialNestedData(
                2,
                'Abigail',
                Lazy::create(static fn (): string => 'nested-secret'),
                Lazy::create(static fn (): string => 'nested-default-secret')->defaultIncluded(),
            ),
        );
    }

    /**
     * Create a request with query parameters.
     */
    private function request(array $query): Request
    {
        return Request::create('/', 'GET', $query);
    }
}

class RequestPartialData extends Data
{
    public function __construct(
        public int $id,
        #[MapOutputName('display_name')]
        public string $name,
        public Lazy|string $secret,
        #[MapOutputName('default_secret')]
        public Lazy|string $defaultSecret,
        public RequestPartialNestedData $nested,
    ) {
    }

    /**
     * Get the request properties that may be included.
     */
    public static function allowedRequestIncludes(): ?array
    {
        return ['secret', 'nested'];
    }

    /**
     * Get the request properties that may be excluded.
     */
    public static function allowedRequestExcludes(): ?array
    {
        return ['defaultSecret', 'nested'];
    }

    /**
     * Get the request properties allowed by an only selection.
     */
    public static function allowedRequestOnly(): ?array
    {
        return ['id', 'name', 'nested'];
    }

    /**
     * Get the request properties allowed by an except selection.
     */
    public static function allowedRequestExcept(): ?array
    {
        return ['name', 'nested'];
    }
}

class RequestPartialNestedData extends Data
{
    public function __construct(
        public int $id,
        #[MapOutputName('display_name')]
        public string $name,
        public Lazy|string $secret,
        #[MapOutputName('default_secret')]
        public Lazy|string $defaultSecret,
    ) {
    }

    /**
     * Get the request properties that may be included.
     */
    public static function allowedRequestIncludes(): ?array
    {
        return ['secret'];
    }

    /**
     * Get the request properties that may be excluded.
     */
    public static function allowedRequestExcludes(): ?array
    {
        return ['defaultSecret'];
    }

    /**
     * Get the request properties allowed by an only selection.
     */
    public static function allowedRequestOnly(): ?array
    {
        return ['name'];
    }

    /**
     * Get the request properties allowed by an except selection.
     */
    public static function allowedRequestExcept(): ?array
    {
        return ['name'];
    }
}

class UnrestrictedRequestPartialData extends Data
{
    public function __construct(
        public int $id,
        public Lazy|string $secret,
    ) {
    }

    /**
     * Get the request properties that may be included.
     */
    public static function allowedRequestIncludes(): ?array
    {
        return null;
    }
}
