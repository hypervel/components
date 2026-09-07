<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\Data\RecordedResponse;
use Hypervel\Saloon\Exceptions\FixtureException;
use Hypervel\Saloon\Exceptions\FixtureMissingException;
use Hypervel\Saloon\Http\Faking\Fixture;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class FixtureTest extends TestCase
{
    protected Filesystem $files;

    protected string $fixturePath;

    public function testItStoresLoadsRedactsAndTransformsFixtures(): void
    {
        $fixture = (new RedactingFixtureStub('pagination/per-page-limit=5&page=4', $this->files))
            ->setContext('provider', 'example');

        $fixture->store(new RecordedResponse(
            201,
            ['Authorization' => ['Bearer secret'], 'X-Trace' => ['abc']],
            '{"token":"secret","nested":{"token":"secret"},"message":"key=secret"}',
            ['existing' => true],
        ));

        $this->assertTrue($this->files->exists($fixture->getFixturePath()));
        $this->assertStringStartsWith($this->fixturePath . DIRECTORY_SEPARATOR, $fixture->getFixturePath());
        $contents = $this->files->get($fixture->getFixturePath());
        $this->assertStringNotContainsString('secret', $contents);

        $loaded = (new RedactingFixtureStub('pagination/per-page-limit=5&page=4', $this->files))
            ->merge(['nested.name' => 'Taylor'])
            ->through(function (array $body): array {
                $body['complete'] = true;

                return $body;
            });
        $response = $loaded->getMockResponse();

        $this->assertNotNull($response);
        $this->assertSame(201, $response->status());
        $this->assertSame(
            [
                'token' => '[redacted]',
                'nested' => ['token' => '[redacted]', 'name' => 'Taylor'],
                'message' => '[redacted]',
                'complete' => true,
            ],
            json_decode((string) $response->body()->all(), true, 512, JSON_THROW_ON_ERROR),
        );
        $restored = RecordedResponse::fromFile($contents);
        $this->assertSame(['existing' => true, 'provider' => 'example'], $restored->context);
    }

    #[DataProvider('invalidFixtureNames')]
    public function testItRejectsUnsafeOrNonPortableFixtureNames(string $name): void
    {
        $this->expectException(FixtureException::class);

        (new Fixture($name, $this->files))->getFixturePath();
    }

    public static function invalidFixtureNames(): array
    {
        return [
            'empty segment' => ['users//first'],
            'current segment' => ['users/./first'],
            'parent segment' => ['users/../first'],
            'absolute path' => ['/users'],
            'backslash' => ['users\first'],
            'null byte' => ["users\0first"],
            'colon' => ['users:first'],
        ];
    }

    public function testMissingFixturesCanReturnNullOrThrow(): void
    {
        $this->bindManager(false);
        $this->assertNull((new Fixture('missing', $this->files))->getMockResponse());

        $this->bindManager(true);
        $this->expectException(FixtureMissingException::class);

        (new Fixture('missing', $this->files))->getMockResponse();
    }

    public function testFixtureContextCanBeMergedAndRead(): void
    {
        $fixture = (new Fixture('context', $this->files))->withContext([
            'tenant' => 'acme',
            'page' => 2,
        ]);

        $this->assertSame('acme', $fixture->getContext('tenant'));
        $this->assertSame(['tenant' => 'acme', 'page' => 2], $fixture->getContext()->all());
    }

    public function testFixtureTransformsRejectScalarJsonBodies(): void
    {
        (new Fixture('scalar', $this->files))->store(new RecordedResponse(200, [], '0'));

        $this->expectException(FixtureException::class);
        $this->expectExceptionMessage('Fixture merge and through transforms require a JSON array or object body.');

        (new Fixture('scalar', $this->files))->merge(['name' => 'Taylor'])->getMockResponse();
    }

    public function testCallableNamedStringsRemainLiteralRedactionValues(): void
    {
        $fixture = new CallableNamedReplacementFixtureStub('literal-replacements', $this->files);
        $fixture->store(new RecordedResponse(
            200,
            ['Authorization' => ['Bearer secret']],
            '{"token":"secret","message":"key=secret"}',
        ));

        $recorded = RecordedResponse::fromFile($this->files->get($fixture->getFixturePath()));

        $this->assertSame(['trim'], $recorded->headers['Authorization']);
        $this->assertSame(
            ['token' => 'trim', 'message' => 'trim'],
            json_decode($recorded->data, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testMalformedRedactionPatternPreventsFixturePublication(): void
    {
        $fixture = new RegexFixtureStub('malformed-pattern', $this->files, '/[bad/');
        $fixturePath = $fixture->getFixturePath();
        set_error_handler(function (int $severity, string $message): bool {
            $this->assertSame(E_WARNING, $severity);
            $this->assertStringContainsString('preg_match_all()', $message);

            return true;
        });

        try {
            $fixture->store(new RecordedResponse(200, [], '{"token":"secret"}'));
            $this->fail('A malformed redaction pattern was accepted.');
        } catch (FixtureException $exception) {
            $this->assertStringContainsString('/[bad/', $exception->getMessage());
            $this->assertFalse($this->files->exists($fixturePath));
        } finally {
            restore_error_handler();
        }
    }

    public function testRedactionExecutionFailurePreventsFixturePublication(): void
    {
        $fixture = new RegexFixtureStub(
            'execution-failure',
            $this->files,
            '/(?:\D+|<\d+>)*[!?]/',
        );
        $fixturePath = $fixture->getFixturePath();
        $previousBacktrackLimit = ini_set('pcre.backtrack_limit', '10');

        try {
            $fixture->store(new RecordedResponse(200, [], 'foobar foobar foobar'));
            $this->fail('A failed redaction pattern was accepted.');
        } catch (FixtureException $exception) {
            $this->assertStringContainsString('Backtrack limit exhausted', $exception->getMessage());
            $this->assertFalse($this->files->exists($fixturePath));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousBacktrackLimit);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->fixturePath = ParallelTesting::tempDir('SaloonFixtureTest');
        $this->files->deleteDirectory($this->fixturePath);
        $this->files->ensureDirectoryExists($this->fixturePath);
        $this->bindManager(false);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    /**
     * Bind the fixture-facing manager behavior.
     */
    protected function bindManager(bool $throwOnMissing): void
    {
        $manager = m::mock(SaloonManager::class);
        $manager->shouldReceive('getFixturePath')->andReturn($this->fixturePath);
        $manager->shouldReceive('throwsOnMissingFixtures')->andReturn($throwOnMissing);

        $container = new Container;
        $container->instance('saloon', $manager);
        Container::setInstance($container);
    }
}

class RedactingFixtureStub extends Fixture
{
    protected function defineSensitiveHeaders(): array
    {
        return ['authorization' => '[redacted]'];
    }

    protected function defineSensitiveJsonParameters(): array
    {
        return ['token' => '[redacted]'];
    }

    protected function defineSensitiveRegexPatterns(): array
    {
        return ['/key=[^"}]*/' => '[redacted]'];
    }
}

class RegexFixtureStub extends Fixture
{
    public function __construct(
        string $name,
        Filesystem $files,
        protected string $pattern,
    ) {
        parent::__construct($name, $files);
    }

    protected function defineSensitiveRegexPatterns(): array
    {
        return [$this->pattern => '[redacted]'];
    }
}

class CallableNamedReplacementFixtureStub extends Fixture
{
    protected function defineSensitiveHeaders(): array
    {
        return ['authorization' => 'trim'];
    }

    protected function defineSensitiveJsonParameters(): array
    {
        return ['token' => 'trim'];
    }

    protected function defineSensitiveRegexPatterns(): array
    {
        return ['/key=[^"}]*/' => 'trim'];
    }
}
