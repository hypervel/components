<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Image Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default image processing driver that will be
    | used when manipulating or converting images. This driver is always
    | utilized unless another driver is explicitly specified instead.
    |
    | Bundled: "gd", "imagick"; registered custom drivers are also supported.
    |
    */

    'default' => env('IMAGE_DRIVER', 'gd'),
];
