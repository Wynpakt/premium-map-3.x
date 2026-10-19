<?php

/**
 * Compares the Collmex street with the street Photon matched.
 * OSM and Google will still disagree; this only flags a different OSM street.
 */
class StreetCompare
{
    public static function normalize($street)
    {
        $street = trim((string) $street);
        if (function_exists('mb_strtolower')) {
            $street = mb_strtolower($street, 'UTF-8');
        } else {
            $street = strtolower($street);
        }
        $street = str_replace(
            array('straße', 'strasse', 'str.', 'str '),
            array('str ', 'str ', 'str ', 'str '),
            $street
        );
        $street = preg_replace('/[^a-z0-9]/', '', $street);

        return $street;
    }

    public static function differs($collmexStreet, $photonStreet)
    {
        $photonStreet = trim((string) $photonStreet);
        if ($photonStreet === '') {
            return false;
        }

        return self::normalize($collmexStreet) !== self::normalize($photonStreet);
    }
}
