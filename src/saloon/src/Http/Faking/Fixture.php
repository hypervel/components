<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Faking;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\Data\RecordedResponse;
use Hypervel\Saloon\Exceptions\FixtureException;
use Hypervel\Saloon\Exceptions\FixtureMissingException;
use Hypervel\Saloon\Repositories\ArrayRepository;
use Hypervel\Saloon\SaloonManager;
use JsonException;

class Fixture
{
    /**
     * The fixture extension.
     */
    protected const string FIXTURE_EXTENSION = 'json';

    /**
     * Data merged into the mocked response body.
     *
     * @var null|array<string, mixed>
     */
    protected ?array $merge = null;

    /**
     * The response body transformer.
     *
     * @var null|Closure(array<string, mixed>): array<string, mixed>
     */
    protected ?Closure $through = null;

    /**
     * The fixture context.
     */
    protected ArrayRepository $context;

    /**
     * The filesystem used by the fixture.
     */
    protected Filesystem $files;

    /**
     * Create a fixture.
     */
    public function __construct(
        protected string $name = '',
        ?Filesystem $files = null,
        ?ArrayRepository $context = null,
    ) {
        $this->files = $files ?? Container::getInstance()->make(Filesystem::class);
        $this->context = $context ?? new ArrayRepository;
    }

    /**
     * Merge data into the mocked response body.
     *
     * @param array<string, mixed> $merge
     * @return $this
     */
    public function merge(array $merge = []): static
    {
        $this->merge = $merge;

        return $this;
    }

    /**
     * Transform the mocked response body.
     *
     * @param Closure(array<string, mixed>): array<string, mixed> $through
     * @return $this
     */
    public function through(Closure $through): static
    {
        $this->through = $through;

        return $this;
    }

    /**
     * Load the mock response from the fixture.
     */
    public function getMockResponse(): ?MockResponse
    {
        $fixturePath = $this->getFixturePath();

        if (! $this->files->exists($fixturePath)) {
            if ($this->manager()->throwsOnMissingFixtures()) {
                throw new FixtureMissingException($fixturePath);
            }

            return null;
        }

        $response = RecordedResponse::fromFile($this->files->get($fixturePath))->toMockResponse();

        if ($this->merge === null && $this->through === null) {
            return $response;
        }

        /** @var null|string $body */
        $body = $response->body()->all();
        $contents = $body === null || $body === ''
            ? []
            : json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($contents)) {
            throw new FixtureException('Fixture merge and through transforms require a JSON array or object body.');
        }

        foreach ($this->merge ?? [] as $key => $value) {
            data_set($contents, $key, $value);
        }

        if ($this->through !== null) {
            $contents = ($this->through)($contents);
        }

        $response->body()->set(json_encode($contents, JSON_THROW_ON_ERROR));

        return $response;
    }

    /**
     * Store a recorded response as the fixture.
     *
     * @return $this
     */
    public function store(RecordedResponse $recordedResponse): static
    {
        $recordedResponse = $this->swapSensitiveHeaders($recordedResponse);
        $recordedResponse = $this->swapSensitiveJson($recordedResponse);
        $recordedResponse = $this->swapSensitiveBodyWithRegex($recordedResponse);
        $recordedResponse = $this->beforeSave($recordedResponse);
        $recordedResponse->context = array_merge($recordedResponse->context, $this->context->all());

        $fixturePath = $this->getFixturePath();
        $this->files->ensureDirectoryExists(dirname($fixturePath));
        $this->files->replace($fixturePath, $recordedResponse->toFile());

        return $this;
    }

    /**
     * Get the absolute fixture path.
     */
    public function getFixturePath(): string
    {
        $name = $this->name !== '' ? $this->name : $this->defineName();

        if ($name === '') {
            throw new FixtureException('The fixture must have a name.');
        }

        $segments = explode('/', $name);

        foreach ($segments as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/^[A-Za-z0-9._=&-]+$/D', $segment) !== 1) {
                throw new FixtureException('Fixture names must contain only portable path segments separated by forward slashes.');
            }
        }

        return rtrim($this->manager()->getFixturePath(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . implode(DIRECTORY_SEPARATOR, $segments)
            . '.' . self::FIXTURE_EXTENSION;
    }

    /**
     * Define the fixture name.
     */
    protected function defineName(): string
    {
        return '';
    }

    /**
     * Redact sensitive headers.
     */
    protected function swapSensitiveHeaders(RecordedResponse $recordedResponse): RecordedResponse
    {
        $rules = array_change_key_case($this->defineSensitiveHeaders(), CASE_LOWER);

        foreach ($recordedResponse->headers as $name => $values) {
            $replacement = $rules[strtolower($name)] ?? null;

            if ($replacement === null) {
                continue;
            }

            $replacement = $replacement instanceof Closure ? $replacement($values) : $replacement;
            $recordedResponse->headers[$name] = is_array($replacement) ? $replacement : [$replacement];
        }

        return $recordedResponse;
    }

    /**
     * Redact sensitive JSON values.
     */
    protected function swapSensitiveJson(RecordedResponse $recordedResponse): RecordedResponse
    {
        $rules = $this->defineSensitiveJsonParameters();

        if ($rules === []) {
            return $recordedResponse;
        }

        try {
            $body = json_decode($recordedResponse->data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $recordedResponse;
        }

        if (! is_array($body)) {
            return $recordedResponse;
        }

        $recordedResponse->data = json_encode(
            FixtureRedactor::recursivelyReplaceAttributes($body, $rules),
            JSON_THROW_ON_ERROR,
        );

        return $recordedResponse;
    }

    /**
     * Redact sensitive response body patterns.
     */
    protected function swapSensitiveBodyWithRegex(RecordedResponse $recordedResponse): RecordedResponse
    {
        $patterns = $this->defineSensitiveRegexPatterns();

        if ($patterns !== []) {
            $recordedResponse->data = FixtureRedactor::replaceSensitiveRegexPatterns(
                $recordedResponse->data,
                $patterns,
            );
        }

        return $recordedResponse;
    }

    /**
     * Define sensitive response headers.
     *
     * @return array<string, Closure(list<string>): (list<string>|string)|string>
     */
    protected function defineSensitiveHeaders(): array
    {
        return [];
    }

    /**
     * Define sensitive JSON attributes.
     *
     * @return array<string, Closure(mixed): mixed|string>
     */
    protected function defineSensitiveJsonParameters(): array
    {
        return [];
    }

    /**
     * Define sensitive response body patterns.
     *
     * @return array<string, Closure(string): string|string>
     */
    protected function defineSensitiveRegexPatterns(): array
    {
        return [];
    }

    /**
     * Prepare a recorded response before storage.
     */
    protected function beforeSave(RecordedResponse $recordedResponse): RecordedResponse
    {
        return $recordedResponse;
    }

    /**
     * Get fixture context or a single context value.
     *
     * @return ($key is null ? ArrayRepository : mixed)
     */
    public function getContext(?string $key = null): mixed
    {
        return $key === null ? $this->context : $this->context->get($key);
    }

    /**
     * Set a fixture context value.
     *
     * @return $this
     */
    public function setContext(string $key, mixed $value): static
    {
        $this->context->add($key, $value);

        return $this;
    }

    /**
     * Merge fixture context values.
     *
     * @param array<string, mixed> $context
     * @return $this
     */
    public function withContext(array $context): static
    {
        $this->context->merge($context);

        return $this;
    }

    /**
     * Resolve the Saloon manager.
     */
    protected function manager(): SaloonManager
    {
        /** @var SaloonManager */
        return Container::getInstance()->make('saloon');
    }
}
