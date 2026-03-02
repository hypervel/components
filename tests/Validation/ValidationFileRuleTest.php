<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Contracts\Translation\Translator as TranslatorContract;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Arr;
use Hypervel\Testbench\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\File;
use Hypervel\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * @internal
 * @coversNothing
 */
class ValidationFileRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton('translator', function () {
            return new Translator(
                new ArrayLoader(),
                'en'
            );
        });
    }

    public function testBasic()
    {
        $this->fails(
            File::default(),
            'foo',
            ['validation.file'],
        );

        $this->passes(
            File::default(),
            UploadedFile::fake()->create('foo.bar'),
        );

        $this->passes(File::default(), null);
    }

    protected function fails($rule, $values, $messages)
    {
        $this->assertValidationRules($rule, $values, false, $messages);
    }

    protected function assertValidationRules($rule, $values, $result, $messages)
    {
        $values = Arr::wrap($values);

        foreach ($values as $value) {
            $v = new Validator(
                $this->app->make(TranslatorContract::class),
                ['my_file' => $value],
                ['my_file' => is_object($rule) ? clone $rule : $rule]
            );

            $this->assertSame($result, $v->passes());

            $this->assertSame(
                $result ? [] : ['my_file' => $messages],
                $v->messages()->toArray()
            );
        }
    }

    protected function passes($rule, $values)
    {
        $this->assertValidationRules($rule, $values, true, []);
    }

    public function testSingleMimetype()
    {
        $this->fails(
            File::types('text/plain'),
            UploadedFile::fake()->createWithContent('foo.png', file_get_contents(__DIR__ . '/fixtures/image.png')),
            ['validation.mimetypes']
        );

        $this->passes(
            File::types('image/png'),
            UploadedFile::fake()->createWithContent('foo.png', file_get_contents(__DIR__ . '/fixtures/image.png')),
        );
    }

    public function testMultipleMimeTypes()
    {
        $this->fails(
            File::types(['text/plain', 'image/jpeg']),
            UploadedFile::fake()->createWithContent('foo.png', file_get_contents(__DIR__ . '/fixtures/image.png')),
            ['validation.mimetypes']
        );

        $this->passes(
            File::types(['text/plain', 'image/png']),
            UploadedFile::fake()->createWithContent('foo.png', file_get_contents(__DIR__ . '/fixtures/image.png')),
        );
    }

    public function testSingleMime()
    {
        $this->fails(
            File::types('txt'),
            UploadedFile::fake()->createWithContent('foo.png', file_get_contents(__DIR__ . '/fixtures/image.png')),
            ['validation.mimes']
        );

        $this->passes(
            File::types('png'),
            UploadedFile::fake()->createWithContent('foo.png', file_get_contents(__DIR__ . '/fixtures/image.png')),
        );
    }

    public function testMultipleMimes()
    {
        $this->fails(
            File::types(['png', 'jpg', 'jpeg', 'svg']),
            UploadedFile::fake()->createWithContent('foo.txt', 'Hello World!'),
            ['validation.mimes']
        );

        $this->passes(
            File::types(['png', 'jpg', 'jpeg', 'svg']),
            [
                UploadedFile::fake()->createWithContent('foo.png', file_get_contents(__DIR__ . '/fixtures/image.png')),
                UploadedFile::fake()->createWithContent('foo.svg', file_get_contents(__DIR__ . '/fixtures/image.svg')),
            ]
        );
    }

    public function testMixOfMimetypesAndMimes()
    {
        $this->fails(
            File::types(['png', 'image/png']),
            UploadedFile::fake()->createWithContent('foo.txt', 'Hello World!'),
            ['validation.mimetypes', 'validation.mimes']
        );

        $this->passes(
            File::types(['png', 'image/png']),
            UploadedFile::fake()->createWithContent('foo.png', file_get_contents(__DIR__ . '/fixtures/image.png')),
        );
    }

    public function testSingleExtension()
    {
        $this->fails(
            File::default()->extensions('png'),
            UploadedFile::fake()->createWithContent('foo', file_get_contents(__DIR__ . '/fixtures/image.png')),
            ['validation.extensions']
        );

        $this->fails(
            File::default()->extensions('png'),
            UploadedFile::fake()->createWithContent('foo.jpg', file_get_contents(__DIR__ . '/fixtures/image.png')),
            ['validation.extensions']
        );

        $this->fails(
            File::default()->extensions('jpeg'),
            UploadedFile::fake()->createWithContent('foo.jpg', file_get_contents(__DIR__ . '/fixtures/image.png')),
            ['validation.extensions']
        );

        $this->passes(
            File::default()->extensions('png'),
            UploadedFile::fake()->createWithContent('foo.png', file_get_contents(__DIR__ . '/fixtures/image.png')),
        );
    }

    public function testMultipleExtensions()
    {
        $this->fails(
            File::default()->extensions(['png', 'jpeg', 'jpg']),
            UploadedFile::fake()->createWithContent('foo', file_get_contents(__DIR__ . '/fixtures/image.png')),
            ['validation.extensions']
        );

        $this->fails(
            File::default()->extensions(['png', 'jpeg']),
            UploadedFile::fake()->createWithContent('foo.jpg', file_get_contents(__DIR__ . '/fixtures/image.png')),
            ['validation.extensions']
        );

        $this->passes(
            File::default()->extensions(['png', 'jpeg', 'jpg']),
            UploadedFile::fake()->createWithContent('foo.png', file_get_contents(__DIR__ . '/fixtures/image.png')),
        );
    }

    #[RequiresPhpExtension('gd')]
    public function testImage()
    {
        $this->fails(
            File::image(),
            UploadedFile::fake()->createWithContent('foo.txt', 'Hello World!'),
            ['validation.image']
        );

        $this->passes(
            File::image(),
            UploadedFile::fake()->image('foo.png'),
        );
    }

    public function testImageFailsOnSvgByDefault()
    {
        $maliciousSvgFileWithXSS = UploadedFile::fake()->createWithContent(
            name: 'foo.svg',
            content: <<<'XML'
                    <svg xmlns="http://www.w3.org/2000/svg" width="383" height="97" viewBox="0 0 383 97">
                        <text x="10" y="50" font-size="30" fill="black">XSS Logo</text>
                        <script>alert('XSS');</script>
                    </svg>
                    XML
        );

        $this->fails(
            File::image(),
            $maliciousSvgFileWithXSS,
            ['validation.image']
        );
        $this->fails(
            Rule::imageFile(),
            $maliciousSvgFileWithXSS,
            ['validation.image']
        );

        $this->passes(
            File::image(allowSvg: true),
            $maliciousSvgFileWithXSS
        );
        $this->passes(
            Rule::imageFile(allowSvg: true),
            $maliciousSvgFileWithXSS
        );
    }

    public function testSize()
    {
        $this->fails(
            File::default()->size(1024),
            [
                UploadedFile::fake()->create('foo.txt', 1025),
                UploadedFile::fake()->create('foo.txt', 1023),
            ],
            ['validation.size.file']
        );

        $this->passes(
            File::default()->size(1024),
            UploadedFile::fake()->create('foo.txt', 1024),
        );
    }

    public function testBetween()
    {
        $this->fails(
            File::default()->between(1024, 2048),
            [
                UploadedFile::fake()->create('foo.txt', 1023),
                UploadedFile::fake()->create('foo.txt', 2049),
            ],
            ['validation.between.file']
        );

        $this->passes(
            File::default()->between(1024, 2048),
            [
                UploadedFile::fake()->create('foo.txt', 1024),
                UploadedFile::fake()->create('foo.txt', 2048),
                UploadedFile::fake()->create('foo.txt', 1025),
                UploadedFile::fake()->create('foo.txt', 2047),
            ]
        );
    }

    public function testMin()
    {
        $this->fails(
            File::default()->min(1024),
            UploadedFile::fake()->create('foo.txt', 1023),
            ['validation.min.file']
        );

        $this->passes(
            File::default()->min(1024),
            [
                UploadedFile::fake()->create('foo.txt', 1024),
                UploadedFile::fake()->create('foo.txt', 1025),
                UploadedFile::fake()->create('foo.txt', 2048),
            ]
        );
    }

    public function testMinWithHumanReadableSize()
    {
        $this->fails(
            File::default()->min('1024kb'),
            UploadedFile::fake()->create('foo.txt', 1023),
            ['validation.min.file']
        );

        $this->passes(
            File::default()->min('1024kb'),
            [
                UploadedFile::fake()->create('foo.txt', 1024),
                UploadedFile::fake()->create('foo.txt', 1025),
                UploadedFile::fake()->create('foo.txt', 2048),
            ]
        );
    }

    public function testMax()
    {
        $this->fails(
            File::default()->max(1024),
            UploadedFile::fake()->create('foo.txt', 1025),
            ['validation.max.file']
        );

        $this->passes(
            File::default()->max(1024),
            [
                UploadedFile::fake()->create('foo.txt', 1024),
                UploadedFile::fake()->create('foo.txt', 1023),
                UploadedFile::fake()->create('foo.txt', 512),
            ]
        );
    }

    public function testMaxWithHumanReadableSize()
    {
        $this->fails(
            File::default()->max('1024kb'),
            UploadedFile::fake()->create('foo.txt', 1025),
            ['validation.max.file']
        );

        $this->passes(
            File::default()->max('1024kb'),
            [
                UploadedFile::fake()->create('foo.txt', 1024),
                UploadedFile::fake()->create('foo.txt', 1023),
                UploadedFile::fake()->create('foo.txt', 512),
            ]
        );
    }

    public function testMaxWithHumanReadableSizeAndMultipleValue()
    {
        $this->fails(
            File::default()->max('1mb'),
            UploadedFile::fake()->create('foo.txt', 1025),
            ['validation.max.file']
        );

        $this->passes(
            File::default()->max('1mb'),
            [
                UploadedFile::fake()->create('foo.txt', 1000),
                UploadedFile::fake()->create('foo.txt', 999),
                UploadedFile::fake()->create('foo.txt', 512),
            ]
        );
    }

    public function testMacro()
    {
        File::macro('toDocument', function () {
            return static::default()->rules('mimes:txt,csv');
        });

        $this->fails(
            File::toDocument(),
            UploadedFile::fake()->create('foo.png'),
            ['validation.mimes']
        );

        $this->passes(
            File::toDocument(),
            [
                UploadedFile::fake()->create('foo.txt'),
                UploadedFile::fake()->create('foo.csv'),
            ]
        );
    }

    public function testItUsesTheCorrectValidationMessageForFile(): void
    {
        file_put_contents($path = __DIR__ . '/test.json', 'this-is-a-test');

        // $file = new \Illuminate\Http\File($path);
        $file = new UploadedFile($path = __DIR__ . '/test.json', 'this-is-a-test', null, null, null, true);

        $this->fails(
            ['max:0'],
            $file,
            ['validation.max.file']
        );

        unlink($path);
    }

    public function testItCanSetDefaultUsing()
    {
        $this->assertInstanceOf(File::class, File::default());

        File::defaults(function () {
            return File::types('txt')->max(12 * 1024);
        });

        $this->fails(
            File::default(),
            UploadedFile::fake()->create('foo.png', 13 * 1024),
            [
                'validation.mimes',
                'validation.max.file',
            ]
        );

        File::defaults(File::image()->between(1024, 2048));

        $this->passes(
            File::default(),
            UploadedFile::fake()->create('foo.png', (int) 1.5 * 1024),
        );
    }

    public function testFileSizeConversionWithDifferentUnits()
    {
        $this->passes(
            File::image()->size('5MB'),
            UploadedFile::fake()->create('foo.png', 5000)
        );

        $this->passes(
            File::image()->size(' 2gb '),
            UploadedFile::fake()->create('foo.png', 2 * 1000000)
        );

        $this->passes(
            File::image()->size('1Tb'),
            UploadedFile::fake()->create('foo.png', 1000000000)
        );

        $this->expectException(InvalidArgumentException::class);
        File::image()->size('10xyz');
    }
}
