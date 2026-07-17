<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\EncodedHtmlString;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\parallel;

class SupportEncodedHtmlStringTest extends TestCase
{
    protected function tearDown(): void
    {
        EncodedHtmlString::flushState();

        parent::tearDown();
    }

    public function testScopedEncodingOverridesBootEncoderAndRestoresNestedState(): void
    {
        EncodedHtmlString::encodeUsing(fn ($value) => "boot:{$value}");

        $result = EncodedHtmlString::withEncoding(
            fn ($value) => "outer:{$value}",
            function () {
                $outerBefore = (new EncodedHtmlString('before'))->toHtml();

                $inner = EncodedHtmlString::withEncoding(
                    fn ($value) => "inner:{$value}",
                    fn () => (new EncodedHtmlString('during'))->toHtml()
                );

                $outerAfter = (new EncodedHtmlString('after'))->toHtml();

                return [$outerBefore, $inner, $outerAfter];
            }
        );

        $this->assertSame(['outer:before', 'inner:during', 'outer:after'], $result);
        $this->assertSame('boot:final', (new EncodedHtmlString('final'))->toHtml());
    }

    public function testScopedEncodingIsIsolatedBetweenCoroutines(): void
    {
        EncodedHtmlString::encodeUsing(fn ($value) => "boot:{$value}");

        $results = parallel([
            'a' => function () {
                return EncodedHtmlString::withEncoding(
                    fn ($value) => "a:{$value}",
                    function () {
                        $before = (new EncodedHtmlString('before'))->toHtml();
                        usleep(5000);

                        return [$before, (new EncodedHtmlString('after'))->toHtml()];
                    }
                );
            },
            'b' => function () {
                return EncodedHtmlString::withEncoding(
                    fn ($value) => "b:{$value}",
                    function () {
                        $before = (new EncodedHtmlString('before'))->toHtml();
                        usleep(5000);

                        return [$before, (new EncodedHtmlString('after'))->toHtml()];
                    }
                );
            },
        ]);

        $this->assertSame(['a:before', 'a:after'], $results['a']);
        $this->assertSame(['b:before', 'b:after'], $results['b']);
        $this->assertSame('boot:final', (new EncodedHtmlString('final'))->toHtml());
    }

    public function testBootEncoderAcceptsEveryCallableShapeAndCanBeReset(): void
    {
        EncodedHtmlString::encodeUsing(new class {
            public function __invoke(string $value): string
            {
                return "invokable:{$value}";
            }
        });

        $this->assertSame('invokable:value', (new EncodedHtmlString('value'))->toHtml());

        $encoder = new class {
            public function encode(string $value): string
            {
                return "array:{$value}";
            }
        };

        EncodedHtmlString::encodeUsing([$encoder, 'encode']);

        $this->assertSame('array:value', (new EncodedHtmlString('value'))->toHtml());

        EncodedHtmlString::encodeUsing();

        $this->assertSame('&lt;value&gt;', (new EncodedHtmlString('<value>'))->toHtml());
    }
}
