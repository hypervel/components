<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class PoolDefinitionTest extends TestCase
{
    public function testDefinitionRetainsItsImmutableValues(): void
    {
        $options = PoolOptions::fromArray(['max_objects' => 5]);
        $definition = new PoolDefinition('manager:auto:s3:hash', 's3', 'auto:hash', $options);

        $this->assertSame('manager:auto:s3:hash', $definition->identity);
        $this->assertSame('s3', $definition->resourceType);
        $this->assertSame('auto:hash', $definition->fingerprint);
        $this->assertSame($options, $definition->options);
    }

    #[DataProvider('invalidStrings')]
    public function testDefinitionStringsMustBeNonEmpty(
        string $identity,
        string $resourceType,
        string $fingerprint,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new PoolDefinition($identity, $resourceType, $fingerprint, PoolOptions::fromArray([]));
    }

    public static function invalidStrings(): array
    {
        return [
            ['', 's3', 'auto:hash', 'The pool identity must be a non-empty string.'],
            ['   ', 's3', 'auto:hash', 'The pool identity must be a non-empty string.'],
            ['identity', '', 'auto:hash', 'The pool resource type must be a non-empty string.'],
            ['identity', "\t", 'auto:hash', 'The pool resource type must be a non-empty string.'],
            ['identity', 's3', '', 'The pool fingerprint must be a non-empty string.'],
            ['identity', 's3', "\n", 'The pool fingerprint must be a non-empty string.'],
        ];
    }
}
