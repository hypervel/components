<?php

declare(strict_types=1);

namespace Hypervel\Data\Enums;

use LogicException;

enum DataTypeKind: string
{
    case Default = 'Default';
    case Array = 'Array';
    case Iterable = 'Iterable';
    case Enumerable = 'Enumerable';
    case Paginator = 'Paginator';
    case CursorPaginator = 'CursorPaginator';
    case DataObject = 'DataObject';
    case DataCollection = 'DataCollection';
    case DataPaginatedCollection = 'DataPaginatedCollection';
    case DataCursorPaginatedCollection = 'DataCursorPaginatedCollection';
    case DataArray = 'DataArray';
    case DataIterable = 'DataIterable';
    case DataEnumerable = 'DataEnumerable';
    case DataPaginator = 'DataPaginator';
    case DataCursorPaginator = 'DataCursorPaginator';

    /**
     * Determine if this kind describes one data object.
     */
    public function isDataObject(): bool
    {
        return $this === self::DataObject;
    }

    /**
     * Determine if this kind describes an iterable of data objects.
     */
    public function isDataCollectable(): bool
    {
        return $this === self::DataCollection
            || $this === self::DataPaginatedCollection
            || $this === self::DataCursorPaginatedCollection
            || $this === self::DataArray
            || $this === self::DataIterable
            || $this === self::DataEnumerable
            || $this === self::DataPaginator
            || $this === self::DataCursorPaginator;
    }

    /**
     * Determine if this kind is related to data objects.
     */
    public function isDataRelated(): bool
    {
        return $this->isDataObject() || $this->isDataCollectable();
    }

    /**
     * Determine if this kind is not related to data objects.
     */
    public function isNonDataRelated(): bool
    {
        return $this === self::Default
            || $this === self::Array
            || $this === self::Iterable
            || $this === self::Enumerable
            || $this === self::Paginator
            || $this === self::CursorPaginator;
    }

    /**
     * Determine if this kind describes a non-data iterable.
     */
    public function isNonDataIterable(): bool
    {
        return $this === self::Array
            || $this === self::Iterable
            || $this === self::Enumerable
            || $this === self::Paginator
            || $this === self::CursorPaginator;
    }

    /**
     * Determine if this kind requires an offset paginator source.
     */
    public function isPaginator(): bool
    {
        return $this === self::Paginator
            || $this === self::DataPaginator
            || $this === self::DataPaginatedCollection;
    }

    /**
     * Determine if this kind requires a cursor paginator source.
     */
    public function isCursorPaginator(): bool
    {
        return $this === self::CursorPaginator
            || $this === self::DataCursorPaginator
            || $this === self::DataCursorPaginatedCollection;
    }

    /**
     * Get the equivalent kind containing data objects.
     */
    public function getDataRelatedEquivalent(): self
    {
        return match ($this) {
            self::Array => self::DataArray,
            self::Iterable => self::DataIterable,
            self::Enumerable => self::DataEnumerable,
            self::Paginator => self::DataPaginator,
            self::CursorPaginator => self::DataCursorPaginator,
            self::DataCollection => self::DataCollection,
            self::DataPaginatedCollection => self::DataPaginatedCollection,
            self::DataCursorPaginatedCollection => self::DataCursorPaginatedCollection,
            default => throw new LogicException("No data-related equivalent exists for [{$this->name}]."),
        };
    }
}
