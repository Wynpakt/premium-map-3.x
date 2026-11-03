<?php

/**
 * A map pin needs a street, a postcode and a city.
 * Anything less is still stored, but Photon would place it on the first hit
 * for the country name.
 */
class MapAddress
{
    public static function complete($street, $zip, $city)
    {
        return trim((string) $street) !== ''
            && trim((string) $zip) !== ''
            && trim((string) $city) !== '';
    }

    public static function sql()
    {
        return "TRIM(item.street) <> '' AND TRIM(item.zip) <> '' AND TRIM(item.city) <> ''";
    }
}
