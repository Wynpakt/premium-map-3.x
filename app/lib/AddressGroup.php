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

    /**
     * Collmex names win. Known ids keep a word when the name sync has not
     * run yet. Anything else stays the number.
     *
     * @param array<string, string> $names keyed by group id
     * @return array<int, string>
     */
    public static function labels($groups, array $names)
    {
        $known = array(
            '7' => 'Sprecher',
            '8' => 'Laden',
            '9' => 'Händler',
            self::FORMER => 'ehemalige Kunden',
            '20' => 'Cola',
            '21' => 'Bier',
            '29' => 'Frohlunder',
            '33' => 'Muntermate',
            self::HIDDEN => 'nicht auf der Karte',
            '112' => 'Webshop',
            self::PUBLIC_MAP => 'auf der öffentlichen Karte',
        );
        $out = array();
        foreach (explode(',', (string) $groups) as $id) {
            if ($id === '' || $id === '0') {
                continue;
            }
            if (isset($names[$id]) && $names[$id] !== '') {
                $out[] = $names[$id];
                continue;
            }
            $out[] = isset($known[$id]) ? $known[$id] : $id;
        }

        return $out;
    }
}
