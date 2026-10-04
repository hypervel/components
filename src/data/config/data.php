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

    'features' => [
        /*
         * When trying to set a computed property value, the package will throw an exception.
         * You can disable this behavior by setting this option to true, which will then just
         * ignore the value being passed into the computed property and recalculate it.
         */
        'ignore_exception_when_trying_to_set_computed_property_value' => false,
    ],

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
     * Rule inferrers adjust the validation rules of every data property after
     * the package's fixed rule inference. Only add your own inferrers here.
     */
    'rule_inferrers' => [],

    /*
     * Custom global normalizers run after normalizers declared by the data class
     * and before the package's fixed source normalization. The optional form
     * request normalizer reads only a form request's validated input.
     */
    'normalizers' => [
        // Hypervel\Data\Normalizers\FormRequestNormalizer::class,
    ],

    /*
     * Data objects can be wrapped into a key like 'data' when used as a resource,
     * this key can be set globally here for all data objects. You can pass in
     * `null` if you want to disable wrapping.
     */
    'wrap' => null,

    /*
     * Adds a specific caster to the Symfony VarDumper component which hides
     * some properties from data objects and collections when being dumped
     * by `dump` or `dd`. Can be 'enabled', 'disabled' or 'development'
     * which will only enable the caster locally.
     */
    'var_dumper_caster_mode' => 'development',

    /*
     * A data object can be validated when created using a factory or when calling the from
     * method. By default, only when a request is passed the data is being validated. This
     * behavior can be changed to always validate or to completely disable validation.
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
     * When a nested include, exclude, only or except partial targets a property that does
     * not exist, the package will throw an exception. You can disable this behavior by
     * setting this option to true.
     */
    'ignore_invalid_partials' => false,

    /*
     * When transforming a nested chain of data objects, the package can end up in an infinite
     * loop when including a recursive relationship. The max transformation depth can be
     * set as a safety measure to prevent this from happening. When set to null, the
     * package will not enforce a maximum depth.
     */
    'max_transformation_depth' => null,

    /*
     * When the maximum transformation depth is reached, the package will throw an exception.
     * You can disable this behavior by setting this option to false which will return an
     * empty array. Values stored by Eloquent casts always throw instead of being truncated.
     */
    'throw_when_max_transformation_depth_reached' => true,

    /*
     * When using the `make:data` command, the package will use these settings to generate
     * the data classes. You can override these settings by passing options to the command.
     */
    'commands' => [
        /*
         * Provides default configuration for the `make:data` command. These settings can be overridden with options
         * passed directly to the `make:data` command for generating single Data classes, or if not set they will
         * automatically fall back to these defaults. See `php artisan make:data --help` for more information
         */
        'make' => [
            /*
             * The default namespace for generated Data classes. This exists under the application's root namespace,
             * so the default 'Data' will end up as '\App\Data', and generated Data classes will be placed in the
             * app/Data/ folder. Data classes can live anywhere, but this is where `make:data` will put them.
             */
            'namespace' => 'Data',

            /*
             * This suffix will be appended to all data classes generated by make:data, so that they are less likely
             * to conflict with other related classes, controllers or models with a similar name without resorting
             * to adding an alias for the Data object. Set to a blank string (not null) to disable.
             */
            'suffix' => 'Data',
        ],
    ],
];
