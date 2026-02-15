<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

interface ServerRequestPlusInterface extends RequestPlusInterface, ServerRequestInterface
{
    /** @return array<string, mixed> */
    public function getServerParams(): array;

    /** @param array<string, mixed> $serverParams */
    public function setServerParams(array $serverParams): static;

    /** @param array<string, mixed> $serverParams */
    public function withServerParams(array $serverParams): static;

    /** @return array<string, string> */
    public function getQueryParams(): array;

    /** @param array<string, string> $query */
    public function setQueryParams(array $query): static;

    /** @param array<string, string> $query */
    public function withQueryParams(array $query): static;

    /** @return array<string, string> */
    public function getCookieParams(): array;

    /** @param array<string, string> $cookies */
    public function setCookieParams(array $cookies): static;

    /** @param array<string, string> $cookies */
    public function withCookieParams(array $cookies): static;

    /** @return array<mixed>|object|null */
    public function getParsedBody(): array|object|null;

    /** @param null|array<mixed>|object $data */
    public function setParsedBody(array|object|null $data): static;

    /** @param null|array<mixed>|object $data */
    public function withParsedBody($data): static;

    /** @return array<string, UploadedFileInterface> */
    public function getUploadedFiles(): array;

    /** @param array<string, UploadedFileInterface> $uploadedFiles */
    public function setUploadedFiles(array $uploadedFiles): static;

    /** @param array<UploadedFileInterface> $uploadedFiles */
    public function withUploadedFiles(array $uploadedFiles): static;

    /** @return array<mixed> */
    public function getAttributes(): array;

    public function getAttribute(string $name, mixed $default = null): mixed;

    public function setAttribute(string $name, mixed $value): static;

    public function unsetAttribute(string $name): static;

    public function withAttribute(string $name, mixed $value): static;

    public function withoutAttribute(string $name): static;
}
