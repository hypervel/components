<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\Data\RecordedResponse;
use Hypervel\Saloon\Exceptions\FixtureException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Repositories\Body\JsonBodyRepository;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\DTORequest;
use PHPUnit\Framework\Attributes\DataProvider;

class FixtureDataTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Saloon::fixturePath(dirname(__DIR__) . '/Fixtures/Saloon');
    }

    public function testYouCanCreateAFixtureDataObjectFromAFileString(): void
    {
        $data = [
            'statusCode' => 200,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'data' => [
                'name' => 'Sam',
            ],
        ];

        $fixtureData = RecordedResponse::fromFile(json_encode($data));

        $this->assertSame($data['statusCode'], $fixtureData->statusCode);
        $this->assertSame($data['headers'], $fixtureData->headers);
        $this->assertSame($data['data'], $fixtureData->data);
    }

    public function testYouCanCreateAMockResponseFromFixtureData(): void
    {
        $data = [
            'statusCode' => 200,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'data' => [
                'name' => 'Sam',
            ],
        ];

        $fixtureData = RecordedResponse::fromFile(json_encode($data));
        $mockResponse = $fixtureData->toMockResponse();

        $this->assertEquals(new MockResponse($data['data'], $data['statusCode'], $data['headers']), $mockResponse);
    }

    /**
     * @param array<string, mixed> $data
     * @param null|array<string, mixed> $expected
     */
    #[DataProvider('fixtureFiles')]
    public function testYouCanJsonSerializeTheFixtureDataOrConvertItIntoAFile(array $data, ?array $expected = null): void
    {
        $expected ??= $data;

        $fixtureData = RecordedResponse::fromFile(json_encode($data, JSON_PRETTY_PRINT));

        $serialized = json_encode($fixtureData, JSON_PRETTY_PRINT);

        $this->assertSame(json_encode($expected, JSON_PRETTY_PRINT), $serialized);
        $this->assertSame($serialized, $fixtureData->toFile());
    }

    /**
     * Get fixture files with their expected serialization.
     *
     * @return array<string, array{0: array<string, mixed>, 1?: array<string, mixed>}>
     */
    public static function fixtureFiles(): array
    {
        return [
            'without context key' => [
                [
                    'statusCode' => 200,
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'data' => [
                        'name' => 'Sam',
                    ],
                ],
                [
                    'statusCode' => 200,
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'data' => [
                        'name' => 'Sam',
                    ],
                    'context' => [],
                ],
            ],
            'with context key' => [
                [
                    'statusCode' => 200,
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'data' => [
                        'name' => 'Sam',
                    ],
                    'context' => [],
                ],
            ],
            'with context data' => [
                [
                    'statusCode' => 200,
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'data' => [
                        'name' => 'Sam',
                    ],
                    'context' => [
                        'test' => 'you can json serialize the fixture data or convert it into a file',
                    ],
                ],
            ],
        ];
    }

    public function testItRoundTripsTextAndContext(): void
    {
        $recorded = new RecordedResponse(
            201,
            ['X-Trace' => ['abc']],
            '{"name":"Taylor"}',
            ['provider' => 'example'],
        );

        $restored = RecordedResponse::fromFile($recorded->toFile());

        $this->assertSame(201, $restored->statusCode);
        $this->assertSame(['X-Trace' => ['abc']], $restored->headers);
        $this->assertSame('{"name":"Taylor"}', $restored->data);
        $this->assertSame(['provider' => 'example'], $restored->context);
        $this->assertSame('{"name":"Taylor"}', (string) $restored->toMockResponse()->createPsrResponse()->getBody());
    }

    public function testItRoundTripsBinaryResponseData(): void
    {
        $recorded = new RecordedResponse(200, data: "\xB1\x31");

        $contents = $recorded->toFile();
        $restored = RecordedResponse::fromFile($contents);

        $this->assertStringContainsString('"encoding": "base64"', $contents);
        $this->assertSame("\xB1\x31", $restored->data);
    }

    public function testItRejectsInvalidBase64Data(): void
    {
        $this->expectException(FixtureException::class);

        RecordedResponse::fromFile('{"statusCode":200,"headers":[],"data":"%","encoding":"base64"}');
    }

    public function testArbitraryDataCanBeMergedInTheFixture(): void
    {
        $response = (new TestConnector)->send(new DTORequest, new MockClient([
            MockResponse::fixture('user')->merge([
                'name' => 'Sam Carré',
            ]),
        ]));

        $user = $response->dto();

        $this->assertSame('Sam Carré', $user->name);
        $this->assertSame('Sam', $user->actualName);
        $this->assertSame('@carre_sam', $user->twitter);
    }

    public function testArbitraryDataUsingDotNotationCanBeMergedInTheFixture(): void
    {
        $response = (new TestConnector)->send(new DTORequest, new MockClient([
            MockResponse::fixture('users')->merge([
                'data.0.twitter' => '@jon_doe',
            ]),
        ]));

        $users = $response->json('data');

        $this->assertCount(2, $users);
        $this->assertSame('@jon_doe', $users[0]['twitter']);
        $this->assertSame('@janedoe', $users[1]['twitter']);
    }

    public function testAClosureCanBeUsedToModifyTheMockResponseData(): void
    {
        $response = (new TestConnector)->send(new DTORequest, new MockClient([
            MockResponse::fixture('users')->through(fn (array $data): array => array_merge_recursive($data, [
                'data' => [
                    [
                        'name' => 'Sam',
                        'actual_name' => 'Carré',
                        'twitter' => '@carre_sam',
                    ],
                ],
            ])),
        ]));

        $users = $response->json('data');

        $this->assertCount(3, $users);
        $this->assertSame('@jondoe', $users[0]['twitter']);
        $this->assertSame('@janedoe', $users[1]['twitter']);
        $this->assertSame('@carre_sam', $users[2]['twitter']);
    }

    public function testMergingKeepsAnArrayFixtureBodyAsJson(): void
    {
        $files = new Filesystem;
        $fixturePath = ParallelTesting::tempDir('SaloonFixtureDataTest');
        $files->deleteDirectory($fixturePath);
        $files->ensureDirectoryExists($fixturePath);
        Saloon::fixturePath($fixturePath);

        try {
            $files->put($fixturePath . '/array.json', json_encode([
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'data' => ['name' => 'Sam'],
            ], JSON_THROW_ON_ERROR));

            $response = MockResponse::fixture('array')
                ->merge(['twitter' => '@carre_sam'])
                ->through(fn (array $data): array => [...$data, 'actual_name' => 'Sam'])
                ->getMockResponse();

            $this->assertInstanceOf(JsonBodyRepository::class, $response->body());
            $this->assertSame(['name' => 'Sam', 'twitter' => '@carre_sam', 'actual_name' => 'Sam'], $response->body()->all());
        } finally {
            $files->deleteDirectory($fixturePath);
        }
    }
}
