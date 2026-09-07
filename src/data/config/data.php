<?php

declare(strict_types=1);

use Hypervel\Data\Support\Creation\ValidationStrategy;

return [
    /*
     * The package will use this format when working with dates. If this option
     * is an array, it will try to convert from the first format that works,
     * and will serialize dates using the first format from the array.
     */
    'date_format' => DATE_ATOM,

    /*
     * When transforming or casting dates, the following timezone will be used to
     * convert the date to the correct timezone. If set to null no timezone will
     * be passed.
     */
    'date_timezone' => null,

    /*
     * Custom global transformers override the package's fixed transformation for
     * their declared types.
     */
    'transformers' => [],

    /*
     * Custom global casts override the package's fixed casting for their declared
     * types.
     */
    'casts' => [],

    /*
     * Custom global normalizers run after normalizers declared by the data class
     * and before the package's fixed source normalization.
     */
    'normalizers' => [],

    /*
     * Data objects can be wrapped into a key like 'data' when used as a resource,
     * this key can be set globally here for all data objects. You can pass in
     * `null` if you want to disable wrapping.
     */
    'wrap' => null,

    /*
     * A data object can be validated when created using a factory or when calling the from
     * method. By default, only when a request is passed the data is being validated. This
     * behaviour can be changed to always validate or to completely disable validation.
     */
    'validation_strategy' => ValidationStrategy::OnlyRequests->value,

    /*
     * A data object can map the names of its properties when transforming (output) or when
     * creating (input). By default, the package will not map any names. You can set a
     * global strategy here, or override it on a specific data object.
     */
    'name_mapping_strategy' => [
        'input' => null,
        'output' => null,
    ],

    /*
     * When transforming a nested chain of data objects, the package can end up in an infinite
     * loop when including a recursive relationship. The max transformation depth can be
     * set as a safety measure to prevent this from happening. When set to null, the
     * package will not enforce a maximum depth.
     */
    'max_transformation_depth' => null,
];
