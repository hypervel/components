<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Http\UploadedFile;
use Hypervel\Support\Arr;
use Hypervel\Testbench\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Contracts\Translator as TranslatorContract;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\File;
use Hypervel\Validation\Validator;

/**
 * @internal
 * @coversNothing
 */
class ValidationImageFileRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(TranslatorContract::class, function () {
            return new Translator(
                new ArrayLoader(),
                'en'
            );
        });
    }

    public function testDimensions()
    {
        $this->fails(
            File::image()->dimensions(Rule::dimensions()->width(100)->height(100)),
            UploadedFile::fake()->image('foo.png', 101, 101),
            ['validation.dimensions'],
        );

        $this->passes(
            File::image()->dimensions(Rule::dimensions()->width(100)->height(100)),
            UploadedFile::fake()->image('foo.png', 100, 100),
        );
    }

    public function testDimensionsWithCustomImageSizeMethod()
    {
        $this->fails(
            File::image()->dimensions(Rule::dimensions()->width(100)->height(100)),
            new UploadedFileWithCustomImageSizeMethod(stream_get_meta_data($tmpFile = tmpfile())['uri'], 'foo.png'),
            ['validation.dimensions'],
        );

        $this->passes(
            File::image()->dimensions(Rule::dimensions()->width(200)->height(200)),
            new UploadedFileWithCustomImageSizeMethod(stream_get_meta_data($tmpFile = tmpfile())['uri'], 'foo.png'),
        );
    }

    public function testDimensionWithTheRatioMethod()
    {
        $this->fails(
            File::image()->dimensions(Rule::dimensions()->ratio(1)),
            UploadedFile::fake()->image('foo.png', 105, 100),
            ['validation.dimensions'],
        );

        $this->passes(
            File::image()->dimensions(Rule::dimensions()->ratio(1)),
            UploadedFile::fake()->image('foo.png', 100, 100),
        );
    }

    public function testDimensionWithTheMinRatioMethod()
    {
        $this->fails(
            File::image()->dimensions(Rule::dimensions()->minRatio(1 / 2)),
            UploadedFile::fake()->image('foo.png', 100, 100),
            ['validation.dimensions'],
        );

        $this->passes(
            File::image()->dimensions(Rule::dimensions()->minRatio(1 / 2)),
            UploadedFile::fake()->image('foo.png', 100, 200),
        );
    }

    public function testDimensionWithTheMaxRatioMethod()
    {
        $this->fails(
            File::image()->dimensions(Rule::dimensions()->maxRatio(1 / 2)),
            UploadedFile::fake()->image('foo.png', 100, 300),
            ['validation.dimensions'],
        );

        $this->passes(
            File::image()->dimensions(Rule::dimensions()->maxRatio(1 / 2)),
            UploadedFile::fake()->image('foo.png', 100, 100),
        );
    }

    public function testDimensionWithTheRatioBetweenMethod()
    {
        $this->fails(
            File::image()->dimensions(Rule::dimensions()->ratioBetween(1 / 2, 1 / 3)),
            UploadedFile::fake()->image('foo.png', 100, 100),
            ['validation.dimensions'],
        );

        $this->passes(
            File::image()->dimensions(Rule::dimensions()->ratioBetween(1 / 2, 1 / 3)),
            UploadedFile::fake()->image('foo.png', 100, 200),
        );
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
                $this->app->get(TranslatorContract::class),
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
}

class UploadedFileWithCustomImageSizeMethod extends UploadedFile
{
    public function isValid(): bool
    {
        return true;
    }

    public function getExtension(): string
    {
        return 'png';
    }

    public function dimensions()
    {
        return [200, 200];
    }
}
