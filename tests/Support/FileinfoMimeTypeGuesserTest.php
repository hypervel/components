<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use finfo;
use Hypervel\Context\CoroutineContext;
use Hypervel\Support\FileinfoMimeTypeGuesser;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

use function Hypervel\Coroutine\parallel;

#[RequiresPhpExtension('fileinfo')]
class FileinfoMimeTypeGuesserTest extends TestCase
{
    public function testGuessMimeTypeWithInvalidFile()
    {
        $this->expectException(InvalidArgumentException::class);

        (new FileinfoMimeTypeGuesser)
            ->guessMimeType(__DIR__ . '/unknown');
    }

    public function testGuessMimeType()
    {
        $mimeType = (new FileinfoMimeTypeGuesser)
            ->guessMimeType(__DIR__ . '/Fixtures/test.gif');

        $this->assertEquals('image/gif', $mimeType);
    }

    public function testGuessMimeTypeIsCoroutineScoped()
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
