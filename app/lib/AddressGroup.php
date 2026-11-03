<?php

/**
 * Collmex address-group ids the internal map can filter on.
 *
 * The names come from the address_group table. These three ids stay special
 * for status and visibility. "Aktuell" is every address that is not group 16.
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

    /**
     * Filter options from imported Collmex rows. Id 0 and a blank name are
     * not groups. The list is ordered by the German name.
     *
     * @param array<int, array> $rows
     * @return array<int, array{id: string, name: string}>
     */
    public static function options(array $rows)
    {
        $byId = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (string) $row['id'] : '';
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            if (!preg_match('/^[1-9][0-9]*$/', $id) || $name === '') {
                continue;
            }
            $byId[$id] = array(
                'id' => $id,
                'name' => $name,
            );
        }
        $options = array_values($byId);
        usort($options, function ($left, $right) {
            return strcmp(self::sortKey($left['name']), self::sortKey($right['name']));
        });

        return $options;
    }

    private static function sortKey($name)
    {
        $folded = strtr((string) $name, array(
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
            'Ä' => 'ae',
            'Ö' => 'oe',
            'Ü' => 'ue',
        ));

        return strtolower($folded);
    }
}
