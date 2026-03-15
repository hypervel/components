<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Xmlable;
use InvalidArgumentException;
use SimpleXMLElement;

class Xml
{
    /**
     * Convert the given data to an XML string.
     */
    public static function toXml(mixed $data, ?SimpleXMLElement $parentNode = null, string $root = 'root'): string
    {
        if ($data instanceof Xmlable) {
            return (string) $data;
        }
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        } else {
            $data = (array) $data;
        }
        if ($parentNode === null) {
            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?>' . "<{$root}></{$root}>");
        } else {
            $xml = $parentNode;
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::toXml($value, $xml->addChild($key));
            } else {
                if (is_numeric($key)) {
                    $xml->addChild('item' . $key, (string) $value);
                } else {
                    $xml->addChild($key, (string) $value);
                }
            }
        }
        return trim($xml->asXML());
    }

    /**
     * Convert the given XML string to an array.
     *
     * @throws InvalidArgumentException
     */
    public static function toArray(string $xml): array
    {
        $respObject = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR);

        if ($respObject === false) {
            throw new InvalidArgumentException('Syntax error.');
        }

        return json_decode(json_encode($respObject), true);
    }
}
