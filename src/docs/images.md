# Image Manipulation

- [Introduction](#introduction)
- [Installation](#installation)
    - [Configuration](#configuration)
- [Reading Images](#reading-images)
    - [Uploaded Files](#uploaded-files)
    - [Storage Files](#storage-files)
    - [Other Sources](#other-sources)
- [Manipulating Images](#manipulating-images)
    - [Resizing Images](#resizing-images)
    - [Other Transformations](#other-transformations)
- [Encoding Images](#encoding-images)
- [Storing Images](#storing-images)
- [Inspecting Images](#inspecting-images)
- [Returning Images](#returning-images)
- [Image Drivers](#image-drivers)
    - [Custom Image Drivers](#custom-image-drivers)
    - [Custom Transformations](#custom-transformations)

<a name="introduction"></a>
## Introduction

Hypervel provides a fluent image manipulation API that allows you to resize, crop, encode, and store images using the same expressive conventions found throughout the framework. The bundled GD and Imagick drivers are powered by [Intervention Image](https://image.intervention.io/), while custom drivers may use any image processing backend.

The image API is useful when working with uploaded files, files stored on Hypervel [filesystem disks](/docs/{{version}}/filesystem), local files, remote URLs, streams, or raw image bytes:

```php
use Hypervel\Support\Facades\Image;

$path = Image::fromStorage('avatars/photo.jpg', 'public')
    ->cover(400, 400)
    ->toWebp()
    ->quality(80)
    ->storePublicly('avatars', 'public');
```

> [!WARNING]
> Image decoding, transformation, and encoding are CPU-bound work. While an image is being processed, the worker cannot run other coroutines. An image family also retains its original source while processed variants retain their output bytes. Perform expensive or batch image processing on a [queued job](/docs/{{version}}/queues) instead of during the HTTP request that receives the upload.

<a name="installation"></a>
## Installation

Install Hypervel's image package via Composer:

```shell
composer require hypervel/image
```

If you will use the bundled GD or Imagick driver, also install Intervention Image:

```shell
composer require intervention/image:^4.0
```

You should also ensure your PHP installation has the matching GD or Imagick extension. A custom driver does not require Intervention Image or either bundled extension.

<a name="configuration"></a>
### Configuration

You may publish Hypervel's image configuration file using the `image-config` tag:

```shell
php artisan vendor:publish --tag=image-config
```

The image configuration file allows you to specify your application's default image driver. You may also specify the default driver using the `IMAGE_DRIVER` environment variable. The bundled drivers are `gd` and `imagick`, and registered custom drivers may also be selected:

```ini
IMAGE_DRIVER=imagick
```

<a name="reading-images"></a>
## Reading Images

The `Image` facade provides several methods for reading images from common sources. Image contents are loaded lazily when the image is first inspected, processed, or stored. The source is resolved once and shared by every variant derived from the image, including variants processed concurrently.

<a name="uploaded-files"></a>
### Uploaded Files

You may retrieve an uploaded image from an incoming request using the `image` method. This method returns a `Hypervel\Image\Image` instance for the uploaded file, or `null` if the file is not present:

```php
use Hypervel\Http\Request;

Route::post('/avatar', function (Request $request) {
    $request->validate(['avatar' => ['required', 'image']]);

    $path = $request->image('avatar')
        ->cover(400, 400)
        ->toWebp()
        ->storePublicly('avatars', 'public');

    // ...
});
```

Alternatively, you may create an image instance from a `Hypervel\Http\UploadedFile` instance using the `fromUpload` method:

```php
use Hypervel\Support\Facades\Image;

$image = Image::fromUpload($request->file('avatar'));
```

When an image is created from an uploaded file, you may retrieve the underlying uploaded file using the `file` method:

```php
$file = $image->file();
```

<a name="storage-files"></a>
### Storage Files

You may create an image instance from a file stored on one of your application's [filesystem disks](/docs/{{version}}/filesystem) using the `fromStorage` method. The first argument is the path to the file, while the second argument is the disk name:

```php
use Hypervel\Support\Facades\Image;

$image = Image::fromStorage('avatars/photo.jpg', disk: 'public');
```

You may also create image instances directly from a filesystem disk instance using the `image` method:

```php
use Hypervel\Support\Facades\Storage;

$image = Storage::disk('public')->image('avatars/photo.jpg');
```

<a name="other-sources"></a>
### Other Sources

The `Image` facade also includes methods for creating image instances from raw bytes, Base64 encoded strings, local file paths, remote URLs, and open streams:

```php
use Hypervel\Support\Facades\Image;

$image = Image::fromBytes($contents);
$image = Image::fromBase64($base64);
$image = Image::fromPath(storage_path('app/avatars/photo.jpg'));
$image = Image::fromUrl('https://example.com/photo.jpg');
$image = Image::fromStream($stream);
```

Remote URL requests are deferred until the image is first materialized. HTTP client and server error responses throw a `Hypervel\Http\Client\RequestException` from the [HTTP client](/docs/{{version}}/http-client#error-handling), and their response bodies are not passed to an image driver.

Streams remain owned by the caller and must stay open until the image is first materialized, such as when it is inspected, converted to bytes, processed, or stored. Hypervel reads the stream once but does not close it for you.

<a name="manipulating-images"></a>
## Manipulating Images

Image instances are immutable. Each manipulation method returns a new image instance with the transformation appended to its processing pipeline, allowing methods to be chained fluently:

```php
$image = $request->image('avatar')
    ->orient()
    ->cover(400, 400)
    ->sharpen(10);
```

Transformations are processed in the order they are added and the image is encoded once at the end of the complete recipe. Inspecting or encoding an image does not replace its original source or discard its pipeline, so you may append another transformation afterward without decoding and re-encoding an intermediate result.

Transformed output bytes and inspected metadata are cached on each image instance, while untransformed source bytes are cached across the image family. A new manipulation creates a variant with fresh derived caches while retaining the shared original source. When processing variants concurrently, create each immutable variant before dispatching it; the variants will share one source read while retaining their own recipes and output caches.

<a name="resizing-images"></a>
### Resizing Images

The `resize` method resizes an image to the given dimensions. You may provide both a width and height, or provide only one dimension using named arguments:

```php
$image = $image->resize(800, 600);
$image = $image->resize(width: 800);
$image = $image->resize(height: 600);
```

The `scale` method proportionally scales an image down so that it fits within the given dimensions. This method will never increase the size of an image:

```php
$image = $image->scale(800, 600);
$image = $image->scale(width: 800);
$image = $image->scale(height: 600);
```

The `cover` method resizes and crops an image to completely cover the given dimensions:

```php
$image = $image->cover(400, 400);
```

The `contain` method resizes an image to fit within the given dimensions while preserving the entire image. If necessary, empty space will be filled using the optional background color:

```php
$image = $image->contain(400, 400);
$image = $image->contain(400, 400, '#ffffff');
$image = $image->contain(400, 400, 'dominant');
```

You may specify `dominant` as the background color to fill empty space using the image's dominant color.

You may crop an image using the `crop` method. The first two arguments are the desired width and height, and the optional third and fourth arguments specify the crop's `x` and `y` coordinates:

```php
$image = $image->crop(300, 200);
$image = $image->crop(300, 200, x: 50, y: 25);
```

Any width or height you provide must be greater than zero. Invalid values throw a `Hypervel\Image\ImageException`.

<a name="other-transformations"></a>
### Other Transformations

Hypervel also provides a variety of additional image transformation methods:

```php
$image = $image->orient();
$image = $image->rotate(90);
$image = $image->rotate(90, '#ffffff');
$image = $image->rotate(90, 'dominant');
$image = $image->blur(5);
$image = $image->grayscale();
$image = $image->sharpen(10);
$image = $image->flipVertically();
$image = $image->flipHorizontally();
```

The `orient` method rotates the image according to its EXIF orientation data. The `rotate` method rotates the image clockwise by the given angle and accepts an optional background color. The `blur` and `sharpen` methods accept values between `0` and `100`. Values outside this range throw an `ImageException`.

<a name="conditional-transformations"></a>
#### Conditional Transformations

Image instances support Hypervel's `Conditionable` trait, allowing you to conditionally apply transformations using the `when` and `unless` methods:

```php
$image = $request->image('avatar')
    ->when($request->boolean('crop'), fn ($image) => $image->cover(400, 400))
    ->unless($request->boolean('preserve_format'), fn ($image) => $image->toWebp());
```

<a name="encoding-images"></a>
## Encoding Images

By default, processed images are encoded using their original format. However, you may convert the image to another supported format before retrieving or storing it:

```php
$image = $image->toWebp();
$image = $image->toJpg();
$image = $image->toJpeg();
$image = $image->toPng();
$image = $image->toGif();
$image = $image->toAvif();
$image = $image->toHeic();
$image = $image->toBmp();
```

The public `toFormat` method accepts `webp`, `jpg`, `jpeg`, `png`, `gif`, `avif`, `heic`, `heif`, and `bmp`. The `heif` spelling is normalized to `heic`:

```php
$image = $image->toFormat('heif');
```

You may use the `quality` method to set the output quality. The quality must be between `1` and `100`; values outside this range throw an `ImageException`:

```php
$image = $image->toWebp()->quality(80);
```

The `optimize` method is a convenient shortcut for converting the image to a given format and setting its quality. By default, images are optimized as WebP images with a quality of `70`:

```php
$image = $image->optimize();

$image = $image->optimize(format: 'jpg', quality: 85);
```

You may retrieve the processed image contents as a string of bytes, base64 encoded string, or data URI:

```php
$bytes = $image->toBytes();
$base64 = $image->toBase64();
$dataUri = $image->toDataUri();
```

An image instance may also be cast to a string to retrieve its data URI:

```php
$dataUri = (string) $image;
```

<a name="storing-images"></a>
## Storing Images

The `store` method stores the processed image on one of your application's filesystem disks. Like uploaded files, Hypervel will generate a unique filename and return the stored path. The second argument may be used to specify the disk:

```php
$path = $request->image('avatar')
    ->cover(400, 400)
    ->store(path: 'avatars');

$path = $request->image('avatar')
    ->cover(400, 400)
    ->store(path: 'avatars', disk: 's3');
```

Calling `store` without arguments stores the image at the root of the default disk. Disk names may be strings, backed enums, or unit enums. Backed enums use their value, while unit enums use their case name:

```php
enum FilesystemDisk: string
{
    case Media = 'media';
}

$path = $image->store();
$path = $image->store(path: 'avatars', disk: FilesystemDisk::Media);
```

You may use the `storeAs` method to specify the stored filename:

```php
$path = $request->image('avatar')
    ->cover(400, 400)
    ->storeAs(path: 'avatars', name: 'avatar.jpg', disk: 'public');

$path = $image->storeAs('avatar.jpg');
```

The `storePublicly` and `storePubliclyAs` methods store the image with `public` visibility:

```php
$path = $request->image('avatar')
    ->cover(400, 400)
    ->storePublicly(path: 'avatars', disk: 'public');

$path = $request->image('avatar')
    ->cover(400, 400)
    ->storePubliclyAs(path: 'avatars', name: 'avatar.webp', disk: 'public');

$path = $image->storePubliclyAs('avatar.webp');
```

If the image could not be stored, the storage methods return `false`.

The image package has no separate tenant namespace or partition setting. Use tenant-scoped filesystem disks and paths to isolate stored data. Images created through a dynamic scoped filesystem capture that disk and prefix when the image is created, even though the file contents remain lazy.

<a name="inspecting-images"></a>
## Inspecting Images

You may retrieve the image's MIME type, extension, dimensions, width, height, and dominant color using the following methods:

```php
$mimeType = $image->mimeType();
$extension = $image->extension();

[$width, $height] = $image->dimensions();
$width = $image->width();
$height = $image->height();

$dominantColor = $image->dominantColor();
```

These methods operate on the processed image. For example, calling `width` after `cover(400, 400)` will return `400`. MIME type, dimensions, dominant color, and transformed output bytes are cached on that image instance after their first successful resolution.

<a name="returning-images"></a>
## Returning Images

Image instances implement Hypervel's `Responsable` contract, so you may return an image directly from a route or controller. Hypervel returns the processed bytes with the detected image MIME type:

```php
use Hypervel\Support\Facades\Image;

Route::get('/avatar', function () {
    return Image::fromStorage('avatars/photo.jpg', 'public')
        ->cover(400, 400)
        ->toWebp();
});
```

<a name="image-drivers"></a>
## Image Drivers

<a name="custom-image-drivers"></a>
### Custom Image Drivers

Hypervel's image manager extends the base `Hypervel\Support\Manager` class. You may register custom image drivers using the `extend` method available on the image manager and `Image` facade.

The driver contract is backend-neutral. A custom driver may use vips, another PHP library, a command-line tool, or a remote service without extending the bundled Intervention driver or installing Intervention Image. A driver must implement all four methods on the `Hypervel\Contracts\Image\Driver` interface. The following incomplete skeleton illustrates the contract; replace each placeholder body with operations provided by your chosen backend:

```php
<?php

namespace App\Images;

use Hypervel\Contracts\Image\Driver;
use Hypervel\Contracts\Image\Transformation;
use Hypervel\Image\ImagePipeline;

class VipsDriver implements Driver
{
    /**
     * The registered custom transformation handlers.
     *
     * @var array<class-string<Transformation>, callable>
     */
    protected array $handlers = [];

    /**
     * Process the given image contents with the specified pipeline.
     */
    public function process(string $contents, ImagePipeline $pipeline): string
    {
        // Decode with vips, apply each transformation in order, apply the
        // output format and quality, then encode once.

        return $contents;
    }

    /**
     * Return the image dimensions as [$width, $height].
     */
    public function dimensions(string $contents): array
    {
        // Decode with vips and return the real dimensions.

        return [0, 0];
    }

    /**
     * Return the dominant color as a seven-character RGB hex value.
     */
    public function dominantColor(string $contents): string
    {
        // Calculate the dominant color with vips.

        return '#000000';
    }

    /**
     * Register a custom transformation handler during worker boot.
     */
    public function transformUsing(string $transformation, callable $callback): static
    {
        $this->handlers[$transformation] = $callback;

        return $this;
    }
}
```

> [!NOTE]
> To see how a complete driver applies transformations and output options, review Hypervel's built-in `Hypervel\Image\Drivers\InterventionDriver`. Only the bundled GD and Imagick drivers depend on Intervention Image.

Image managers and resolved drivers are cached for the worker lifetime. Drivers must remain stateless and coroutine-safe: retain only immutable configuration or a concurrency-safe client. Do not retain image contents, request or tenant data, pipelines, native image handles, or decoded images. Treat the `ImagePipeline` passed to `process` as read-only and do not retain it after the call returns.

Register custom drivers during worker boot, typically in a service provider's `boot` method. The registration and resolved driver affect every subsequent request handled by that worker:

```php
use App\Images\VipsDriver;
use Hypervel\Contracts\Container\Container;
use Hypervel\Support\Facades\Image;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Image::extend(
        'vips',
        fn (Container $container): VipsDriver => $container->make(VipsDriver::class),
    );
}
```

After registering the driver, you may use it for a specific image using the `using` method:

```php
$image = $request->image('avatar')
    ->using('vips')
    ->cover(400, 400);
```

You may also configure a custom driver as your application's default image driver using the `default` option in your application's `config/images.php` configuration file or the `IMAGE_DRIVER` environment variable:

```ini
IMAGE_DRIVER=vips
```

The image package has no tenant-specific driver registry. If a backend varies by tenant, keep a resolver or concurrency-safe client provider on the cached driver and resolve the current tenant inside each `process`, `dimensions`, or `dominantColor` call. Never resolve tenant credentials while constructing the cached driver or retain the resolved tenant value after the operation.

<a name="custom-transformations"></a>
### Custom Transformations

Applications and packages may define custom transformations by creating an immutable class that implements the `Hypervel\Contracts\Image\Transformation` contract. Image variants share transformation objects, so transformation state must never change after construction. Custom transformations can then be added to an image pipeline using the `transform` method:

```php
<?php

namespace App\Images\Transformations;

use Hypervel\Contracts\Image\Transformation;

readonly class Pixelate implements Transformation
{
    public function __construct(
        public int $size,
    ) {
        //
    }
}
```

Next, register a handler for the transformation and driver using the `Image` facade's `transformUsing` method. Register handlers during worker boot because each handler remains on the cached driver and affects every subsequent request:

```php
use App\Images\Transformations\Pixelate;
use Hypervel\Support\Facades\Image;
use Intervention\Image\Interfaces\ImageInterface;

Image::transformUsing('gd', Pixelate::class, function (ImageInterface $image, Pixelate $transformation): ImageInterface {
    return $image->pixelate($transformation->size);
});
```

Once the transformation handler has been registered, you may apply the transformation to an image:

```php
use App\Images\Transformations\Pixelate;

$image = $request->image('avatar')
    ->transform(new Pixelate(12))
    ->store('avatars');
```
