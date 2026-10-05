<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use GuzzleHttp\Psr7\Utils;
use Hypervel\Saloon\Data\MultipartValue;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

class MultipartValueTest extends TestCase
{
    #[DataProvider('validValues')]
    public function testItCanAcceptDifferentValues(mixed $value): void
    {
        try {
            $multipartValue = new MultipartValue('test', $value);

            $this->assertSame($value, $multipartValue->value);
        } finally {
            if (is_resource($value)) {
                fclose($value);
            }
        }
    }

    /**
     * Get the values a multipart value accepts.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function validValues(): iterable
    {
        yield 'stream' => [Utils::streamFor('hello')];
        yield 'resource' => [fopen(sprintf('data://text/plain,%s', 'hello'), 'rb')];
        yield 'string' => ['hello'];
        yield 'integer' => [123];
        yield 'float' => [123.50];
        // Upstream rejects these. Hypervel accepts Guzzle's field values so withData() can add fields to a multipart body.
        yield 'boolean' => [false];
        yield 'null' => [null];
        yield 'empty array' => [[]];
        yield 'array' => [['admin', 'profile' => ['city' => 'London']]];
    }

    #[DataProvider('invalidValues')]
    public function testItWillThrowAnExceptionOnInvalidValues(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The value property must be either a Psr\Http\Message\StreamInterface, resource, string, finite number, boolean, null, or array.');

        new MultipartValue('test', $value);
    }

    /**
     * Get the values a multipart value rejects.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'object' => [new UserRequest];
        yield 'infinite float' => [INF];
    }

    #[TestWith(['roles.txt', []])]
    #[TestWith([null, ['X-Role' => 'admin']])]
    public function testAnArrayValueCannotHaveAFilenameOrHeaders(?string $filename, array $headers): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An array value cannot have a filename or headers.');

        new MultipartValue('roles', ['admin'], $filename, $headers);
    }
}
