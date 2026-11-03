<?php

/**
 * Collmex address-group ids the internal map can filter on.
 *
 * Potenziell, privat, Gastro and Großhändler have no number yet (LAND-15).
 * Until then, "aktuell" is every address that is not group 16.
 */
class AddressGroup
{
    const FORMER = '16';
    const PUBLIC_MAP = '124';
    const HIDDEN = '93';

    public static function contains($groups, $id)
    {
        foreach (explode(',', (string) $groups) as $part) {
            if ($part === (string) $id) {
                return true;
            }
        }

        return false;
    }
}
