<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Exception;
use finfo;
use Hypervel\Context\CoroutineContext;
use Hypervel\Support\FileinfoMimeTypeGuesser;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

#[RequiresPhpExtension('fileinfo')]
class FileinfoMimeTypeGuesserTest extends TestCase
{
    public function testGuessMimeTypeWithInvalidFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FileinfoMimeTypeGuesser)
            ->guessMimeType(__DIR__ . '/unknown');
    }

    public function testGuessMimeType(): void
    {
        $mimeType = (new FileinfoMimeTypeGuesser)
            ->guessMimeType(__DIR__ . '/Fixtures/test.gif');

        $this->assertEquals('image/gif', $mimeType);
    }

    public function testFinfoConstructionFailureRetainsItsCause(): void
    {
        try {
            (new FileinfoMimeTypeGuesser(__DIR__ . '/Fixtures/missing.magic'))
                ->guessMimeType(__DIR__ . '/Fixtures/test.gif');

            $this->fail('The invalid magic database did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf(Exception::class, $exception->getPrevious());
            $this->assertSame($exception->getMessage(), $exception->getPrevious()->getMessage());
        }
    }

    public function testGuessMimeTypeIsCoroutineScoped(): void
    {
        $guesser = new FileinfoMimeTypeGuesser;
        $key = FileinfoMimeTypeGuesser::FINFO_CONTEXT_KEY_PREFIX;

        $results = parallel(array_fill(0, 5, function () use ($guesser, $key) {
            return [
                'mimeType' => $guesser->guessMimeType(__DIR__ . '/Fixtures/test.gif'),
                'finfo' => CoroutineContext::get($key),
            ];
        }));

        $this->assertCount(5, $results);
        foreach ($results as $result) {
            $this->assertSame('image/gif', $result['mimeType']);
        }

        $finfoInstances = array_column($results, 'finfo');

        $this->assertContainsOnlyInstancesOf(finfo::class, $finfoInstances);
        $this->assertCount(5, array_unique(array_map(spl_object_id(...), $finfoInstances)));
    }
}
