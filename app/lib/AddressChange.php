<?php

/**
 * An address edit in Collmex must be geocoded again, but the current pin stays
 * until Photon returns a new coordinate.
 */
class AddressChange
{
    public static function addressDiffers($oldStreet, $oldZip, $oldCity, $newStreet, $newZip, $newCity)
    {
        return (string) $oldStreet !== (string) $newStreet
            || (string) $oldZip !== (string) $newZip
            || (string) $oldCity !== (string) $newCity;
    }

    /**
     * @return array lat, lng, geolocate_count, geocode_pending
     */
    public static function nextGeoState($changed, $lat, $lng, $count, $pending)
    {
        if (!$changed) {
            return array(
                'lat' => $lat,
                'lng' => $lng,
                'geolocate_count' => (int) $count,
                'geocode_pending' => (int) $pending,
            );
        }

        return array(
            'lat' => $lat,
            'lng' => $lng,
            'geolocate_count' => 0,
            'geocode_pending' => 1,
        );
    }
}
