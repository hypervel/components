<?php

declare(strict_types=1);

namespace Hypervel\Image;

use Closure;
use Exception;
use finfo;
use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Image\Driver;
use Hypervel\Contracts\Image\Transformation;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Http\UploadedFile;
use Hypervel\Image\Transformations\Blur;
use Hypervel\Image\Transformations\Contain;
use Hypervel\Image\Transformations\Cover;
use Hypervel\Image\Transformations\Crop;
use Hypervel\Image\Transformations\FlipHorizontally;
use Hypervel\Image\Transformations\FlipVertically;
use Hypervel\Image\Transformations\Grayscale;
use Hypervel\Image\Transformations\Orient;
use Hypervel\Image\Transformations\Resize;
use Hypervel\Image\Transformations\Rotate;
use Hypervel\Image\Transformations\Scale;
use Hypervel\Image\Transformations\Sharpen;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Stringable;
use UnitEnum;

class Image implements Responsable, Stringable
{
    use Conditionable;
    use Macroable;

    /**
     * The original image source.
     */
    protected ImageSource $source;

    /**
     * The image processing pipeline.
     */
    protected ImagePipeline $pipeline;

    /**
     * The driver override.
     */
    protected ?string $driver = null;

    /**
     * The processed image contents.
     */
    protected ?string $processedContents = null;

    /**
     * The cached MIME type.
     */
    protected ?string $mimeType = null;

    /**
     * The cached image dimensions.
     *
     * @var null|array{0: int, 1: int}
     */
    protected ?array $dimensions = null;

    /**
     * The cached dominant color.
     */
    protected ?string $dominantColor = null;

    /**
     * The cached hash name.
     */
    protected ?string $hashName = null;

    /**
     * Create a new image instance.
     */
    public function __construct(
        Closure|string $contents,
        protected ?UploadedFile $file = null,
    ) {
        $this->source = new ImageSource($contents);
        $this->pipeline = new ImagePipeline;
    }

    /**
     * Set the cover dimensions.
     *
     * @param positive-int $width
     * @param positive-int $height
     *
     * @throws ImageException
     */
    public function cover(int $width, int $height): static
    {
        $this->ensureValidDimensions($width, $height);

        return $this->transform(new Cover($width, $height));
    }

    /**
     * Set the contain dimensions.
     *
     * @param positive-int $width
     * @param positive-int $height
     *
     * @throws ImageException
     */
    public function contain(int $width, int $height, ?string $background = null): static
    {
        $this->ensureValidDimensions($width, $height);

        return $this->transform(new Contain($width, $height, $background));
    }

    /**
     * Crop the image to the given dimensions and position.
     *
     * @param positive-int $width
     * @param positive-int $height
     *
     * @throws ImageException
     */
    public function crop(int $width, int $height, int $x = 0, int $y = 0): static
    {
        $this->ensureValidDimensions($width, $height);

        return $this->transform(new Crop($width, $height, $x, $y));
    }

    /**
     * Resize the image to the given dimensions.
     *
     * @param null|positive-int $width
     * @param null|positive-int $height
     *
     * @throws ImageException
     */
    public function resize(?int $width = null, ?int $height = null): static
    {
        if ($width === null && $height === null) {
            throw new ImageException('At least one resize dimension must be specified.');
        }

        $this->ensureValidDimensions($width, $height);

        return $this->transform(new Resize($width, $height));
    }

    /**
     * Rotate the image clockwise by the given angle.
     */
    public function rotate(float $angle, ?string $background = null): static
    {
        return $this->transform(new Rotate($angle, $background));
    }

    /**
     * Set the scale dimensions.
     *
     * @param null|positive-int $width
     * @param null|positive-int $height
     *
     * @throws ImageException
     */
    public function scale(?int $width = null, ?int $height = null): static
    {
        if ($width === null && $height === null) {
            throw new ImageException('At least one scale dimension must be specified.');
        }

        $this->ensureValidDimensions($width, $height);

        return $this->transform(new Scale($width, $height));
    }

    /**
     * Ensure the image dimensions are valid.
     *
     * @throws ImageException
     */
    protected function ensureValidDimensions(?int $width, ?int $height): void
    {
        if ($width !== null && $width < 1) {
            throw new ImageException('Image width must be greater than zero.');
        }

        if ($height !== null && $height < 1) {
            throw new ImageException('Image height must be greater than zero.');
        }
    }

    /**
     * Auto-orient the image based on EXIF data.
     */
    public function orient(): static
    {
        return $this->transform(new Orient);
    }

    /**
     * Apply a blur effect.
     *
     * @param int<0, 100> $amount
     *
     * @throws ImageException
     */
    public function blur(int $amount = 5): static
    {
        $this->ensureValueIsBetween('blur amount', $amount, 0, 100);

        return $this->transform(new Blur($amount));
    }

    /**
     * Convert the image to grayscale.
     */
    public function grayscale(): static
    {
        return $this->transform(new Grayscale);
    }

    /**
     * Sharpen the image.
     *
     * @param int<0, 100> $amount
     *
     * @throws ImageException
     */
    public function sharpen(int $amount = 10): static
    {
        $this->ensureValueIsBetween('sharpen amount', $amount, 0, 100);

        return $this->transform(new Sharpen($amount));
    }

