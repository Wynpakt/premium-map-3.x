<?php

/**
 * Query builder and GeoJSON parser for a local Photon 0.5 instance.
 * Photon has no structured street/postcode parameters; the address is one q= string.
 */
class PhotonGeocoder
{
    public static function countryName($countryCode)
    {
        $code = strtoupper(trim((string) $countryCode));
        $names = array(
            'DE' => 'Germany',
            'AT' => 'Austria',
            'CH' => 'Switzerland',
        );

        if (isset($names[$code])) {
            return $names[$code];
        }

        return $code;
    }

    public static function baseUrl(array $urls, $countryCode)
    {
        $code = strtoupper(trim((string) $countryCode));
        if ($code !== '' && isset($urls[$code]) && $urls[$code] !== '') {
            return rtrim($urls[$code], '/');
        }
        if (isset($urls['default']) && $urls['default'] !== '') {
            return rtrim($urls['default'], '/');
        }

        return null;
    }

    public static function query($street, $zip, $city, $countryCode)
    {
        $parts = array();
        foreach (array($street, $zip, $city, self::countryName($countryCode)) as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode(' ', $parts);
    }

    public static function endpoint($baseUrl, $street, $zip, $city, $countryCode)
    {
        $q = self::query($street, $zip, $city, $countryCode);

        return rtrim($baseUrl, '/') . '/api?limit=1&lang=de&q=' . rawurlencode($q);
    }

    /**
     * @param mixed $json
     * @return array|null lat, lng, street, postcode, city, country
     */
    public static function parse($json)
    {
        if (!is_array($json) || empty($json['features'][0]['geometry']['coordinates'])) {
            return null;
        }

        $coords = $json['features'][0]['geometry']['coordinates'];
        if (!isset($coords[0], $coords[1]) || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
            return null;
        }

        $props = array();
        if (isset($json['features'][0]['properties']) && is_array($json['features'][0]['properties'])) {
            $props = $json['features'][0]['properties'];
        }

        $street = '';
        if (!empty($props['street'])) {
            $street = (string) $props['street'];
            if (!empty($props['housenumber'])) {
                $street .= ' ' . $props['housenumber'];
            }
        }

        $city = '';
        if (!empty($props['city'])) {
            $city = (string) $props['city'];
        } elseif (!empty($props['name'])) {
            $city = (string) $props['name'];
        }

        return array(
            'lat' => (string) $coords[1],
            'lng' => (string) $coords[0],
            'street' => $street,
            'postcode' => isset($props['postcode']) ? (string) $props['postcode'] : '',
            'city' => $city,
            'country' => isset($props['country']) ? (string) $props['country'] : '',
        );
    }
}
