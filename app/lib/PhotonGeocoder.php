<?php

/**
 * Query builder and GeoJSON parser for a local Photon 0.5 instance.
 *
 * Photon 0.5 does not index the postcode field, so the PLZ inside q= does not
 * filter. A same-named street in another district (Ritterstraße 9, 12207)
 * outranks the house-number range in the requested PLZ. Search inside a box
 * around the postcode anchor, then keep a hit only when its postcode matches.
 */
class PhotonGeocoder
{
    const LIMIT = 50;

    public static function bboxRadii()
    {
        return array(0.04, 0.12, 0.35);
    }

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

    public static function endpoint($baseUrl, $street, $zip, $city, $countryCode, $bbox = null)
    {
        $q = self::query($street, $zip, $city, $countryCode);
        $url = rtrim($baseUrl, '/') . '/api?limit=' . self::LIMIT . '&lang=de&q=' . rawurlencode($q);
        if ($bbox) {
            $url .= '&bbox=' . $bbox;
        }

        return $url;
    }

    public static function reverseEndpoint($baseUrl, $lat, $lng)
    {
        return rtrim($baseUrl, '/')
            . '/reverse?lang=de&lat=' . rawurlencode((string) $lat)
            . '&lon=' . rawurlencode((string) $lng);
    }

    public static function normalizePostcode($postcode)
    {
        return preg_replace('/\D/', '', (string) $postcode);
    }

    /**
     * Square around a postcode anchor, as minLon,minLat,maxLon,maxLat.
     */
    public static function bbox($lat, $lng, $radiusDeg)
    {
        $lat = (float) $lat;
        $lng = (float) $lng;
        $radiusDeg = (float) $radiusDeg;

        return self::coord($lng - $radiusDeg) . ','
            . self::coord($lat - $radiusDeg) . ','
            . self::coord($lng + $radiusDeg) . ','
            . self::coord($lat + $radiusDeg);
    }

    /**
     * The postcode area itself (name equals the PLZ) beats a house inside it.
     *
     * @param array $features
     * @return array|null
     */
    public static function anchor(array $features, $zip)
    {
        $zip = self::normalizePostcode($zip);
        if ($zip === '') {
            return null;
        }

        $fallback = null;
        foreach ($features as $feature) {
            if (self::normalizePostcode($feature['postcode']) !== $zip) {
                continue;
            }
            if (self::normalizePostcode($feature['name']) === $zip) {
                return $feature;
            }
            if ($fallback === null) {
                $fallback = $feature;
            }
        }

        return $fallback;
    }