    /**
     * Flip the image vertically.
     */
    public function flipVertically(): static
    {
        return $this->transform(new FlipVertically);
    }

    /**
     * Flip the image horizontally.
     */
    public function flipHorizontally(): static
    {
        return $this->transform(new FlipHorizontally);
    }

    /**
     * Flip the image vertically.
     */
    public function flip(): static
    {
        return $this->flipVertically();
    }

    /**
     * Flip the image horizontally.
     */
    public function flop(): static
    {
        return $this->flipHorizontally();
    }

    /**
     * Add a transformation to the image pipeline.
     */
    public function transform(Transformation $transformation): static
    {
        return $this->withClone(fn (Image $image) => $image->pipeline->add($transformation));
    }

    /**
     * Set the optimization options.
     *
     * @param int<1, 100> $quality
     *
     * @throws ImageException
     */
    public function optimize(string $format = 'webp', int $quality = ImageOutputOptions::DEFAULT_QUALITY): static
    {
        return $this->toFormat($format)->quality($quality);
    }

    /**
     * Set the output quality.
     *
     * @param int<1, 100> $quality
     *
     * @throws ImageException
     */
    public function quality(int $quality): static
    {
        $this->ensureValueIsBetween('quality', $quality, 1, 100);

        return $this->withOutput(fn (ImageOutputOptions $output) => $output->quality = $quality);
    }

