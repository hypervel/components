<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\Rules;

use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Validator;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\File;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * @internal
 * @coversNothing
 */
class FileValidationTest extends TestCase
{
    #[TestWith(['0'])]
    #[TestWith(['.'])]
    #[TestWith(['*'])]
    #[TestWith(['__asterisk__'])]
    public function testItCanValidateAttributeAsArray(string $attribute): void
    {
        $file = UploadedFile::fake()->create('hypervel.png', 1, 'image/png');

        $validator = Validator::make([
            'files' => [
                $attribute => $file,
            ],
        ], [
            'files.*' => ['required', File::types(['image/png', 'image/jpeg'])],
        ]);

        $this->assertTrue($validator->passes());
    }

    #[TestWith(['0'])]
    #[TestWith(['.'])]
    #[TestWith(['*'])]
    #[TestWith(['__asterisk__'])]
    public function testItCanValidateAttributeAsArrayWhenValidationShouldFails(string $attribute): void
    {
        $file = UploadedFile::fake()->create('hypervel.php', 1, 'image/php');

        $validator = Validator::make([
            'files' => [
                $attribute => $file,
            ],
        ], [
            'files.*' => ['required', File::types($mimes = ['image/png', 'image/jpeg'])],
        ]);

        $this->assertFalse($validator->passes());

        $this->assertSame([
            0 => __('validation.mimetypes', ['attribute' => sprintf('files.%s', str_replace('_', ' ', $attribute)), 'values' => implode(', ', $mimes)]),
        ], $validator->messages()->all());
    }

    public function testFileCustomValidationMessages()
    {
        $validator = Validator::make(
            [
                'one' => UploadedFile::fake()->create('photo', 1000),
                'two' => 'not-a-file',
            ],
            [
                'one' => [File::default()->max(50)],
                'two' => [File::default()->max(50)],
            ],
            [
                'one.max' => 'File one is too large',
                'one.file' => 'File one is not a file',
                'two.max' => 'File two is too large',
                'two.file' => 'File two is not a file',
            ]
        );

        $this->assertTrue($validator->fails());

        $this->assertSame([
            'File one is too large',
            'File two is not a file',
        ], $validator->messages()->all());
    }

    public function testFileMimesCustomValidationMessages()
    {
        $validator = Validator::make(
            ['document' => UploadedFile::fake()->create('file.pdf')],
            ['document' => [File::types(['jpg', 'png'])]],
            ['document.mimes' => 'Wrong file type']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['Wrong file type'], $validator->messages()->all());
    }

    public function testFileMinSizeCustomValidationMessages()
    {
        $validator = Validator::make(
            ['upload' => UploadedFile::fake()->create('small.pdf', 50)],
            ['upload' => [File::types(['pdf'])->min(100)]],
            ['upload.min' => 'File too small']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['File too small'], $validator->messages()->all());
    }

    public function testFileMaxSizeCustomValidationMessages()
    {
        $validator = Validator::make(
            ['upload' => UploadedFile::fake()->create('large.pdf', 2000)],
            ['upload' => [File::types(['pdf'])->max(1024)]],
            ['upload.max' => 'File exceeds limit']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['File exceeds limit'], $validator->messages()->all());
    }

    public function testFileDimensionCustomValidationMessages()
    {
        $validator = Validator::make(
            ['image' => UploadedFile::fake()->image('foo.jpg', 100, 100)],
            ['image' => [File::image()->dimensions(Rule::dimensions()->width(50)->height(50))]],
            ['image.dimensions' => 'Invalid dimensions']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['Invalid dimensions'], $validator->messages()->all());
    }

    public function testFileBetweenCustomValidationMessages()
    {
        $validator = Validator::make(
            ['file' => UploadedFile::fake()->create('foo.pdf', 10)],
            ['file' => [File::types(['pdf'])->between(100, 1000)]],
            ['file.between' => 'Size out of range']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['Size out of range'], $validator->messages()->all());
    }

    public function testImageCustomValidationMessages()
    {
        $validator = Validator::make(
            ['avatar' => UploadedFile::fake()->create('foo.txt')],
            ['avatar' => [File::image()]],
            ['avatar.image' => 'Not an image']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['Not an image'], $validator->messages()->all());
    }

    public function testFileMultipleCustomValidationMessages()
    {
        $validator = Validator::make(
            ['photo' => UploadedFile::fake()->create('foo.pdf', 5000)],
            ['photo' => [File::types(['jpg', 'png'])->max(1024)]],
            [
                'photo.mimes' => 'Invalid type',
                'photo.max' => 'Too large',
            ]
        );

        $this->assertTrue($validator->fails());

        $messages = $validator->messages()->all();

        $this->assertContains('Invalid type', $messages);
        $this->assertContains('Too large', $messages);
    }

    public function testFileSizeCustomValidationMessages()
    {
        $validator = Validator::make(
            ['file' => UploadedFile::fake()->create('doc.pdf', 500)],
            ['file' => [File::types(['pdf'])->size(100)]],
            ['file.size' => 'File must be exactly 100KB']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['File must be exactly 100KB'], $validator->messages()->all());
    }

    public function testFileExtensionsCustomValidationMessages()
    {
        $validator = Validator::make(
            ['file' => UploadedFile::fake()->create('foo.pdf')],
            ['file' => [File::default()->extensions(['txt', 'doc'])]],
            ['file.extensions' => 'Invalid file extension']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['Invalid file extension'], $validator->messages()->all());
    }

    public function testFileEncodingCustomValidationMessages()
    {
        $validator = Validator::make(
            ['file' => UploadedFile::fake()->createWithContent('foo.txt', "\xf0\x28\x8c\x28")],
            ['file' => [File::types(['txt'])->encoding('UTF-8')]],
            ['file.encoding' => 'Invalid file encoding']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['Invalid file encoding'], $validator->messages()->all());
    }
}
