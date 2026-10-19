<?php

/**
 * Groups Collmex addresses that look like the same place.
 * The map keeps both markers. Merging happens in Collmex, not by hiding a row.
 */
class DuplicateFinder
{
    public static function normalize($street, $zip, $city)
    {
        $street = self::fold($street);
        $zip = preg_replace('/[^0-9]/', '', (string) $zip);
        $city = self::fold($city);

        return $street . '|' . $zip . '|' . $city;
    }

    public static function pointKey($lat, $lng)
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        if ((float) $lat == 0.0 && (float) $lng == 0.0) {
            return null;
        }

        return round((float) $lat, 4) . ',' . round((float) $lng, 4);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{address:array,point:array}
     */
    public static function groups(array $rows)
    {
        $byAddress = array();
        $byPoint = array();
        foreach ($rows as $row) {
            $addressKey = self::normalize(
                isset($row['street']) ? $row['street'] : '',
                isset($row['zip']) ? $row['zip'] : '',
                isset($row['city']) ? $row['city'] : ''
            );
            if ($addressKey !== '||') {
                $byAddress[$addressKey][] = $row;
            }
            $point = self::pointKey(
                isset($row['lat']) ? $row['lat'] : null,
                isset($row['lng']) ? $row['lng'] : null
            );
            if ($point !== null) {
                $byPoint[$point][] = $row;
            }
        }

        return array(
            'address' => self::onlyGroups($byAddress),
            'point' => self::onlyGroups($byPoint),
        );
    }

    private static function onlyGroups(array $buckets)
    {
        $out = array();
        foreach ($buckets as $key => $rows) {
            if (count($rows) > 1) {
                $out[$key] = $rows;
            }
        }

        return $out;
    }

    private static function fold($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}