    /**
     * Ensure the value is within the given range.
     *
     * @throws ImageException
     */
    protected function ensureValueIsBetween(string $name, int $value, int $minimum, int $maximum): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new ImageException("Image {$name} must be between {$minimum} and {$maximum}.");
        }
    }

    /**
     * Convert the image to WebP format.
     */
    public function toWebp(): static
    {
        return $this->toFormat('webp');
    }

    /**
     * Convert the image to JPEG format.
     */
    public function toJpg(): static
    {
        return $this->toFormat('jpg');
    }

    /**
     * Convert the image to JPEG format.
     */
    public function toJpeg(): static
    {
        return $this->toJpg();
    }

    /**
     * Convert the image to PNG format.
     */
    public function toPng(): static
    {
        return $this->toFormat('png');
    }

    /**
     * Convert the image to GIF format.
     */
    public function toGif(): static
    {
        return $this->toFormat('gif');
    }

    /**
     * Convert the image to AVIF format.
     */
    public function toAvif(): static
    {
        return $this->toFormat('avif');
    }

    /**
     * Convert the image to HEIC format.
     */
    public function toHeic(): static
    {
        return $this->toFormat('heic');
    }

    /**
     * Convert the image to BMP format.
     */
    public function toBmp(): static
    {
        return $this->toFormat('bmp');
    }

    /**
     * Set the output format.
     *
     * @throws ImageException
     */
    public function toFormat(string $format): static
    {
        if (! in_array($format, ['webp', 'jpg', 'jpeg', 'png', 'gif', 'avif', 'heic', 'heif', 'bmp'], true)) {
            throw new ImageException("The [{$format}] format is not supported.");
        }

        $format = $format === 'heif' ? 'heic' : $format;

        return $this->withOutput(fn (ImageOutputOptions $output) => $output->format = $format);
    }

    /**
     * Store the processed image on a filesystem disk.
     *
     * @param array<string, mixed> $options
     */
    public function store(string $path = '', UnitEnum|string|null $disk = null, array $options = []): string|false
    {
        return $this->storeAs($path, $this->hashName(), $disk, $options);
    }

    /**
     * Store the processed image on a filesystem disk with public visibility.
     *
     * @param array<string, mixed> $options
     */
    public function storePublicly(string $path = '', UnitEnum|string|null $disk = null, array $options = []): string|false
    {
        $options['visibility'] = 'public';

        return $this->storeAs($path, $this->hashName(), $disk, $options);
    }

    /**
     * Store the processed image on a filesystem disk with a given name.
     *
     * @param array<string, mixed> $options
     */
    public function storeAs(string $path, ?string $name = null, UnitEnum|string|null $disk = null, array $options = []): string|false
    {
        if (is_null($name)) {
            [$path, $name] = ['', $path];
        }

        $path = trim($path . '/' . $name, '/');

        $result = Container::getInstance()->make(FilesystemFactory::class)
            ->disk($disk)
            ->put($path, $this->toBytes(), $options);

        return $result ? $path : false;
    }

    /**
     * Store the processed image on a filesystem disk with public visibility and a given name.
     *
     * @param array<string, mixed> $options
     */
    public function storePubliclyAs(string $path, ?string $name = null, UnitEnum|string|null $disk = null, array $options = []): string|false
    {
        if (is_null($name)) {
            [$path, $name] = ['', $path];
        }

        $options['visibility'] = 'public';

        return $this->storeAs($path, $name, $disk, $options);
    }

    /**
     * Get a hashed filename with the correct extension.
     */
    public function hashName(string $path = ''): string
    {
        $this->hashName ??= Str::random(40);

        $hash = $this->hashName . '.' . $this->extension();

        return $path ? $path . '/' . $hash : $hash;
    }

    /**
     * Process the image and return the raw bytes.
     */
    public function toBytes(): string
    {
        if (! $this->pipeline->hasChanges()) {
            return $this->source->contents();
        }

        return $this->processedContents ??= $this->process();
    }

    /**
     * Process the image recipe.
     *
     * @throws ImageException
     */
    protected function process(): string
    {
        try {
            return $this->resolveDriver()->process($this->source->contents(), $this->pipeline);
        } catch (ImageException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new ImageException("Failed to process image: {$exception->getMessage()}", 0, $exception);
        }
    }

    /**
     * Process the image and return as a base64 encoded string.
     */
    public function toBase64(): string
    {
        return base64_encode($this->toBytes());
    }

    /**
     * Process the image and return as a data URI.
     */
    public function toDataUri(): string
    {
        return 'data:' . $this->mimeType() . ';base64,' . $this->toBase64();
    }

    /**
     * Get the file extension based on the MIME type.
     */
    public function extension(): string
    {
        return match ($this->mimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif', 'image/x-avif' => 'avif',
            'image/heic', 'image/x-heic', 'image/heif' => 'heic',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            'image/tiff' => 'tiff',
            default => 'bin',
        };
    }

    /**
     * Get the MIME type of the processed image.
     */
    public function mimeType(): string
    {
        return $this->mimeType ??= (new finfo(FILEINFO_MIME_TYPE))->buffer($this->toBytes());
    }

    /**
     * Get the dimensions of the processed image.
     *
     * @return array{0: int, 1: int}
     *
     * @throws ImageException
     */
    public function dimensions(): array
    {
        if ($this->dimensions !== null) {
            return $this->dimensions;
        }

        $contents = $this->toBytes();

        // getimagesizefromstring() misreports HEIC's coded / padded frame size, so HEIC dimensions belong to the selected driver.
        if (in_array($this->mimeType(), ['image/heic', 'image/heif', 'image/x-heic'], true)) {
            $driver = $this->resolveDriver();

            try {
                return $this->dimensions = $driver->dimensions($contents);
            } catch (Exception $exception) {
                throw new ImageException(
                    "Unable to determine the dimensions of the image: {$exception->getMessage()}",
                    previous: $exception,
                );
            }
        }

        $size = @getimagesizefromstring($contents);

        if ($size === false) {
            throw new ImageException('Unable to determine the dimensions of the image.');
        }

        return $this->dimensions = [$size[0], $size[1]];
    }

    /**
     * Get the width of the processed image.
     */
    public function width(): int
    {
        return $this->dimensions()[0];
    }

    /**
     * Get the height of the processed image.
     */
    public function height(): int
    {
        return $this->dimensions()[1];
    }

    /**
     * Get the dominant (average) color of the image as a hex string.
     */
    public function dominantColor(): string
    {
        return $this->dominantColor ??= $this->resolveDriver()->dominantColor($this->toBytes());
    }

    /**
     * Set the driver to use for processing.
     */
    public function using(string $driver): static
    {
        $clone = clone $this;

        $clone->driver = $driver;

        return $clone;
    }

    /**
     * Use the GD driver for processing.
     */
    public function usingGd(): static
    {
        return $this->using('gd');
    }

    /**
     * Use the Imagick driver for processing.
     */
    public function usingImagick(): static
    {
        return $this->using('imagick');
    }

    /**
     * Resolve the image processing driver.
     */
    protected function resolveDriver(): Driver
    {
        /** @var ImageManager $manager */
        $manager = Container::getInstance()->make('image');

        return $this->driver !== null
            ? $manager->driver($this->driver)
            : $manager->driver();
    }

    /**
     * Get the underlying uploaded file instance.
     */
    public function file(): ?UploadedFile
    {
        return $this->file;
    }

    /**
     * Clone the pipeline and reset all derived state.
     */
    public function __clone(): void
    {
        $this->pipeline = clone $this->pipeline;
        $this->processedContents = null;
        $this->mimeType = null;
        $this->dimensions = null;
        $this->dominantColor = null;
        $this->hashName = null;
    }

    /**
     * Create an immutable clone with updated output options.
     */
    protected function withOutput(Closure $callback): static
    {
        return $this->withClone(fn (Image $image) => $callback($image->pipeline->output));
    }

    /**
     * Create an immutable clone with the given callback applied.
     */
    protected function withClone(Closure $callback): static
    {
        $clone = clone $this;

        $callback($clone);

        return $clone;
    }

    /**
     * Create an HTTP response that represents the image.
     */
    public function toResponse(Request $request): Response
    {
        return new Response($this->toBytes(), 200, [
            'Content-Type' => $this->mimeType(),
        ]);
    }

    /**
     * Prevent serialization of the image.
     *
     * @throws ImageException
     */
    public function __serialize(): never
    {
        throw new ImageException('Images cannot be serialized. Store the image first and serialize the path instead.');
    }

    /**
     * Get the string representation of the image.
     */
    public function toString(): string
    {
        return $this->toDataUri();
    }

    /**
     * Get the string representation of the image.
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
