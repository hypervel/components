<?php

declare(strict_types=1);

namespace Hypervel\Tinker;

use Exception;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Application;
use Hypervel\Process\ProcessResult;
use Hypervel\Support\Collection;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Stringable;
use Symfony\Component\VarDumper\Caster\Caster;

class TinkerCaster
{
    /**
     * Application methods to include in the presenter.
     */
    private static array $appProperties = [
        'configurationIsCached',
        'environment',
        'environmentFile',
        'isLocal',
        'routesAreCached',
        'runningUnitTests',
        'version',
        'path',
        'basePath',
        'configPath',
        'databasePath',
        'langPath',
        'publicPath',
        'storagePath',
        'bootstrapPath',
    ];

    /**
     * Get an array representing the properties of an application.
     */
    public static function castApplication(Application $app): array
    {
        $results = [];

        foreach (self::$appProperties as $property) {
            try {
                $val = $app->{$property}();

                if (! is_null($val)) {
                    $results[Caster::PREFIX_VIRTUAL . $property] = $val;
                }
            } catch (Exception $e) {
            }
        }

        return $results;
    }

    /**
     * Get an array representing the properties of a collection.
     */
    public static function castCollection(Collection $collection): array
    {
        return [
            Caster::PREFIX_VIRTUAL . 'all' => $collection->all(),
        ];
    }

    /**
     * Get an array representing the properties of an html string.
     */
    public static function castHtmlString(HtmlString $htmlString): array
    {
        return [
            Caster::PREFIX_VIRTUAL . 'html' => $htmlString->toHtml(),
        ];
    }

    /**
     * Get an array representing the properties of a fluent string.
     */
    public static function castStringable(Stringable $stringable): array
    {
        return [
            Caster::PREFIX_VIRTUAL . 'value' => (string) $stringable,
        ];
    }

    /**
     * Get an array representing the properties of a process result.
     */
    public static function castProcessResult(ProcessResult $result): array
    {
        return [
            Caster::PREFIX_VIRTUAL . 'output' => $result->output(),
            Caster::PREFIX_VIRTUAL . 'errorOutput' => $result->errorOutput(),
            Caster::PREFIX_VIRTUAL . 'exitCode' => $result->exitCode(),
            Caster::PREFIX_VIRTUAL . 'successful' => $result->successful(),
        ];
    }

    /**
     * Get an array representing the properties of a model.
     */
    public static function castModel(Model $model): array
    {
        $attributes = array_merge(
            $model->getAttributes(),
            $model->getRelations()
        );

        $visible = array_flip(
            $model->getVisible() ?: array_diff(array_keys($attributes), $model->getHidden())
        );

        $hidden = array_flip($model->getHidden());

        $appends = (function () {
            return array_combine($this->appends, $this->appends); // @phpstan-ignore-line
        })->bindTo($model, $model)();

        foreach ($appends as $appended) {
            $attributes[$appended] = $model->{$appended};
        }

        $results = [];

        foreach ($attributes as $key => $value) {
            $prefix = '';

            if (isset($visible[$key])) {
                $prefix = Caster::PREFIX_VIRTUAL;
            }

            if (isset($hidden[$key])) {
                $prefix = Caster::PREFIX_PROTECTED;
            }

            $results[$prefix . $key] = $value;
        }

        return $results;
    }
}