    /**
     * With a PLZ, a hit in another postcode is not a match.
     * Without a PLZ (speakers), the first hit stands.
     *
     * @param array $features
     * @return array|null
     */
    public static function choose(array $features, $street, $zip)
    {
        if (count($features) === 0) {
            return null;
        }

        $zip = self::normalizePostcode($zip);
        if ($zip === '') {
            return $features[0];
        }

        $wantedNumber = self::houseNumber($street);
        $best = null;
        $bestScore = 0;
        foreach ($features as $feature) {
            if (self::normalizePostcode($feature['postcode']) !== $zip) {
                continue;
            }
            // A different street in the right PLZ is still the wrong place.
            if (!self::sameStreet($street, $feature['street'])) {
                continue;
            }
            $score = 3;
            if ($wantedNumber !== '' && self::houseNumberMatches($feature['housenumber'], $wantedNumber)) {
                $score = 4;
            }
            if ($score > $bestScore) {
                $best = $feature;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public static function meters($lat1, $lng1, $lat2, $lng2)
    {
        $earth = 6371000;
        $phi1 = deg2rad((float) $lat1);
        $phi2 = deg2rad((float) $lat2);
        $dPhi = deg2rad((float) $lat2 - (float) $lat1);
        $dLambda = deg2rad((float) $lng2 - (float) $lng1);
        $a = pow(sin($dPhi / 2), 2) + cos($phi1) * cos($phi2) * pow(sin($dLambda / 2), 2);

        return (int) round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * @param mixed $json
     * @return array|null lat, lng, street, postcode, city, country
     */
    public static function parse($json)
    {
        $features = self::parseAll($json);
        if (count($features) === 0) {
            return null;
        }

        return $features[0];
    }

    /**
     * @param mixed $json
     * @return array
     */
    public static function parseAll($json)
    {
        if (!is_array($json) || empty($json['features']) || !is_array($json['features'])) {
            return array();
        }

        $out = array();
        foreach ($json['features'] as $feature) {
            $parsed = self::parseFeature($feature);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }

        return $out;
    }

    /**
     * false means Photon did not answer, so the try must not be counted.
     * An array is every parsed hit. An empty array means Photon found nothing.
     *
     * @return array|false
     */
    public static function featuresFromHttp($body, $status)
    {
        if ($body === false || (int) $status === 429 || (int) $status >= 400) {
            return false;
        }

        return self::parseAll(json_decode((string) $body, true));
    }

    /**
     * false means Photon did not answer, so the try must not be counted.
     * null means Photon answered and found nothing.
     *
     * @return array|null|false
     */
    public static function fromHttp($body, $status)
    {
        $features = self::featuresFromHttp($body, $status);
        if ($features === false) {
            return false;
        }
        if (count($features) === 0) {
            return null;
        }

        return $features[0];
    }

    private static function parseFeature($feature)
    {
        if (!is_array($feature) || empty($feature['geometry']['coordinates']) || !is_array($feature['geometry']['coordinates'])) {
            return null;
        }

        $coords = $feature['geometry']['coordinates'];
        if (!isset($coords[0], $coords[1]) || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
            return null;
        }

        $props = array();
        if (isset($feature['properties']) && is_array($feature['properties'])) {
            $props = $feature['properties'];
        }

        $housenumber = !empty($props['housenumber']) ? (string) $props['housenumber'] : '';
        $street = !empty($props['street']) ? (string) $props['street'] : '';
        if ($street !== '' && $housenumber !== '') {
            $street .= ' ' . $housenumber;
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
            'housenumber' => $housenumber,
            'postcode' => isset($props['postcode']) ? (string) $props['postcode'] : '',
            'city' => $city,
            'country' => isset($props['country']) ? (string) $props['country'] : '',
            'name' => isset($props['name']) ? (string) $props['name'] : '',
        );
    }

    private static function sameStreet($collmexStreet, $photonStreet)
    {
        $photonStreet = trim((string) $photonStreet);
        if ($photonStreet === '') {
            return false;
        }

        return StreetCompare::normalize(self::withoutHouseNumber($collmexStreet))
            === StreetCompare::normalize(self::withoutHouseNumber($photonStreet));
    }

    private static function withoutHouseNumber($street)
    {
        $street = trim((string) $street);
        $stripped = preg_replace('/\s+\d+[a-zA-Z]?(?:\s*[-\/]\s*\d+[a-zA-Z]?)?\s*$/u', '', $street);
        if ($stripped === null || $stripped === '') {
            return $street;
        }

        return trim($stripped);
    }

    private static function houseNumber($street)
    {
        if (!preg_match_all('/\d+[a-zA-Z]?/u', (string) $street, $matches) || count($matches[0]) === 0) {
            return '';
        }

        return strtolower($matches[0][count($matches[0]) - 1]);
    }

    private static function houseNumberMatches($candidate, $wanted)
    {
        $candidate = strtolower(trim((string) $candidate));
        $wanted = strtolower(trim((string) $wanted));
        if ($candidate === '' || $wanted === '') {
            return false;
        }
        if ($candidate === $wanted) {
            return true;
        }
        if (!preg_match('/^(\d+)\s*[-\/]\s*(\d+)$/', $candidate, $range)) {
            return false;
        }
        if (!preg_match('/^(\d+)$/', $wanted, $number)) {
            return false;
        }
        $value = (int) $number[1];

        return $value >= (int) $range[1] && $value <= (int) $range[2];
    }

    private static function coord($value)
    {
        return number_format((float) $value, 5, '.', '');
    }
}
